<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Closure;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\ProviderError;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\Timestamp;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\ToolReference;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\Text;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * The faux provider — upstream's `createFauxCore()` and the `FauxProviderHandle` `fauxProvider()`
 * returns, in one object: the models, the queue of scripted responses, the state, and the stream.
 *
 * Each request takes the next queued step (a scripted `AssistantMessage`, or a factory
 * `(TranscriptContext, ?StreamOptions, FauxProviderState, Model): AssistantMessage`), rewrites it to
 * the provider and model asked, estimates its usage from the serialized context (`ceil(chars / 4)`
 * per side, and per `sessionId` a simulated prompt cache: the prefix shared with that session's last
 * prompt is a cache read, the rest a cache write), and streams it in pieces of 3 to 5 "tokens" (4
 * characters each), paced at `tokensPerSecond` when that is given. An empty queue is an error turn,
 * "No more faux responses queued".
 *
 * Not ported: the deferred responses (`options.deferred`, `fetchDeferred()`, `cancelDeferred()`),
 * which nothing in pig can ask for. Lengths are counted in code points where JavaScript counts UTF-16
 * units, so text outside the BMP estimates a little lower than upstream's.
 */
final class FauxProvider implements StreamApi
{
    private const string DEFAULT_MODEL_NAME = 'Faux Model';

    private const string DEFAULT_BASE_URL = 'http://localhost:0';

    private const int DEFAULT_MIN_TOKEN_SIZE = 3;

    private const int DEFAULT_MAX_TOKEN_SIZE = 5;

    public readonly FauxProviderState $state;

    /** @var non-empty-list<Model> */
    public readonly array $models;

    private readonly int $minTokenSize;

    private readonly int $maxTokenSize;

    /** @var list<AssistantMessage|Closure> */
    private array $pendingResponses = [];

    /** @var array<string, string> sessionId => the last prompt sent under it */
    private array $promptCache = [];

    /**
     * @param list<array<string, mixed>> $models see `Faux::fauxProvider()`
     * @param array{min?: int, max?: int} $tokenSize
     */
    public function __construct(
        public readonly string $provider = Faux::DEFAULT_PROVIDER,
        array $models = [],
        private readonly ?float $tokensPerSecond = null,
        array $tokenSize = [],
    ) {
        $this->minTokenSize = max(1, min($tokenSize['min'] ?? self::DEFAULT_MIN_TOKEN_SIZE, $tokenSize['max'] ?? self::DEFAULT_MAX_TOKEN_SIZE));
        $this->maxTokenSize = max($this->minTokenSize, $tokenSize['max'] ?? self::DEFAULT_MAX_TOKEN_SIZE);
        $this->state = new FauxProviderState();
        $definitions = $models !== [] ? $models : [[
            'id' => Faux::DEFAULT_MODEL_ID,
            'name' => self::DEFAULT_MODEL_NAME,
            'reasoning' => false,
            'input' => ['text', 'image'],
            'contextWindow' => 128_000,
            'maxTokens' => 16_384,
        ]];
        $this->models = array_map(fn (array $definition): Model => new Model(
            $definition['id'],
            $definition['name'] ?? $definition['id'],
            Api::Extension,
            $this->provider,
            self::DEFAULT_BASE_URL,
            $definition['contextWindow'] ?? 128_000,
            $definition['maxTokens'] ?? 16_384,
            $definition['reasoning'] ?? false,
            $definition['input'] ?? ['text', 'image'],
            new Pricing(...($definition['cost'] ?? [])),
            inputLimits: $definition['inputLimits'] ?? null,
        ), $definitions);
    }

    /** `createProvider({id, auth, models, api})`: this, as a provider `Extension\ProviderRegistry` takes. */
    public function provider(): Provider
    {
        return new Provider($this->provider, $this->provider, $this->models, $this);
    }

    /** Upstream's `getModel()`: the first model, or the one with this id. */
    public function getModel(?string $modelId = null): ?Model
    {
        if ($modelId === null || $modelId === '') {
            return $this->models[0];
        }

        foreach ($this->models as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /** @param list<AssistantMessage|Closure> $responses */
    public function setResponses(array $responses): void
    {
        $this->pendingResponses = array_values($responses);
    }

    /** @param list<AssistantMessage|Closure> $responses */
    public function appendResponses(array $responses): void
    {
        $this->pendingResponses = [...$this->pendingResponses, ...$responses];
    }

    public function getPendingResponseCount(): int
    {
        return count($this->pendingResponses);
    }

    /** `streamSimple` is `stream` here, as upstream's is: the options go through as they came. */
    #[\Override]
    public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions
    {
        return $options ?? new SimpleStreamOptions(apiKey: $apiKey);
    }

    #[\Override]
    public function stream(Model $model, TranscriptContext $context, StreamOptions $options): AssistantMessageEventStream
    {
        $outer = new AssistantMessageEventStream();
        $step = array_shift($this->pendingResponses);
        $this->state->callCount++;

        Async::spawn(function () use ($outer, $step, $model, $context, $options): void {
            try {
                if ($options->onResponse !== null) {
                    ($options->onResponse)(['status' => 200, 'headers' => []], $model);
                }

                if ($step === null) {
                    $message = $this->withUsageEstimate(
                        self::createErrorMessage('No more faux responses queued', $model),
                        $context,
                        $options,
                    );
                    $outer->push(new ErrorEvent(StopReason::Error, $message));
                    $outer->end($message);

                    return;
                }

                $message = $this->resolveResponse($step, $context, $options, $model);
                $this->streamWithDeltas($outer, $message, $options->signal);
            } catch (Throwable $error) {
                $message = self::createErrorMessage($error->getMessage(), $model);
                $outer->push(new ErrorEvent(StopReason::Error, $message));
                $outer->end($message);
            }
        });

        return $outer;
    }

    private function resolveResponse(AssistantMessage|Closure $step, TranscriptContext $context, StreamOptions $options, Model $model): AssistantMessage
    {
        $resolved = $step instanceof Closure ? $step($context, $options, $this->state, $model) : $step;

        if (!$resolved instanceof AssistantMessage) {
            throw new ProviderError('A faux response factory must return an AssistantMessage');
        }

        return $this->withUsageEstimate(self::cloneMessage($resolved, $model), $context, $options);
    }

    /** Upstream's `cloneMessage()`: the message under this provider's api, provider and model id. */
    private static function cloneMessage(AssistantMessage $message, Model $model): AssistantMessage
    {
        return self::with($message, $message->content, $message->stopReason, model: $model);
    }

    private static function createErrorMessage(string $error, Model $model): AssistantMessage
    {
        return new AssistantMessage([], $model->api, $model->provider, $model->id, new Usage(), StopReason::Error, $error);
    }

    /** Upstream's `createAbortedMessage(partial)`. */
    private static function createAbortedMessage(AssistantMessage $partial): AssistantMessage
    {
        return self::with($partial, $partial->content, StopReason::Aborted, 'Request was aborted', timestamp: Timestamp::nowMs());
    }

    /**
     * Upstream's `withUsageEstimate()`: prompt and output tokens estimated from the text, and with a
     * `sessionId` (and caching not `none`) the shared prefix with that session's last prompt read
     * from the cache and the rest written to it.
     */
    private function withUsageEstimate(AssistantMessage $message, TranscriptContext $context, StreamOptions $options): AssistantMessage
    {
        $promptText = self::serializeContext($context);
        $promptTokens = self::estimateTokens($promptText);
        $outputTokens = self::estimateTokens(self::assistantContentToText($message->content));
        $input = $promptTokens;
        $cacheRead = 0;
        $cacheWrite = 0;
        $sessionId = $options->sessionId;

        if ($sessionId !== null && $sessionId !== '' && $options->cacheRetention !== 'none') {
            $previousPrompt = $this->promptCache[$sessionId] ?? null;

            if ($previousPrompt !== null && $previousPrompt !== '') {
                $cachedChars = self::commonPrefixLength($previousPrompt, $promptText);
                $cacheRead = self::estimateTokens(mb_substr($previousPrompt, 0, $cachedChars));
                $cacheWrite = self::estimateTokens(mb_substr($promptText, $cachedChars));
                $input = max(0, $promptTokens - $cacheRead);
            } else {
                $cacheWrite = $promptTokens;
            }

            $this->promptCache[$sessionId] = $promptText;
        }

        $usage = new Usage($input, $outputTokens, $cacheRead, $cacheWrite, $input + $outputTokens + $cacheRead + $cacheWrite, new Cost());

        return self::with($message, $message->content, $message->stopReason, usage: $usage);
    }

    /**
     * Upstream's `streamWithDeltas()`: `start`, then each block's start, its pieces, its end — a tool
     * call's arguments streamed as their JSON and set whole at the end — and `done`, or `error` for a
     * scripted error or aborted message. The signal is looked at before every block and piece.
     */
    private function streamWithDeltas(AssistantMessageEventStream $stream, AssistantMessage $message, ?AbortSignal $signal): void
    {
        $content = [];
        $partial = static function () use ($message, &$content): AssistantMessage {
            return self::with($message, $content, StopReason::Pending);
        };
        $abort = static function () use ($stream, $partial): void {
            $aborted = self::createAbortedMessage($partial());
            $stream->push(new ErrorEvent(StopReason::Aborted, $aborted));
            $stream->end($aborted);
        };

        if ($signal?->aborted() ?? false) {
            $abort();

            return;
        }

        $stream->push(new StartEvent($partial()));

        foreach ($message->content as $index => $block) {
            if ($signal?->aborted() ?? false) {
                $abort();

                return;
            }

            if ($block instanceof ThinkingContent) {
                $content[] = new ThinkingContent('');
                $stream->push(new ThinkingStartEvent($index, $partial()));

                foreach ($this->splitStringByTokenSize($block->thinking) as $chunk) {
                    $this->scheduleChunk($chunk);

                    if ($signal?->aborted() ?? false) {
                        $abort();

                        return;
                    }

                    $content[$index] = new ThinkingContent($content[$index]->thinking . $chunk);
                    $stream->push(new ThinkingDeltaEvent($index, $chunk, $partial()));
                }

                $stream->push(new ThinkingEndEvent($index, $block->thinking, $partial()));

                continue;
            }

            if ($block instanceof TextContent) {
                $content[] = new TextContent('');
                $stream->push(new TextStartEvent($index, $partial()));

                foreach ($this->splitStringByTokenSize($block->text) as $chunk) {
                    $this->scheduleChunk($chunk);

                    if ($signal?->aborted() ?? false) {
                        $abort();

                        return;
                    }

                    $content[$index] = new TextContent($content[$index]->text . $chunk);
                    $stream->push(new TextDeltaEvent($index, $chunk, $partial()));
                }

                $stream->push(new TextEndEvent($index, $block->text, $partial()));

                continue;
            }

            \assert($block instanceof ToolCall);
            $content[] = new ToolCall($block->id, $block->name, []);
            $stream->push(new ToolCallStartEvent($index, $partial()));

            foreach ($this->splitStringByTokenSize(self::json($block->arguments)) as $chunk) {
                $this->scheduleChunk($chunk);

                if ($signal?->aborted() ?? false) {
                    $abort();

                    return;
                }

                $stream->push(new ToolCallDeltaEvent($index, $chunk, $partial()));
            }

            $content[$index] = new ToolCall($block->id, $block->name, $block->arguments);
            $stream->push(new ToolCallEndEvent($index, $block, $partial()));
        }

        if ($message->stopReason === StopReason::Pending) {
            throw new ProviderError('Faux response ended without a stop reason');
        }

        if ($message->stopReason === StopReason::Error || $message->stopReason === StopReason::Aborted) {
            $stream->push(new ErrorEvent($message->stopReason, $message));
            $stream->end($message);

            return;
        }

        $stream->push(new DoneEvent($message->stopReason, $message));
        $stream->end($message);
    }

    /** Upstream's `scheduleChunk()`: a turn of the loop, or the chunk's tokens at `tokensPerSecond`. */
    private function scheduleChunk(string $chunk): void
    {
        if ($this->tokensPerSecond === null || $this->tokensPerSecond <= 0) {
            Async::delay(0);

            return;
        }

        Async::delay(self::estimateTokens($chunk) / $this->tokensPerSecond);
    }

    /**
     * Upstream's `splitStringByTokenSize()`: pieces of a random 3 to 5 tokens, 4 characters each;
     * an empty text is one empty piece.
     *
     * @return list<string>
     */
    private function splitStringByTokenSize(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $index = 0;

        while ($index < $length) {
            $tokenSize = $this->minTokenSize + random_int(0, $this->maxTokenSize - $this->minTokenSize);
            $charSize = max(1, $tokenSize * 4);
            $chunks[] = mb_substr($text, $index, $charSize);
            $index += $charSize;
        }

        return $chunks !== [] ? $chunks : [''];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    private static function commonPrefixLength(string $a, string $b): int
    {
        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $length = min(count($left), count($right));
        $index = 0;

        while ($index < $length && $left[$index] === $right[$index]) {
            $index++;
        }

        return $index;
    }

    /** @param list<TextContent|ImageContent> $content */
    private static function contentToText(array $content): string
    {
        return implode("\n", array_map(
            static fn (TextContent|ImageContent $block): string => $block instanceof TextContent
                ? $block->text
                : "[image:{$block->mimeType}:" . strlen($block->data) . ']',
            $content,
        ));
    }

    /** @param list<mixed> $content */
    private static function assistantContentToText(array $content): string
    {
        return implode("\n", array_map(static fn (mixed $block): string => match (true) {
            $block instanceof TextContent => $block->text,
            $block instanceof ThinkingContent => $block->thinking,
            $block instanceof ToolCall => "{$block->name}:" . self::json($block->arguments),
            default => '',
        }, $content));
    }

    private static function messageToText(mixed $message): string
    {
        if ($message instanceof SystemMessage) {
            return implode("\n", array_values(array_filter([
                Text::getSystemMessageText($message),
                ...array_map(static fn (ToolReference $tool): string => 'tool-:' . JsJson::stringify(['name' => $tool->name]), $message->toolsRemoved ?? []),
                ...array_map(static fn (Tool $tool): string => 'tool+:' . JsJson::stringify(MessageJson::encodeTool($tool)), $message->toolsAdded ?? []),
            ], static fn (string $part): bool => $part !== '')));
        }

        if ($message instanceof UserMessage) {
            return self::contentToText($message->content);
        }

        if ($message instanceof AssistantMessage) {
            return self::assistantContentToText($message->content);
        }

        \assert($message instanceof ToolResultMessage);

        return implode("\n", [$message->toolName, ...array_map(static fn ($block): string => self::contentToText([$block]), $message->content)]);
    }

    private static function serializeContext(TranscriptContext $context): string
    {
        return implode("\n\n", array_map(static fn (mixed $message): string => self::role($message) . ':' . self::messageToText($message), $context->messages));
    }

    private static function role(mixed $message): string
    {
        return match (true) {
            $message instanceof SystemMessage => 'system',
            $message instanceof UserMessage => 'user',
            $message instanceof AssistantMessage => 'assistant',
            default => 'toolResult',
        };
    }

    /** `JSON.stringify(arguments)`, an empty object as `{}`. */
    private static function json(array $arguments): string
    {
        return $arguments === [] ? '{}' : JsJson::stringify($arguments);
    }

    /**
     * The message with these fields replaced — `{...message, content, stopReason}` and, from
     * `cloneMessage()`, the request's api, provider and model.
     *
     * @param list<mixed> $content
     */
    private static function with(
        AssistantMessage $message,
        array $content,
        StopReason $stopReason,
        ?string $errorMessage = null,
        ?Model $model = null,
        ?Usage $usage = null,
        ?int $timestamp = null,
    ): AssistantMessage {
        return new AssistantMessage(
            $content,
            $model?->api ?? $message->api,
            $model?->provider ?? $message->provider,
            $model?->id ?? $message->model,
            $usage ?? $message->usage,
            $stopReason,
            $errorMessage ?? $message->errorMessage,
            $timestamp ?? $message->timestamp,
            $message->rawStopReason,
            $message->responseId,
            $message->responseModel,
            $message->endTurn,
            $message->diagnostics,
            $message->providerThinkingLevel,
            $message->deferred,
            $message->thinkingLevel,
        );
    }
}
