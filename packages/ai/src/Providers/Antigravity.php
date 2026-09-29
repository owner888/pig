<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Antigravity\Routing;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\SseParser;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Google Antigravity: Gemini, Claude and GPT-OSS on a subscription, through Code Assist.
 *
 * **Adapted from `GoogleGeminiCli` rather than from the implementation this was ported from.**
 * Both speak `v1internal:streamGenerateContent?alt=sse` and both wrap a Gemini request in an
 * envelope, so the streaming, the SSE unwrap, the error shape and `GoogleShared` are all already
 * here and already tested. What the reference implementation had that this needed was the
 * envelope's *contents* — the labels, the request id shape, the model enum — and those are copied
 * field for field. Porting its hand-rolled SSE loop as well would have meant two of them.
 *
 * **The model on the wire is not the model on screen.** `Antigravity\Routing` turns the id
 * somebody chose plus the thinking level into a runtime id and the enum that names it; see that
 * class for why pig has no other provider shaped this way.
 *
 * **Three constants here are not what pig used to send**, and each was wrong in a way that fails
 * as a 4xx rather than as anything legible:
 *
 * - the host has no `sandbox` in it;
 * - the User-Agent is the Antigravity CLI's, and this deployment checks it;
 * - the envelope's `userAgent` field says `antigravity`, not `pig`. Upstream's changelog has that
 *   one as a fix for 429s specifically.
 */
final class Antigravity
{
    /** Where it answers. `daily-` is the live host despite the name; no `sandbox` in it. */
    public const string ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';

    /** Where to try when the first one will not have us. */
    public const string FALLBACK_ENDPOINT = 'https://cloudcode-pa.googleapis.com';

    /**
     * The CLI's own, which this deployment checks.
     *
     * A version and a platform that have nothing to do with the machine running pig — the point
     * is to be talking to Antigravity, and every field here is the reference implementation's
     * literal string.
     */
    private const string USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

    /** What a 403 or a 404 from the primary host means: try the other one. */
    private const array FALL_BACK_ON = [403, 404];

    /**
     * @param string|null $fallback where to try when the primary refuses us, or null to work it
     *        out — which means Google's second host when the model points at Google's first, and
     *        no fallback at all when a `models.json` points this provider somewhere of its own.
     *        **Injectable for the same reason `$http` is**: a test cannot point a model at
     *        Google's host and also have the request arrive at a canned server, so under a
     *        hardcoded pair the one branch that matters is the one no test can see.
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?string $fallback = null,
    ) {
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
            $response = $this->send($model, $context, $options, $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $data = json_decode($event->data, true);

                    // A chunk with no `response` in it is Code Assist saying nothing rather than
                    // saying something unreadable.
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

    /**
     * The request, and the second host if the first one refuses us.
     *
     * Only 403 and 404 fall through, and only once. A 429 is the quota answering and a 500 is the
     * deployment having a bad time — asking the other host the same question would get the same
     * answer, and `Session\Retry` one level up is what waits either of those out.
     */
    private function send(Model $model, Context $context, ?GoogleOptions $options, ?AbortSignal $signal): Response
    {
        $body = $this->encode($this->body($model, $context, $options));
        $token = self::credentials($options?->apiKey)[0];

        // A `models.json` pointing this provider somewhere of its own — a proxy, a local gateway
        // — is believed rather than silently second-guessed with Google's other host.
        $primary = $model->baseUrl !== '' ? $model->baseUrl : self::ENDPOINT;
        $second = $this->fallback ?? ($primary === self::ENDPOINT ? self::FALLBACK_ENDPOINT : null);
        $hosts = $second === null || $second === $primary ? [$primary] : [$primary, $second];

        $last = null;

        foreach ($hosts as $host) {
            $last = $this->http->send($this->request($host, $token, $model, $body), $signal);

            if ($last->isSuccessful() || !in_array($last->status, self::FALL_BACK_ON, true)) {
                return $last;
            }
        }

        // Unreachable with a non-empty host list, and `$hosts` always has one.
        return $last ?? throw new ProviderError('No endpoint to try.');
    }

    private function request(string $host, string $token, Model $model, string $body): Request
    {
        return new Request(
            'POST',
            rtrim($host, '/') . '/v1internal:streamGenerateContent?alt=sse',
            [
                'accept' => 'text/event-stream',
                'content-type' => 'application/json',
                'authorization' => "Bearer {$token}",
                'user-agent' => self::USER_AGENT,
                ...$model->headers,
            ],
            $body,
        );
    }

    /**
     * The envelope, with a Gemini request inside it.
     *
     * Every field is the reference implementation's, including the ones whose purpose is not
     * obvious from here: `requestType: agent` and the `used_claude` labels are what the
     * deployment reads to decide which quota a turn spends and which beta behaviour it gets.
     *
     * @return array<string, mixed>
     */
    private function body(Model $model, Context $context, ?GoogleOptions $options): array
    {
        [, $project] = self::credentials($options?->apiKey);
        // Both, so that the two cannot contradict each other: `thinkingEnabled: false` with a
        // level set means off, which is the reading every other provider here gives it.
        $level = ($options?->thinkingEnabled ?? false) ? $options?->thinkingLevel : null;
        [$runtime, $enum] = Routing::resolve($model->id, $level);

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

        // The budget comes from the routing tables and not from the caller: here a thinking
        // level already chose a different upstream model, and the budget is part of that choice
        // rather than a separate dial. `-1` is "as much as you like".
        $budget = Routing::thinkingBudget($runtime, $level);

        if ($model->reasoning && $budget !== 0) {
            $config['thinkingConfig'] = ['includeThoughts' => true, 'thinkingBudget' => $budget];
        }

        if ($config !== []) {
            $inner['generationConfig'] = $config;
        }

        $seed = self::seed($context);
        $trajectory = md5("antigravity:traj:{$seed}");
        $steps = count($inner['contents']);

        $inner['sessionId'] = (string) random_int(100_000_000_000, 999_999_999_999);
        $inner['labels'] = [
            'last_step_index' => (string) max(0, $steps - 1),
            'request_id' => $trajectory . '-' . self::turnsSoFar($context),
            'trajectory_id' => $trajectory,
            // Claude on this deployment wants a beta header's worth of behaviour, and these are
            // how it is asked for. `conservative` carries the same answer in the reference
            // implementation — both are sent, both the same.
            'used_claude' => self::isClaude($runtime) ? 'true' : 'false',
            'used_claude_conservative' => self::isClaude($runtime) ? 'true' : 'false',
            'used_non_gemini_model' => str_starts_with($runtime, 'gemini-') ? 'false' : 'true',
            'model_enum' => $enum,
        ];

        return [
            'project' => $project,
            'model' => $runtime,
            'request' => $inner,
            'requestType' => 'agent',
            'userAgent' => 'antigravity',
            'requestId' => sprintf(
                'agent/%s/%d/%s/%d',
                substr(md5("antigravity:agent:{$seed}"), 0, 16),
                Timestamp::nowMs(),
                $trajectory,
                max(1, $steps),
            ),
        ];
    }

    /**
     * Something stable to derive this conversation's ids from.
     *
     * The first 64 **bytes** of the first message, which is the reference implementation's and is
     * deliberately not widened to characters: the only thing asked of this is that the same
     * conversation produces the same trajectory id every turn, and it goes straight into `md5()`.
     * A cut that lands inside a character is a different byte string, not a broken one — and
     * changing how it is cut would change every id, which is what the deployment caches against.
     */
    private static function seed(Context $context): string
    {
        $text = '';

        foreach ($context->messages[0]->content ?? [] as $part) {
            if ($part instanceof TextContent) {
                $text = $part->text;

                break;
            }
        }

        // An opening turn that is only a picture has no text to key on. A constant is right
        // rather than something random: every turn of *this* conversation then agrees with
        // itself, which is the only property asked of the seed.
        return $text === '' ? 'conv' : substr($text, 0, 64);
    }

    /** How many turns the assistant has already taken, which is the reference's `requestIndex`. */
    private static function turnsSoFar(Context $context): int
    {
        return count(array_filter(
            $context->messages,
            static fn (mixed $message): bool => $message instanceof AssistantMessage,
        ));
    }

    private static function isClaude(string $runtime): bool
    {
        return str_starts_with($runtime, 'claude-');
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
            throw new ProviderError(
                'Antigravity needs a token and a Cloud project together, which is what signing in '
                . 'stores. An ordinary Gemini API key cannot be used with antigravity models.',
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

    /**
     * What went wrong, in the words the deployment used.
     *
     * A quota refusal is named as one, because "RESOURCE_EXHAUSTED" in a wall of JSON is the one
     * failure people need to recognise at a glance — and `Session\Retry` reads the delay out of
     * the same body, so the wait is as long as was asked for.
     */
    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
        $said = is_string($message) ? $message : trim($body);

        $quota = $status === 429
            || stripos($said, 'RESOURCE_EXHAUSTED') !== false
            || stripos($said, 'quota') !== false;

        return $quota
            ? "antigravity is out of quota ({$status}): {$said}"
            : "antigravity returned {$status}: {$said}";
    }
}
