<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\SseParser;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Throwable;

/**
 * Google's Generative Language API — Gemini, streamed.
 *
 * The fourth shape, and the one furthest from the other three. A chunk carries a list of
 * *parts*, and a part is text, or thinking (text with `thought: true` on it), or a whole
 * function call. So:
 *
 * - **A tool call arrives complete**, arguments and all, in one part. It is opened,
 *   delivered and closed in the same breath, because there is nothing to stream.
 * - **Thinking and text are the same field**, told apart by a flag, so a block ends when
 *   that flag changes — the same boundary problem as `openai-completions`, one field over.
 * - **Tool results are addressed by name as well as id**, and consecutive ones are merged
 *   into a single user turn, which is what the API wants.
 *
 * Upstream hands this to `@google/genai`. There is none here, so the REST endpoint is
 * called directly: `:streamGenerateContent?alt=sse`, which is what that package does
 * underneath.
 *
 * Ported from upstream's `providers/google.ts`. What a Gemini request and a Gemini chunk look
 * like lives in `GoogleShared`, because Code Assist sends the same request and answers with the
 * same chunk one key deeper — see that file. What is here is this endpoint's own business: where
 * it sends, how it authenticates, how it words a failure, and the body it assembles around those
 * shared pieces.
 */
final class Google
{
    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, Context $context, ?GoogleOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(
        AssistantMessageEventStream $stream,
        Model $model,
        Context $context,
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;
        $open = null;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $data = json_decode($event->data, true);

                    if (is_array($data)) {
                        $open = GoogleShared::onChunk($data, $builder, $stream, $open);
                    }
                }
            }

            GoogleShared::close($builder, $stream, $open);
            $signal?->throwIfAborted();

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result.
            $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return "google returned {$status}: " . (is_string($message) ? $message : trim($body));
    }

    // ---- the request ---------------------------------------------------------------------

    private function request(Model $model, Context $context, ?GoogleOptions $options): Request
    {
        $headers = [
            'accept' => 'text/event-stream',
            'content-type' => 'application/json',
            'x-goog-api-key' => $options?->apiKey ?? '',
            ...$model->headers,
        ];

        // `alt=sse` is what turns this from one enormous JSON array into a stream.
        $url = rtrim($model->baseUrl, '/') . '/models/' . rawurlencode($model->id) . ':streamGenerateContent?alt=sse';

        return new Request('POST', $url, $headers, $this->encode($this->body($model, $context, $options)));
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function body(Model $model, Context $context, ?GoogleOptions $options): array
    {
        $body = ['contents' => GoogleShared::contents($model, $context)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $body['systemInstruction'] = GoogleShared::systemInstruction($context->systemPrompt);
        }

        if ($context->tools !== []) {
            $body['tools'] = GoogleShared::tools($context->tools);

            if ($options?->toolChoice !== null) {
                $body['toolConfig'] = GoogleShared::toolConfig($options->toolChoice);
            }
        }

        if ($model->reasoning) {
            $config['thinkingConfig'] = $this->thinking($options);
        }

        if ($config !== []) {
            $body['generationConfig'] = $config;
        }

        return $body;
    }

    /**
     * What to tell Gemini about thinking.
     *
     * Saying nothing is not the same as saying no: Gemini thinks by default, so a turn
     * that did not ask for it has to ask for none. Gemini 3 takes a level and ignores a
     * budget; 2.5 takes a budget. `Stream` works out which; this sends whichever arrived.
     *
     * @return array<string, mixed>
     */
    private function thinking(?GoogleOptions $options): array
    {
        if ($options === null || !$options->thinkingEnabled) {
            return ['thinkingBudget' => 0];
        }

        $config = ['includeThoughts' => true];

        if ($options->thinkingLevel !== null) {
            $config['thinkingLevel'] = $options->thinkingLevel;
        } elseif ($options->thinkingBudget !== null) {
            $config['thinkingBudget'] = $options->thinkingBudget;
        }

        return $config;
    }

}
