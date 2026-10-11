<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use InvalidArgumentException;
use Pig\Ai\AssistantImages;
use Pig\Ai\Cost;
use Pig\Ai\Extension\ImageGenerationApi;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Pricing;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use RuntimeException;
use Throwable;

/**
 * Image generation via Antigravity's Gemini image models — the provider's `images` implementation
 * (upstream's `ProviderImages`), registered on the `antigravity` provider as its
 * `ImageGenerationApi`, so `Models::generateImages()` (codemode's `$models->generateImages()`) reaches
 * it like any other image model. The `generate_image` tool and `/antigravity.image` go through
 * `Models::generateImages()` too and then save what came back (`save()`): one request path.
 *
 * What it sends is what pi-antigravity's image command sends: one `streamGenerateContent` envelope
 * per attempt, the prompt (and any reference images) as the user turn, an `imageConfig` with the
 * aspect ratio, tried on each of `QuotaClient::ENDPOINTS` and then on the next model of
 * `MODEL_FALLBACKS` after a 404. The model that answered is the result's `model`, so a fallback is
 * never silent. A quota wall on every attempt asks `$failover` for the next stored account, as the
 * chat protocol does (`AntigravityApi::send()`).
 */
final class ImageGenerator implements ImageGenerationApi
{
    public const string DEFAULT_MODEL = 'gemini-3.1-flash-image';

    /**
     * The image models this provider sells, as the extension knows them: id => name. Subscription
     * models like the chat ones (`Models::fallback()`), so they cost nothing per token; they take a
     * prompt and reference images and answer with images and text, as Gemini's image models do.
     */
    public const array MODELS = [
        'gemini-3.1-flash-image' => 'Gemini 3.1 Flash Image (Antigravity)',
        'gemini-3-pro-image' => 'Gemini 3 Pro Image (Antigravity)',
        'gemini-3-pro-image-preview' => 'Gemini 3 Pro Image Preview (Antigravity)',
    ];

    public const array MODEL_FALLBACKS = [
        'gemini-3.1-flash-image',
        'gemini-3-pro-image',
        'gemini-3-pro-image-preview',
    ];

    public const array ASPECT_RATIOS = [
        '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9',
    ];

    public const string DEFAULT_IMAGE_DIR = '.pig/generated-images';

    private const string USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

    private const string SYSTEM_INSTRUCTION =
        'You are an AI image generator. Generate images based on user descriptions. Focus on creating high-quality, visually appealing images that match the user\'s request.';

    /**
     * @param string|null $endpoint the one host to try in place of `QuotaClient::ENDPOINTS` — for tests
     * @param (Closure(list<string> $triedAccessTokens): ?string)|null $failover the next stored account's
     *        api key after a quota wall, or null when none is left — `AntigravityApi`'s `$failover`
     */
    public function __construct(
        private readonly ?HttpClient $http = null,
        private readonly ?string $endpoint = null,
        private readonly ?Closure $failover = null,
    ) {
    }

    /** @return list<ImageModel> `MODELS` as the provider's image models */
    public static function models(): array
    {
        return array_map(self::model(...), array_keys(self::MODELS));
    }

    /** One image model of this provider — a registered one, or one the tool was asked for by id. */
    public static function model(string $id): ImageModel
    {
        return new ImageModel(
            $id,
            self::MODELS[$id] ?? $id,
            ImageApi::Extension,
            Models::PROVIDER,
            AntigravityApi::ENDPOINT,
            ['text', 'image'],
            ['image', 'text'],
            new Pricing(),
            inputLimits: \Pig\Ai\Models::inputLimits(Models::PROVIDER, ['text', 'image'], 0),
        );
    }

    /**
     * `ImageGenerationApi`: the prompt is the context's text, any image in it a reference; the aspect
     * ratio is `$options->metadata['aspectRatio']` (default `1:1`); the key is the signed-in account's
     * `{"token", "projectId"}`. Never throws — every failure is an error result.
     */
    #[\Override]
    public function generateImages(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages
    {
        $timestamp = Timestamp::nowMs();

        try {
            [$token, $project] = self::credentials($options->apiKey);
            $prompt = trim(implode("\n", array_map(
                static fn (TextContent $text): string => $text->text,
                array_values(array_filter($context->input, static fn (mixed $item): bool => $item instanceof TextContent)),
            )));

            if ($prompt === '') {
                throw new InvalidArgumentException('Prompt cannot be empty.');
            }

            if (strlen($prompt) > 8000) {
                throw new InvalidArgumentException('Prompt exceeds maximum length of 8000 characters.');
            }

            $ratio = $options->metadata['aspectRatio'] ?? '1:1';
            $ratio = is_string($ratio) ? trim($ratio) : '';

            if (!in_array($ratio, self::ASPECT_RATIOS, true)) {
                throw new InvalidArgumentException(
                    "Unsupported aspect ratio: '{$ratio}'. Use one of " . implode(', ', self::ASPECT_RATIOS) . '.',
                );
            }

            $references = array_values(array_filter($context->input, static fn (mixed $item): bool => $item instanceof ImageContent));
            [$images, $texts, $usedModel, $rawUsage] = $this->request($token, $project, $model->id, $prompt, $references, $ratio, $options);

            $output = [];
            $notes = trim(implode('', $texts));

            if ($notes !== '') {
                $output[] = new TextContent($notes);
            }

            foreach ($images as $image) {
                $output[] = new ImageContent($image['data'], $image['mimeType']);
            }

            return new AssistantImages(
                $model->api,
                $model->provider,
                $usedModel,
                $output,
                StopReason::Stop,
                $timestamp,
                usage: $rawUsage === null ? null : self::priced(GoogleShared::usage($rawUsage), $model->pricing),
            );
        } catch (Throwable $error) {
            return new AssistantImages(
                $model->api,
                $model->provider,
                $model->id,
                [],
                ($options->signal?->aborted() ?? false) ? StopReason::Aborted : StopReason::Error,
                $timestamp,
                errorMessage: AntigravityApi::redactSecrets($error->getMessage()),
            );
        }
    }

    /**
     * The request loop: the model asked for, then `MODEL_FALLBACKS` after a 404; each on every
     * endpoint until one answers with images; and after a quota wall, the next account.
     *
     * @param list<ImageContent> $references
     * @return array{0: list<array{data: string, mimeType: string}>, 1: list<string>, 2: string, 3: array<string, mixed>|null}
     *         the images, the text, the model that answered, Gemini's `usageMetadata`
     */
    private function request(string $token, string $project, string $model, string $prompt, array $references, string $ratio, ImagesOptions $options): array
    {
        $modelsToTry = array_values(array_unique([$model, ...self::MODEL_FALLBACKS]));
        $candidates = $this->endpoint !== null ? [$this->endpoint] : QuotaClient::ENDPOINTS;
        $http = $this->http ?? new HttpClient(60.0);
        $parts = [['text' => $prompt]];

        foreach ($references as $reference) {
            $parts[] = ['inlineData' => ['mimeType' => $reference->mimeType, 'data' => $reference->data]];
        }

        $tried = [$token];

        while (true) {
            $lastError = 'No endpoint available';
            $quotaWall = false;

            foreach ($modelsToTry as $currentModel) {
                $body = [
                    'project' => $project,
                    'model' => $currentModel,
                    'request' => [
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => [
                            'imageConfig' => ['aspectRatio' => $ratio],
                            'candidateCount' => 1,
                        ],
                    ],
                    'requestType' => 'image_gen',
                    'userAgent' => 'antigravity',
                    'requestId' => sprintf('image_gen/%d/%s/1', (int) (microtime(true) * 1000), bin2hex(random_bytes(16))),
                ];

                if ($options->onPayload !== null) {
                    $body = ($options->onPayload)($body, self::model($currentModel)) ?? $body;
                }

                foreach ($candidates as $endpoint) {
                    $directUrl = rtrim($endpoint, '/') . '/v1internal:generateContent';
                    $headers = [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type' => 'application/json',
                        'User-Agent' => self::USER_AGENT,
                    ];

                    try {
                        $response = $http->send(new Request('POST', $directUrl, $headers, json_encode($body) ?: '{}'), $options->signal);

                        if ($response->status === 404) {
                            $streamUrl = rtrim($endpoint, '/') . '/v1internal:streamGenerateContent?alt=sse';
                            $streamHeaders = [...$headers, 'Accept' => 'text/event-stream'];
                            $response = $http->send(new Request('POST', $streamUrl, $streamHeaders, json_encode($body) ?: '{}'), $options->signal);
                        }

                        if ($options->onResponse !== null) {
                            ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], self::model($currentModel));
                        }

                        if (!$response->isSuccessful()) {
                            $errText = $response->body->all();
                            $lastError = "Image generation failed ({$response->status}): " . substr($errText, 0, 300);
                            $quotaWall = $response->status === 429 && AntigravityApi::isQuotaWall($errText);

                            if ($response->status === 404 || $quotaWall) {
                                // A 404 is the next model's to try; a quota wall every endpoint answers alike.
                                break;
                            }

                            continue;
                        }

                        $quotaWall = false;
                        [$images, $texts, $usage] = self::parseResponse($response->body->all());

                        if ($images !== []) {
                            return [$images, $texts, $currentModel, $usage];
                        }

                        $lastError = 'The response had no image in it' . ($texts !== [] ? ': ' . substr(trim(implode('', $texts)), 0, 300) : '.');
                    } catch (Throwable $e) {
                        if ($options->signal?->aborted() ?? false) {
                            throw $e;
                        }

                        $lastError = $e->getMessage();
                    }
                }

                if ($quotaWall) {
                    break;
                }
            }

            $next = $quotaWall && $this->failover !== null ? ($this->failover)($tried) : null;

            if ($next === null) {
                throw new RuntimeException("Image generation failed: {$lastError}");
            }

            [$token, $project] = self::credentials($next);
            $tried[] = $token;
        }
    }

    /**
     * The token and the project, out of the one string an api key carries — what
     * `AntigravityApi::credentials()` reads, refused in its words.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentials(?string $apiKey): array
    {
        $decoded = $apiKey === null || $apiKey === '' ? null : json_decode($apiKey, true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_string($project) || $project === '') {
            throw new ProviderError(
                'Antigravity needs a token and a Cloud project together, which is what signing in '
                . 'stores. An ordinary Gemini API key cannot be used with antigravity models.',
            );
        }

        return [$token, $project];
    }

    /** The counts at the model's four rates per million, as `OpenRouterImages::parseUsage()` prices them. */
    private static function priced(Usage $usage, Pricing $pricing): Usage
    {
        $cost = [
            $pricing->input / 1_000_000 * $usage->input,
            $pricing->output / 1_000_000 * $usage->output,
            $pricing->cacheRead / 1_000_000 * $usage->cacheRead,
            $pricing->cacheWrite / 1_000_000 * $usage->cacheWrite,
        ];

        return new Usage($usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite, $usage->totalTokens, new Cost(...[...$cost, array_sum($cost)]), $usage->reasoning);
    }

    /**
     * @return array{0: list<array{data: string, mimeType: string}>, 1: list<string>, 2: array<string, mixed>|null}
     */
    private static function parseResponse(string $raw): array
    {
        $images = [];
        $texts = [];
        $usage = null;

        // Response may be SSE (data: {...}) or raw JSON
        $lines = explode("\n", $raw);
        $payloads = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'data:')) {
                $json = trim(substr($trimmed, 5));
                if ($json !== '' && $json !== '[DONE]') {
                    $payloads[] = $json;
                }
            }
        }

        if ($payloads === []) {
            $payloads[] = $raw;
        }

        foreach ($payloads as $p) {
            $decoded = json_decode($p, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                throw new RuntimeException($decoded['error']['message']);
            }

            // The last chunk's metadata is the request's whole count, as Gemini streams it.
            $metadata = $decoded['response']['usageMetadata'] ?? $decoded['usageMetadata'] ?? null;

            if (is_array($metadata)) {
                $usage = $metadata;
            }

            $candidates = $decoded['response']['candidates'] ?? $decoded['candidates'] ?? [];
            if (!is_array($candidates)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $parts = $candidate['content']['parts'] ?? [];
                if (!is_array($parts)) {
                    continue;
                }

                foreach ($parts as $part) {
                    if (!is_array($part)) {
                        continue;
                    }

                    if (isset($part['text']) && is_string($part['text'])) {
                        $texts[] = $part['text'];
                    }

                    if (isset($part['inlineData']['data']) && is_string($part['inlineData']['data'])) {
                        $images[] = [
                            'data' => $part['inlineData']['data'],
                            'mimeType' => (string) ($part['inlineData']['mimeType'] ?? 'image/png'),
                        ];
                    }
                }
            }
        }

        return [$images, $texts, $usage];
    }

    /**
     * Write a result's images to disk — what the `generate_image` tool and `/antigravity.image` do with
     * `Models::generateImages()`'s answer: under `DEFAULT_IMAGE_DIR` unless a file or directory is asked
     * for, numbered when there are several.
     *
     * @return list<string> the paths written
     * @throws RuntimeException when an image cannot be written
     */
    public static function save(AssistantImages $result, ?string $targetPath, string $cwd): array
    {
        $images = array_values(array_filter($result->output, static fn (mixed $block): bool => $block instanceof ImageContent));
        $root = realpath($cwd) ?: $cwd;
        $saved = [];

        foreach ($images as $index => $image) {
            $data = base64_decode($image->data, true);

            if ($data === false) {
                throw new RuntimeException("Image " . ($index + 1) . ' of the answer is not valid base64.');
            }

            $file = self::resolveSavePath($root, $targetPath, $image->mimeType, count($images) > 1 ? $index : null);
            $dir = dirname($file);

            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create {$dir} for the image.");
            }

            if (file_put_contents($file, $data) === false) {
                throw new RuntimeException("Cannot write the image to {$file}.");
            }

            $saved[] = $file;
        }

        return $saved;
    }

    public static function resolveSavePath(string $root, ?string $requested, string $mimeType, ?int $index = null): string
    {
        $ext = match (true) {
            str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg') => 'jpg',
            str_contains($mimeType, 'webp') => 'webp',
            str_contains($mimeType, 'gif') => 'gif',
            default => 'png',
        };

        $stamp = date('Y-m-d\TH-i-s');
        $suffix = $index !== null ? '-' . ($index + 1) : '';
        $defaultFilename = "image-{$stamp}{$suffix}.{$ext}";

        if ($requested === null || trim($requested) === '') {
            return rtrim($root, '/') . '/' . self::DEFAULT_IMAGE_DIR . '/' . $defaultFilename;
        }

        $trimmed = trim($requested);
        $path = str_starts_with($trimmed, '/') ? $trimmed : rtrim($root, '/') . '/' . $trimmed;

        if (is_dir($path) || str_ends_with($trimmed, '/')) {
            return rtrim($path, '/') . '/' . $defaultFilename;
        }

        return $path;
    }
}
