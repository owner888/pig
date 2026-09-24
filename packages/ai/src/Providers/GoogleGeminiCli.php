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
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Throwable;

/**
 * Google Cloud Code Assist — Gemini through a subscription rather than an API key.
 *
 * Upstream's `providers/google-gemini-cli.ts`, and the **fifth** protocol. CLAUDE.md used to call
 * it "the same Gemini shape behind a sign-in", which was wrong and is recorded as wrong: it has
 * its own endpoint and its own envelope. What it is *not* is a different conversation — the thing
 * inside the envelope is a Gemini request and what comes back is a Gemini chunk, which is why
 * almost all of this file's work happens in `GoogleShared`.
 *
 * Three differences from `Google`, and they are the whole file:
 *
 * - **The request is wrapped.** `{project, model, request: {…the Gemini request…}, userAgent,
 *   requestId}`, posted to `v1internal:streamGenerateContent?alt=sse`. The project is what the
 *   subscription is billed against and the token is useless without it.
 * - **The answer is wrapped too**, one key deeper: `{response: {candidates: […]}}`. So this
 *   unwraps and hands `GoogleShared::onChunk()` the same thing `Google` hands it.
 * - **The key is two things in one string.** `Provider::apiKey()` encodes `{token, projectId}` as
 *   JSON, because an api key field carries one string and Code Assist needs both. This is where
 *   it is taken apart, and a key that is not that shape is refused by name — `/login` is what
 *   produces one, and "your key is the wrong kind" is the only useful thing to say about an
 *   ordinary Gemini key arriving here.
 *
 * **Upstream's in-provider retry is not ported.** It wraps the request in three attempts with
 * exponential backoff and honours a server-suggested `retryDelay`, because Code Assist's free
 * tier rate-limits hard. pig already waits out a 429 in `Session\Retry`, one level up — and that
 * one can be turned off with `retry.enabled`, counts down on screen, and stops when escape is
 * pressed. Two retry mechanisms means neither is the one somebody is looking at, so this keeps
 * the one that can be seen and interrupted. The developer's call; if Code Assist turns out to
 * need a tighter loop than a session-level wait, it belongs here and this note is where to start.
 */
final class GoogleGeminiCli
{
    /** Where Code Assist answers. Antigravity's sandbox endpoint is not ported. */
    public const string ENDPOINT = 'https://cloudcode-pa.googleapis.com';

    /**
     * Who Code Assist is told it is talking to, copied from upstream.
     *
     * `Client-Metadata` is JSON inside a header, which is Google's doing and not a mistake here.
     */
    private const array HEADERS = [
        'user-agent' => 'google-cloud-sdk vscode_cloudshelleditor/0.1',
        'x-goog-api-client' => 'gl-node/22.17.0',
        'client-metadata' => '{"ideType":"IDE_UNSPECIFIED","platform":"PLATFORM_UNSPECIFIED","pluginType":"GEMINI"}',
    ];

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

                    // The unwrap, and the only thing this loop does differently. A chunk with no
                    // `response` in it is Code Assist saying nothing rather than saying something
                    // unreadable — upstream skips it too.
                    if (is_array($data) && is_array($data['response'] ?? null)) {
                        $open = GoogleShared::onChunk($data['response'], $builder, $stream, $open);
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

    private function request(Model $model, Context $context, ?GoogleOptions $options): Request
    {
        [$token, $project] = self::credentials($options?->apiKey);

        $headers = [
            'accept' => 'text/event-stream',
            'content-type' => 'application/json',
            'authorization' => "Bearer {$token}",
            ...self::HEADERS,
            ...$model->headers,
        ];

        // `alt=sse` is what turns this from one enormous JSON array into a stream, the same as
        // for the public endpoint.
        $url = rtrim($model->baseUrl, '/') . '/v1internal:streamGenerateContent?alt=sse';

        return new Request('POST', $url, $headers, $this->encode($this->body($model, $context, $options, $project)));
    }

    /**
     * The envelope, with a Gemini request inside it.
     *
     * @return array<string, mixed>
     */
    private function body(Model $model, Context $context, ?GoogleOptions $options, string $project): array
    {
        $inner = ['contents' => GoogleShared::contents($model, $context)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $inner['systemInstruction'] = GoogleShared::systemInstruction($context->systemPrompt);
        }

        if ($context->tools !== []) {
            $inner['tools'] = GoogleShared::tools($context->tools);

            if ($options?->toolChoice !== null) {
                $inner['toolConfig'] = GoogleShared::toolConfig($options->toolChoice);
            }
        }

        // Only when it was asked for, which is the one place this differs from the public
        // endpoint's body: there, a turn that wants no thinking has to say `thinkingBudget: 0`,
        // and Code Assist rejects a `thinkingConfig` on a model that cannot think.
        if ($model->reasoning && ($options?->thinkingEnabled ?? false)) {
            $thinking = ['includeThoughts' => true];

            if ($options->thinkingLevel !== null) {
                $thinking['thinkingLevel'] = $options->thinkingLevel;
            } elseif ($options->thinkingBudget !== null) {
                $thinking['thinkingBudget'] = $options->thinkingBudget;
            }

            $config['thinkingConfig'] = $thinking;
        }

        if ($config !== []) {
            $inner['generationConfig'] = $config;
        }

        return [
            'project' => $project,
            'model' => $model->id,
            'request' => $inner,
            'userAgent' => 'pig',
            // Upstream's shape — time plus something random — and it is what Google's own logs
            // are searched by when a request has to be traced.
            'requestId' => 'pig-' . Timestamp::nowMs() . '-' . bin2hex(random_bytes(4)),
        ];
    }

    /**
     * The token and the project, out of the one string an api key field can carry.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentials(?string $apiKey): array
    {
        $decoded = $apiKey === null || $apiKey === '' ? null : json_decode($apiKey, true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_string($project) || $project === '') {
            // Named rather than sent and refused: an ordinary Gemini API key arriving here is
            // the likely mistake, and "the wrong kind of key" is the only useful thing to say
            // about it. `/login` is what produces the right one.
            throw new ProviderError(
                'Code Assist needs a token and a Cloud project together, which is what /login stores. '
                . 'An ordinary Gemini API key cannot be used with google-gemini-cli models.',
            );
        }

        return [$token, $project];
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

    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return "google-gemini-cli returned {$status}: " . (is_string($message) ? $message : trim($body));
    }
}
