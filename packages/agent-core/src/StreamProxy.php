<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\MessageJson;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Upstream's `agent/proxy.ts`: talk to a gateway server instead of to a provider.
 *
 * The server holds the provider keys and makes the call; pig posts the whole request to it and
 * reads events back. That is the opposite trade from `Ai\Http\Proxy`, which is the other thing
 * called a proxy in this repository and does the opposite: it carries pig's own encrypted bytes to
 * the provider and cannot read them. **This one hands the conversation and the system prompt to
 * whoever runs the gateway.** For a team that does not want keys on laptops that is the point; for
 * anybody else it is a reason not to use it, and the choice belongs to the person deploying it, so
 * nothing here turns it on — it is a `streamFn`, passed in:
 *
 * ```php
 * $proxy = new StreamProxy('https://genai.example.com', $token);
 * $agent = new Agent(new AgentOptions(streamFn: $proxy->stream(...)));
 * ```
 *
 * The wire shape is upstream's exactly, so a gateway written for pi serves pig unchanged:
 * `POST {proxyUrl}/api/stream`, `Authorization: Bearer …`, and a body of
 * `{model, context, options: {temperature, maxTokens, reasoning}}`. That is why the request is
 * built from `Ai\Utils\MessageJson` — the same encoder the session file uses, because upstream
 * sends the same objects to both and a second hand-written notion of a message would drift from it.
 *
 * `model` goes over the wire whole, `baseUrl` and all, because upstream's server reads the
 * provider and api off it to decide who to call. A gateway is therefore as trusted as the provider
 * would be: it is told which endpoint pig thinks it is talking to.
 *
 * Not ported: upstream's `validateToolCall(tools, call)` has no caller there either, and its
 * `ProxyAssistantMessageEvent` union is a TypeScript type — the event classes in `pig/ai` are the
 * counterpart, and `Ai\Utils\JsonSchema` is where a shape gets checked.
 */
final class StreamProxy
{
    /** Upstream's path, and not configurable there either. */
    private const string PATH = '/api/stream';

    public function __construct(
        private readonly string $proxyUrl,
        private readonly string $authToken,
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    /**
     * Returns at once; the response fills in as it arrives.
     *
     * The signature is `AgentOptions::$streamFn`'s, so `$proxy->stream(...)` is the whole of the
     * wiring. A provider's own `stream()` looks the same on purpose.
     */
    public function stream(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
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
        ?SimpleStreamOptions $options,
    ): void {
        // Upstream reaches into `pi-ai/dist/utils/json-parse.js` here with a comment saying it is
        // an internal import. This is the same reach: `AssistantMessageBuilder` is `@internal` to
        // `pig/ai`, and writing a second accumulator would be a second answer to "what does a
        // half-finished tool call look like".
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new AgentError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));

            $finished = false;

            foreach ($this->lines($response->body, $signal) as $line) {
                if ($this->dispatch($line, $builder, $stream)) {
                    $finished = true;

                    // `done` and `error` both end the stream, and pushing onto an ended stream is
                    // not a thing to try. A gateway with anything to say after them is finished
                    // saying it.
                    break;
                }
            }

            $signal?->throwIfAborted();

            // A gateway that stopped without a `done` left a turn half-finished, and a caller
            // cannot tell that from a turn that ended: `stopReason` would still read `stop`.
            if (!$finished) {
                throw new AgentError('The proxy ended the stream without a done event');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // Upstream's catch, and every provider's here: a stream function never throws at its
            // caller, the failure is the stream's result.
            $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * The request, in upstream's shape.
     *
     * `reasoning` is the enum's own string, which is what upstream sends: its `reasoning` is the
     * same five words.
     */
    private function request(Model $model, Context $context, ?SimpleStreamOptions $options): Request
    {
        $body = json_encode([
            'model' => self::encodeModel($model),
            'context' => self::encodeContext($context),
            'options' => [
                'temperature' => $options?->temperature,
                'maxTokens' => $options?->maxTokens,
                'reasoning' => $options?->reasoning?->value,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new AgentError('The conversation could not be encoded for the proxy');
        }

        return new Request(
            'POST',
            rtrim($this->proxyUrl, '/') . self::PATH,
            [
                'Authorization' => 'Bearer ' . $this->authToken,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            $body,
        );
    }

    /**
     * The model, in upstream's `Model` shape.
     *
     * **`cost`, not `pricing`** — pig renamed the field and the wire keeps upstream's name, because
     * the server reading this was written against upstream's interface.
     *
     * `headers` goes too, when the model has any, and that is worth knowing before using this: a
     * custom provider declared in `models.json` with an extra auth header hands that header to the
     * gateway. Upstream sends the model object whole and so does this; a gateway is trusted with
     * the conversation already, and a model whose headers pig withheld would fail at the gateway
     * for a reason nobody could see. `compat` goes for the same reason — without it the server
     * re-detects it from the URL and a deliberate override would silently stop applying. `input`,
 * `contextWindow` and `maxTokens` are sent as upstream orders them, not because order matters to
 * JSON but because a diff against `types.ts` is how the next person checks this.
     *
     * @return array<string, mixed>
     */
    private static function encodeModel(Model $model): array
    {
        $encoded = [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            'input' => $model->input,
            'cost' => [
                'input' => $model->pricing->input,
                'output' => $model->pricing->output,
                'cacheRead' => $model->pricing->cacheRead,
                'cacheWrite' => $model->pricing->cacheWrite,
            ],
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
        ];

        if ($model->headers !== []) {
            $encoded['headers'] = $model->headers;
        }

        if ($model->compat !== null) {
            // Upstream's eight key names, all of them — pig's eight fields map one to one, and
            // `CustomModels` reads and writes the same names, so a `models.json`, a session file
            // and this request all say the same thing.
            $encoded['compat'] = [
                'supportsStore' => $model->compat->store,
                'supportsDeveloperRole' => $model->compat->developerRole,
                'supportsReasoningEffort' => $model->compat->reasoningEffort,
                'maxTokensField' => $model->compat->maxTokensField,
                'requiresToolResultName' => $model->compat->toolResultName,
                'requiresAssistantAfterToolResult' => $model->compat->assistantAfterToolResult,
                'requiresThinkingAsText' => $model->compat->thinkingAsText,
                'requiresMistralToolIds' => $model->compat->mistralToolIds,
            ];
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    private static function encodeContext(Context $context): array
    {
        return [
            'systemPrompt' => $context->systemPrompt,
            'messages' => array_values(array_filter(array_map(
                MessageJson::encode(...),
                $context->messages,
            ), static fn (?array $message): bool => $message !== null)),
            'tools' => array_map(static fn (Tool $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
            ], $context->tools),
        ];
    }

    /**
     * The `data:` payloads, one per line.
     *
     * **Not `SseParser`, deliberately** — and the reason is not that upstream does it this way.
     * Splitting on `\n` and treating each `data:` line as one whole event is an *assumption*, and a
     * sound one: `JSON.stringify` never emits a newline, so an event is always exactly one line.
     * Since a blank line does not start with `data:`, this reader also handles a properly framed
     * stream — it is the superset. A strict SSE parser is not: it waits for the blank line and
     * joins consecutive `data:` lines into one event, so against a gateway that sends events back
     * to back it stalls or glues two together. The gateway's source is not in either repository, so
     * which of the two wires it sends cannot be checked, and only one of the two readers is right
     * either way.
     *
     * **One leading space is optional, which upstream's copy of this gets wrong.** The same twelve
     * lines exist twice in pi: `proxy.ts` tests `startsWith("data: ")` and slices 6, while
     * `google-gemini-cli.ts` tests `startsWith("data:")` and slices 5 then trims. The event-stream
     * spec makes the space after the colon optional and strips exactly one, so the second is
     * correct and the first silently drops every event from a gateway that writes `data:{…}`. The
     * turn would then end with no `done` — which is why the failure that used to be a silent empty
     * message is now a named one.
     *
     * @param iterable<string> $body
     * @return iterable<string>
     */
    private function lines(iterable $body, ?AbortSignal $signal): iterable
    {
        $buffer = '';

        foreach ($body as $chunk) {
            $signal?->throwIfAborted();
            $buffer .= $chunk;
            $pieces = explode("\n", $buffer);
            $buffer = (string) array_pop($pieces);

            foreach ($pieces as $line) {
                $data = self::payload($line);

                if ($data !== null) {
                    yield $data;
                }
            }
        }

        // The last line, for a server that ends without a newline.
        $data = self::payload($buffer);

        if ($data !== null) {
            yield $data;
        }
    }

    /**
     * One `data:` line's payload, or null for anything else.
     *
     * A `\r` from a server writing CRLF goes with the trim, as does the one optional space.
     */
    private static function payload(string $line): ?string
    {
        if (!str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));

        return $data === '' ? null : $data;
    }

    /** @return bool true for `done` or `error`, the two that end the stream */
    private function dispatch(string $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): bool
    {
        $event = json_decode($data, true);

        if (!is_array($event)) {
            // A line that is not JSON is the gateway's mistake, not the conversation's. Upstream
            // lets `JSON.parse` throw here, which ends the turn; a `: keep-alive` comment or a
            // blank `data:` would then kill a working stream, so this skips instead.
            return false;
        }

        $wire = isset($event['contentIndex']) ? (int) $event['contentIndex'] : -1;
        $delta = (string) ($event['delta'] ?? '');
        $type = $event['type'] ?? null;

        // `done` and `error` first, because they are the two that answer true.
        if ($type === 'done') {
            $this->done($builder, $stream, $event);

            return true;
        }

        if ($type === 'error') {
            $this->failed($builder, $stream, $event);

            return true;
        }

        match ($type) {
            'start' => $stream->push(new StartEvent($builder->snapshot())),
            'text_start' => $stream->push(new TextStartEvent(
                $builder->startText($wire),
                $builder->snapshot(),
            )),
            'thinking_start' => $stream->push(new ThinkingStartEvent(
                $builder->startThinking($wire),
                $builder->snapshot(),
            )),
            'toolcall_start' => $stream->push(new ToolCallStartEvent(
                $builder->startToolCall($wire, (string) ($event['id'] ?? ''), (string) ($event['toolName'] ?? '')),
                $builder->snapshot(),
            )),
            // Thinking accumulates in the same field as text; only the block's type tells them
            // apart, which is `AssistantMessageBuilder`'s rule and not this file's invention.
            'text_delta' => $this->append($builder, $stream, $wire, 'text', $delta, 'text_delta'),
            'thinking_delta' => $this->append($builder, $stream, $wire, 'text', $delta, 'thinking_delta'),
            'toolcall_delta' => $this->append($builder, $stream, $wire, 'json', $delta, 'toolcall_delta'),
            'text_end' => $this->end($builder, $stream, $wire, 'text_end', $event),
            'thinking_end' => $this->end($builder, $stream, $wire, 'thinking_end', $event),
            'toolcall_end' => $this->end($builder, $stream, $wire, 'toolcall_end', $event),
            // Upstream warns to the console for an unknown type. There is no console to warn to
            // during a turn — it would land in the middle of the drawn screen — and an event this
            // does not know is one it has nothing to do about.
            default => null,
        };

        return false;
    }

    /**
     * A delta against a block the gateway opened.
     *
     * Upstream throws `Received text_delta for non-text content` when the block at that index is
     * the wrong kind, and that throw ends the turn through its catch. Here a block the gateway
     * never opened has no index at all, which is the same mistake arriving one step earlier.
     */
    private function append(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        int $wire,
        string $field,
        string $delta,
        string $type,
    ): void {
        $index = $builder->indexOf($wire);

        if ($index === null) {
            throw new AgentError("The proxy sent {$type} for a block it never opened ({$wire})");
        }

        $builder->append($index, $field, $delta);

        $stream->push(match ($type) {
            'thinking_delta' => new ThinkingDeltaEvent($index, $delta, $builder->snapshot()),
            'toolcall_delta' => new ToolCallDeltaEvent($index, $delta, $builder->snapshot()),
            default => new TextDeltaEvent($index, $delta, $builder->snapshot()),
        });
    }

    /** @param array<string, mixed> $event */
    private function end(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        int $wire,
        string $type,
        array $event,
    ): void {
        $index = $builder->indexOf($wire);

        if ($index === null) {
            throw new AgentError("The proxy sent {$type} for a block it never opened ({$wire})");
        }

        // One field for both, as upstream has it: `contentSignature` closes text and thinking
        // alike, and the block's own type decides what it means.
        if (isset($event['contentSignature']) && is_string($event['contentSignature'])) {
            $builder->setSignature($index, $event['contentSignature']);
        }

        $stream->push(match ($type) {
            'thinking_end' => new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()),
            'toolcall_end' => new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()),
            default => new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()),
        });
    }

    /** @param array<string, mixed> $event */
    private function done(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $event): void
    {
        // Upstream's server sends stop, length or toolUse here, never error — that is the other
        // event. An unknown word is treated as a plain stop rather than as a failure: the turn did
        // finish, and refusing a finished turn over a word loses the work.
        $builder->setStopReason(StopReason::tryFrom((string) ($event['reason'] ?? '')) ?? StopReason::Stop);
        $builder->setUsage(self::usage($event['usage'] ?? []), priced: true);
        $message = $builder->snapshot();
        $stream->push(new DoneEvent($message->stopReason, $message));
        $stream->end();
    }

    /** @param array<string, mixed> $event */
    private function failed(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $event): void
    {
        $builder->setUsage(self::usage($event['usage'] ?? []), priced: true);
        $builder->fail(
            (string) ($event['errorMessage'] ?? 'The proxy reported an error with no message'),
            ($event['reason'] ?? '') === 'aborted',
        );
        $failed = $builder->snapshot();
        $stream->push(new ErrorEvent($failed->stopReason, $failed));
        $stream->end();
    }

    /**
     * The usage a gateway reports.
     *
     * Trusted as sent, **cost included** — `setUsage(…, priced: true)`, which is upstream assigning
     * `partial.usage = proxyEvent.usage` whole. The gateway made the call and knows what it paid;
     * pig knows only what the model's public price list says, so repricing here reports a number
     * nobody was charged: list price for a gateway on its own deal, and a bill for one running
     * flat-rate. That repricing is right for the four providers, none of which sends a cost at all,
     * and this is the one caller that gets one.
     *
     * The other side of trusting the wire: a gateway that sends no `cost` is reported as costing
     * nothing. Upstream's own type requires the field, so an absent one is taken at its word rather
     * than guessed at from a price list pig has no reason to think applies.
     *
     * The token counts are still added up when the gateway sends no total — that one is arithmetic
     * on numbers it did send.
     *
     * @param mixed $usage
     */
    private static function usage(mixed $usage): Usage
    {
        return MessageJson::decodeUsage(is_array($usage) ? $usage : []);
    }

    /** A non-2xx, with the server's own `error` if it sent one — upstream's wording. */
    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            return 'Proxy error: ' . $decoded['error'];
        }

        return "Proxy error: {$status}";
    }

}
