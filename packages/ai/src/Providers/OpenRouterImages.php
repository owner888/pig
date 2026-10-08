<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantImages;
use Pig\Ai\Cost;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\Utf8;
use stdClass;
use Throwable;

/**
 * Upstream's `api/openrouter-images.ts`: "Image generation over OpenRouter's chat completions
 * endpoint" — one non-streaming `POST <baseUrl>/chat/completions` through the `openai` SDK, with
 * the prompt as the user message's parts and `modalities: ["image"]` (and `"text"` for a model whose
 * `output` has it); the answer's text and its `data:` image URLs become the output.
 *
 * The SDK's part is `SdkRequest::send()` and `OpenAiCompletions::sdkHeaders()`, as the completions
 * provider's: the Stainless headers, `Authorization: Bearer <key>`, the timeout around the wait for
 * the response, `Connection error.` / `Request timed out.`, and a refusal as the SDK's `APIError`.
 * The usage is priced at the model's four rates, without tiers, as upstream's own `parseUsage()`
 * prices it.
 */
final class OpenRouterImages
{
    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    public function generateImages(ImageModel $model, ImagesContext $context, ?ImagesOptions $options = null): AssistantImages
    {
        $timestamp = Timestamp::nowMs();

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $params = self::buildParams($model, $context);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = $nextParams;
            }

            $timeoutMs = $options?->timeoutMs ?? SdkHeaders::STAINLESS_DEFAULT_TIMEOUT_MS;
            // `createClient()`: `defaultHeaders: providerHeadersToRecord({...model.headers, ...optionsHeaders})`.
            $defaultHeaders = Headers::providerHeadersToRecord($model->headers, $options?->headers) ?? [];
            $request = new Request(
                'POST',
                rtrim($model->baseUrl, '/') . '/chat/completions',
                OpenAiCompletions::sdkHeaders($apiKey, $defaultHeaders, $timeoutMs),
                SystemOneShared::encode($params),
            );
            $signal = $options?->signal;
            $response = ProviderRetry::retryProviderRequest(
                fn (): Response => SdkRequest::send($this->http, $request, $signal, $timeoutMs, static function (int $status, string $body): array {
                    $norm = ErrorBody::openAiApiError($status, $body);

                    return [$norm['message'], ErrorBody::format($norm)];
                }),
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            $imageResponse = JsJson::parse($response->body->all(), false);
            $output = [];
            $responseId = $imageResponse instanceof stdClass && is_string($imageResponse->id ?? null) ? $imageResponse->id : null;
            $rawUsage = $imageResponse instanceof stdClass ? ($imageResponse->usage ?? null) : null;
            $usage = $rawUsage instanceof stdClass ? self::parseUsage($rawUsage, $model) : null;
            $choice = $imageResponse instanceof stdClass && is_array($imageResponse->choices ?? null) ? ($imageResponse->choices[0] ?? null) : null;

            if ($choice instanceof stdClass && ($choice->message ?? null) instanceof stdClass) {
                $content = $choice->message->content ?? null;

                if (is_string($content) && $content !== '') {
                    $output[] = new TextContent($content);
                }

                foreach (is_array($choice->message->images ?? null) ? $choice->message->images : [] as $image) {
                    $imageUrl = $image instanceof stdClass
                        ? (is_string($image->image_url ?? null) ? $image->image_url : (($image->image_url ?? null) instanceof stdClass ? ($image->image_url->url ?? null) : null))
                        : null;

                    if (!is_string($imageUrl) || !str_starts_with($imageUrl, 'data:')) {
                        continue;
                    }

                    if (preg_match('/^data:([^;]+);base64,(.+)$/', $imageUrl, $matches) !== 1) {
                        continue;
                    }

                    $output[] = new ImageContent($matches[2], $matches[1]);
                }
            }

            return new AssistantImages($model->api, $model->provider, $model->id, $output, StopReason::Stop, $timestamp, $responseId, $usage);
        } catch (Throwable $error) {
            return new AssistantImages(
                $model->api,
                $model->provider,
                $model->id,
                [],
                ($options?->signal?->aborted() ?? false) ? StopReason::Aborted : StopReason::Error,
                $timestamp,
                errorMessage: SdkRequest::errorMessage($error),
            );
        }
    }

    /** @return array<string, mixed> */
    private static function buildParams(ImageModel $model, ImagesContext $context): array
    {
        $content = array_map(static fn (TextContent|ImageContent $item): array => $item instanceof TextContent
            ? ['type' => 'text', 'text' => Utf8::sanitize($item->text)]
            : ['type' => 'image_url', 'image_url' => ['url' => "data:{$item->mimeType};base64,{$item->data}"]], $context->input);

        return [
            'model' => $model->id,
            'messages' => [['role' => 'user', 'content' => $content]],
            'stream' => false,
            'modalities' => in_array('text', $model->output, true) ? ['image', 'text'] : ['image'],
        ];
    }

    /**
     * Upstream's `parseUsage()`: the cached prompt tokens less the cache writes as reads, the rest of
     * the prompt as input, each priced at the model's rate per million.
     */
    private static function parseUsage(stdClass $rawUsage, ImageModel $model): Usage
    {
        $number = static fn (mixed $value): int => (is_int($value) || is_float($value)) && $value ? (int) $value : 0;
        $details = ($rawUsage->prompt_tokens_details ?? null) instanceof stdClass ? $rawUsage->prompt_tokens_details : new stdClass();
        $promptTokens = $number($rawUsage->prompt_tokens ?? null);
        $reportedCachedTokens = $number($details->cached_tokens ?? null);
        $cacheWriteTokens = $number($details->cache_write_tokens ?? null);
        $cacheReadTokens = $cacheWriteTokens > 0 ? max(0, $reportedCachedTokens - $cacheWriteTokens) : $reportedCachedTokens;
        $input = max(0, $promptTokens - $cacheReadTokens - $cacheWriteTokens);
        $output = $number($rawUsage->completion_tokens ?? null);
        $cost = new Cost(
            $model->pricing->input / 1_000_000 * $input,
            $model->pricing->output / 1_000_000 * $output,
            $model->pricing->cacheRead / 1_000_000 * $cacheReadTokens,
            $model->pricing->cacheWrite / 1_000_000 * $cacheWriteTokens,
        );

        return new Usage(
            $input,
            $output,
            $cacheReadTokens,
            $cacheWriteTokens,
            $input + $output + $cacheReadTokens + $cacheWriteTokens,
            new Cost($cost->input, $cost->output, $cost->cacheRead, $cost->cacheWrite, $cost->input + $cost->output + $cost->cacheRead + $cost->cacheWrite),
        );
    }
}
