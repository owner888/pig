<?php

declare(strict_types=1);

namespace PigAntigravity;


use Pig\Ai\AssistantMessage;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StreamOptions;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\SseParser;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Google Antigravity: Gemini, Claude and GPT-OSS on a subscription, through Code Assist.
 *
 * **Adapted from pig's own Gemini CLI provider rather than from the implementation this was
 * ported from** — that provider has since been removed, the way upstream removed its own.
 * Both speak `v1internal:streamGenerateContent?alt=sse` and both wrap a Gemini request in an
 * envelope, so the streaming, the SSE unwrap, the error shape and `GoogleShared` are all already
 * here and already tested. What the reference implementation had that this needed was the
 * envelope's *contents* — the labels, the request id shape, the model enum — and those are copied
 * field for field. Porting its hand-rolled SSE loop as well would have meant two of them.
 *
 * **The model on the wire is not the model on screen.** `Routing` turns the id
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
final class AntigravityApi implements StreamApi
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

    /**
     * The statuses after which the next endpoint is tried — pi-antigravity's `endpointCandidates()`
     * loop: `if (![403, 404, 429, 500, 502, 503, 504].includes(response.status)) break` — except a
     * 429 that is a quota wall, which every endpoint would answer the same way.
     */
    private const array FALL_BACK_ON = [403, 404, 429, 500, 502, 503, 504];

    /**
     * @param string|null $fallback where to try when the primary refuses us, or null to work it
     *        out — which means Google's second host when the model points at Google's first, and
     *        no fallback at all when a `models.json` points this provider somewhere of its own.
     *        **Injectable for the same reason `$http` is**: a test cannot point a model at
     *        Google's host and also have the request arrive at a canned server, so under a
     *        hardcoded pair the one branch that matters is the one no test can see.
     */
    /**
     * @param (Closure(list<string> $triedAccessTokens): ?string)|null $failover pi-antigravity's
     *        `failoverToNextAccount()`: the next stored account whose access token has not been tried
     *        yet, made the active one, as an api key (`{"token", "projectId"}`) — or null when there
     *        is none. Asked when a request hits a quota wall.
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?string $fallback = null,
        private readonly ?Closure $failover = null,
    ) {
    }

    /**
     * Simple options into Gemini's dialect — what `Stream::translate()` used to do for this
     * provider as its `antigravity()` arm, and the `StreamApi` contract puts here. Thinking is
     * said as a level and only when it was asked for; the routing table turns the level into a
     * runtime model, which is this provider's own business and nobody else's.
     */
    #[\Override]
    public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions
    {
        return new GoogleOptions(
            $options?->temperature,
            $options?->maxTokens ?? $model->maxTokens,
            $options?->signal,
            $apiKey,
            thinkingEnabled: $options?->reasoning !== null,
            thinkingLevel: $options?->reasoning?->value,
        );
    }

    /** Returns at once; the response fills in as it arrives. */
    #[\Override]
    public function stream(Model $model, TranscriptContext $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        // Options that did not come through `translate()` — a caller of `Stream::start()` with
        // base options — carry the key and nothing Gemini-shaped; `GoogleOptions` is what the
        // rest of this class reads, so they are widened here.
        if ($options !== null && !$options instanceof GoogleOptions) {
            $options = new GoogleOptions($options->temperature, $options->maxTokens, $options->signal, $options->apiKey, thinkingEnabled: false);
        }

        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(
        AssistantMessageEventStream $stream,
        Model $model,
        TranscriptContext $context,
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;

        try {
            $received = false;

            // pi-antigravity's `emptyAttempt` loop: a response that carried no text, thinking or
            // tool call is asked for again, after 500 ms and then 1 s, before the turn fails with
            // "Antigravity API returned an empty response". Nothing goes out on the stream until
            // something has arrived (`ensureStarted()`), so an empty attempt leaves no trace.
            for ($emptyAttempt = 0; $emptyAttempt <= 2; $emptyAttempt++) {
                if ($signal?->aborted() ?? false) {
                    throw new ProviderError('Request was aborted');
                }

                if ($emptyAttempt > 0) {
                    self::sleep(0.5 * 2 ** ($emptyAttempt - 1), $signal);
                }

                $response = $this->send($model, $context, $options, $signal);

                // "output.content = []; output.usage = {…}; output.stopReason = "stop"": each attempt
                // starts from nothing.
                $builder = new AssistantMessageBuilder($model);
                $received = $this->read($response, $builder, $stream);

                if ($received) {
                    break;
                }
            }

            if (!$received) {
                throw new ProviderError('Antigravity API returned an empty response');
            }

            $signal?->throwIfAborted();

            // The direct Gemini path's check after the stream (`Google::run()`): an error reason
            // ends the turn with the reason itself, once the whole body has been read.
            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                $raw = $builder->rawStopReason();

                throw new ProviderError($raw !== null && $raw !== '' ? "Provider stopped with: {$raw}" : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result, with any
            // secret in it redacted (pi-antigravity's `safeError()`).
            $builder->fail(self::redactSecrets($error->getMessage()), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Read one response into the builder — pi-antigravity's `streamResponse()` — and say whether
     * anything arrived: a part with text (thinking or not) or a function call. The stream's
     * `start` goes out with the first of those, and not before.
     */
    private function read(Response $response, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): bool
    {
        $parser = new SseParser();
        $open = null;
        $hasContent = false;

        foreach ($response->body as $chunk) {
            foreach ($parser->feed($chunk) as $event) {
                $data = json_decode($event->data, true);

                if (!is_array($data)) {
                    continue;
                }

                // "if (chunk.error) throw new Error(chunk.error.message || JSON.stringify(chunk.error))".
                if (isset($data['error']) && $data['error'] !== null && $data['error'] !== false) {
                    $message = is_array($data['error']) ? ($data['error']['message'] ?? null) : null;

                    throw new ProviderError(is_string($message) && $message !== '' ? $message : (string) json_encode($data['error']));
                }

                // `chunk.response || chunk`.
                $responseData = is_array($data['response'] ?? null) ? $data['response'] : $data;

                // A blocked prompt comes back as a 200 with nothing in it but the reason. This
                // provider's own rule: upstream's Gemini path has no such check (its turn ends
                // "without a finish reason"), so it lives here, not in `GoogleShared`.
                $blocked = $responseData['promptFeedback']['blockReason'] ?? null;

                if (is_string($blocked)) {
                    throw new ProviderError("Gemini refused the prompt: {$blocked}");
                }

                foreach ($responseData['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (is_array($part) && (array_key_exists('text', $part) || isset($part['functionCall']))) {
                        if (!$hasContent) {
                            $stream->push(new StartEvent($builder->snapshot()));
                        }

                        $hasContent = true;

                        break;
                    }
                }

                // As the direct Gemini path reads it: an error finish reason is recorded and the
                // rest of the stream — usage included — is read before the turn fails on it.
                $open = GoogleShared::onChunk($responseData, $builder, $stream, $open);
            }
        }

        GoogleShared::close($builder, $stream, $open);

        return $hasContent;
    }

    /** pi-antigravity's backoff between empty attempts, cut short by an abort. */
    private static function sleep(float $seconds, ?AbortSignal $signal): void
    {
        $done = new \Pig\Async\Deferred();
        $timer = \Pig\Async\Loop::get()->delay($seconds, static fn () => $done->isComplete() ? null : $done->complete(null));
        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            \Pig\Async\Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(null);
            }
        });

        try {
            $done->future->await();
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /**
     * The request, through the runtime models, the endpoints and the stored accounts —
     * pi-antigravity's request loop.
     *
     * The runtime model `Routing` resolves, then `Routing::fallback()`'s (pi-antigravity's
     * `getFallbackRuntimeModel()`) when that one is answered 404. For each, every endpoint in turn
     * while the answer is one of `FALL_BACK_ON`, stopping at a success, at any other status, or at
     * a 429 that is a quota wall (`isQuotaWall()`). A quota wall then asks `$failover` for an
     * account not yet tried and starts over with it, its own project in the envelope; with none
     * left, the turn fails with the wall's own sentence ("Quota reached. …"), which the session
     * does not retry. Any other refusal is `Antigravity API error (<status>, <diagnostics>):
     * <friendlyAntigravityError()>`.
     *
     * Not ported: pi-antigravity's dynamic model discovery (`fetchAvailableRuntimeModel()`), for a
     * model its tables do not know or a 404 on every candidate — pig's catalogue is the tables.
     */
    private function send(Model $model, TranscriptContext $context, ?GoogleOptions $options, ?AbortSignal $signal): Response
    {
        $apiKey = $options?->apiKey;
        [$token, $project] = self::credentials($apiKey);
        $tried = [$token];

        // A `models.json` pointing this provider somewhere of its own — a proxy, a local gateway
        // — is believed rather than silently second-guessed with Google's other host.
        $primary = $model->baseUrl !== '' ? $model->baseUrl : self::ENDPOINT;
        $second = $this->fallback ?? ($primary === self::ENDPOINT ? self::FALLBACK_ENDPOINT : null);
        $hosts = $second === null || $second === $primary ? [$primary] : [$primary, $second];

        // Both, so that the two cannot contradict each other: `thinkingEnabled: false` with a level
        // set means off, which is the reading every other provider here gives it.
        $level = ($options?->thinkingEnabled ?? false) ? $options?->thinkingLevel : null;
        $initial = Routing::resolve($model->id, $level)[0];
        $candidates = [$initial];
        $fallback = Routing::fallback($initial, $level);

        if ($fallback !== null && $fallback !== $initial) {
            $candidates[] = $fallback;
        }

        while (true) {
            $last = null;
            $lastText = '';
            $lastHost = $primary;
            $runtime = $initial;

            foreach ($candidates as $index => $runtime) {
                $body = $this->encode($this->body($model, $context, $options, $project, $runtime));

                foreach ($hosts as $host) {
                    $lastHost = $host;
                    $last = $this->http->send($this->request($host, $token, $model, $body), $signal);

                    if ($last->isSuccessful()) {
                        return $last;
                    }

                    $lastText = $last->body->all();

                    if ($last->status === 429 && self::isQuotaWall($lastText)) {
                        break;
                    }

                    if (!in_array($last->status, self::FALL_BACK_ON, true)) {
                        break;
                    }
                }

                // "if (response?.status === 404) { if (candIdx + 1 < runtimeCandidates.length) continue; }"
                if ($last?->status === 404 && $index + 1 < count($candidates)) {
                    continue;
                }

                break;
            }

            // Unreachable with a non-empty host list, and `$hosts` always has one.
            if ($last === null) {
                throw new ProviderError('No endpoint to try.');
            }

            $friendly = self::friendlyAntigravityError($last->status, $lastText);

            if ($last->status === 429 && preg_match('/Quota reached\./i', $friendly) === 1) {
                $next = $this->failover !== null ? ($this->failover)($tried) : null;

                if ($next !== null) {
                    [$token, $project] = self::credentials($next);
                    $tried[] = $token;

                    continue;
                }

                throw new ProviderError($friendly);
            }

            throw new ProviderError(
                "Antigravity API error ({$last->status}, " . self::formatRequestDiagnostics($lastHost, $project, $runtime) . "): {$friendly}",
            );
        }
    }

    /**
     * pi-antigravity's `formatRequestDiagnostics()`. `matched` and `available` come from its
     * dynamic model discovery, which pig has not got, so they are its own values for "never ran".
     */
    private static function formatRequestDiagnostics(string $endpoint, string $project, string $runtime): string
    {
        return "endpoint={$endpoint}, project={$project}, runtimeModel={$runtime}, matched=none, available=unknown";
    }

    /**
     * pi-antigravity's quota-wall test on a 429's text: "Individual quota reached", a "Resets in …"
     * hint, or quota wording that is not about rate limiting.
     */
    private static function isQuotaWall(string $text): bool
    {
        return preg_match('/Individual quota reached/i', $text) === 1
            || preg_match('/Resets? in /i', $text) === 1
            || (preg_match('/rate.?limit/i', $text) !== 1 && preg_match('/quota exceeded|exceeded your|daily limit/i', $text) === 1);
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
    private function body(Model $model, TranscriptContext $context, ?GoogleOptions $options, string $project, ?string $runtime = null): array
    {
        // Both, so that the two cannot contradict each other: `thinkingEnabled: false` with a
        // level set means off, which is the reading every other provider here gives it.
        $level = ($options?->thinkingEnabled ?? false) ? $options?->thinkingLevel : null;
        [$resolved, $enum] = Routing::resolve($model->id, $level);

        // A runtime model `send()` fell back to goes out under its own enum.
        if ($runtime !== null && $runtime !== $resolved) {
            $enum = Routing::enumOf($runtime);
        } else {
            $runtime = $resolved;
        }

        $inner = ['contents' => GoogleShared::contents($model, $context)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        // The prompt and the tools are the transcript's, replayed (pi-antigravity's
        // `resolveCurrentSystemPrompt()` / `resolveCurrentTools()` over pi-ai's
        // `getCurrentSystemPrompt()` / `getCurrentTools()`): Code Assist has no mid-conversation
        // system messages, so the current state goes in the request's own fields.
        $systemPrompt = Transcript::getCurrentSystemPrompt($context->messages);
        $tools = Transcript::getCurrentTools($context->messages);

        if ($systemPrompt !== '') {
            $inner['systemInstruction'] = GoogleShared::systemInstruction($systemPrompt);
        }

        if ($tools !== []) {
            $inner['tools'] = GoogleShared::tools($tools);

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
    private static function seed(TranscriptContext $context): string
    {
        $text = '';
        // The first message of the conversation, not the system message that leads the transcript:
        // that one is the prompt, which is the same in every session.
        $conversation = array_values(array_filter(
            $context->messages,
            static fn (mixed $message): bool => !$message instanceof \Pig\Ai\SystemMessage,
        ));

        foreach ($conversation[0]->content ?? [] as $part) {
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
    private static function turnsSoFar(TranscriptContext $context): int
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
     * What went wrong — pi-antigravity's `friendlyAntigravityError()`, word for word. The backend's
     * text is `jsonOrTextError()`'s (the error body's `error.message`, else the body), with secrets
     * redacted, cut at 500 characters.
     *
     * The 429 wording is what decides whether the session retries: a quota wall ("Individual quota
     * reached", a "Resets in …" hint, or quota wording that is not about rate limiting) is `Quota
     * reached. …` — which matches none of the retryable patterns, so the turn fails at once with
     * the time to wait — and any other 429 is transient throttling, `Rate limited by Antigravity
     * (429 ResourceExhausted)…`, which the session's backoff waits out.
     */
    public static function friendlyAntigravityError(?int $status, string $text): string
    {
        $decoded = json_decode($text, true);
        $message = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['message'] ?? null) : null;
        $msg = mb_substr(self::redactSecrets(is_string($message) && $message !== '' ? $message : $text), 0, 500, 'UTF-8');

        if ($status === 400) {
            if (preg_match('/Requests ending with a model turn are not supported/i', $msg) === 1) {
                return 'Antigravity rejected an invalid conversation message boundary. Next: update the extension or add a user message / start a new session, then retry.';
            }

            if (preg_match('/function call turn comes immediately after a user turn or after a function response turn/i', $msg) === 1) {
                return 'Antigravity rejected an invalid function-call message boundary. Next: update the extension or start a new session, then retry; re-login is not required.';
            }

            if (preg_match('/API key not valid|API_KEY_INVALID/i', $msg) === 1) {
                return 'Antigravity login expired or credentials are invalid. Next: run /login antigravity, then retry.';
            }

            if (preg_match('/Invalid JSON payload|Unknown name/i', $msg) === 1) {
                return "Antigravity request format was rejected by the backend ({$msg}). Next: switch to a simpler model or retry after updating the extension.";
            }

            if (preg_match('/Request contains an invalid argument/i', $msg) === 1) {
                return "Antigravity rejected this request ({$msg}). Next: retry once; if it keeps failing, switch models or re-login.";
            }

            return "Bad request from Antigravity. Next: retry once, then run /login antigravity if it keeps failing. Backend said: {$msg}";
        }

        if ($status === 401) {
            return 'Antigravity authentication failed. Next: run /login antigravity, then retry.';
        }

        if ($status === 403) {
            if (preg_match('/permission|forbidden|access/i', $msg) === 1) {
                return 'Antigravity access was denied for this account or project. Next: try another model, re-login, or use an account with access.';
            }

            return "Antigravity denied this request. Next: re-login or try another model. Backend said: {$msg}";
        }

        if ($status === 404) {
            if (preg_match('/Requested entity was not found/i', $msg) === 1) {
                return 'This model is not available right now. Next: switch to gemini-3.8-flash, gemini-3.7-flash, gemini-3.6-flash, gemini-3.5-flash, gemini-3.1-pro, or another working model.';
            }

            return "Antigravity could not find the requested resource. Next: retry or switch models. Backend said: {$msg}";
        }

        if ($status === 408) {
            return 'Antigravity timed out. Next: retry the same request.';
        }

        if ($status === 409) {
            return 'Antigravity reported a conflict for this request. Next: retry once or start a new chat session.';
        }

        if ($status === 429) {
            $wait = preg_match('/Resets? in ([^.\n]+)/i', $msg, $match) === 1 ? trim($match[1]) : '';

            if (preg_match('/Individual quota reached/i', $msg) === 1) {
                return 'Quota reached. Please wait ' . ($wait !== '' ? $wait : 'for reset') . '. Next: switch models or try again after reset.';
            }

            $hardLimit = $wait !== ''
                || (preg_match('/rate.?limit/i', $msg) !== 1
                    && preg_match('/quota exceeded|exceeded your|limit reached|reached your|daily limit/i', $msg) === 1);

            if ($hardLimit) {
                return 'Quota reached.' . ($wait !== '' ? " Please wait {$wait}." : '') . ' Next: switch models or retry later.';
            }

            return 'Rate limited by Antigravity (429 ResourceExhausted). Next: retrying automatically; if it persists, switch models.';
        }

        if ($status === 500) {
            return 'Antigravity had an internal server error. Next: retry in a moment or switch models.';
        }

        if ($status === 502) {
            return 'Antigravity returned a bad gateway error. Next: retry in a moment.';
        }

        if ($status === 503) {
            if (preg_match('/No capacity available/i', $msg) === 1) {
                return 'This model has no capacity right now. Next: retry later or switch to another model.';
            }

            return 'Antigravity is temporarily unavailable. Next: retry in a moment or switch models.';
        }

        if ($status === 504) {
            return 'Antigravity timed out upstream. Next: retry in a moment.';
        }

        return $msg;
    }

    /** pi-antigravity's `redactSecrets()`. */
    private static function redactSecrets(string $text): string
    {
        return (string) preg_replace(
            [
                '/\bya29\.[A-Za-z0-9._~+\/-]+=*/',
                '/\b1\/[A-Za-z0-9_-]{20,}/',
                '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
                '/("?(?:access_token|refresh_token|id_token|token|client_secret|code_verifier|authorization)"?\s*[:=]\s*")[^"]*(")/i',
                '/("?(?:access_token|refresh_token|id_token|token|client_secret|code_verifier|authorization)"?\s*[:=]\s*)[^\s&,}]+/i',
            ],
            [
                '[redacted-access-token]',
                '[redacted-refresh-token]',
                'Bearer [redacted]',
                '$1[redacted]$2',
                '$1[redacted]',
            ],
            $text,
        );
    }
}
