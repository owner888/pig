<?php

declare(strict_types=1);

/**
 * The providers, against the real endpoints.
 *
 * `php test/live.php [provider …]` — every provider a key can be found for, or only the ones
 * named. Each scenario prints one line: `ok`, `no` with what went wrong, or `--` when the model
 * cannot do that thing.
 *
 * **Not a PHPUnit test, on purpose.** It costs money and needs credentials, so it must never run
 * in the ordinary suite — which is upstream's arrangement too (`describe.skipIf(!API_KEY)`), one
 * step further: a script cannot be picked up by a runner at all.
 *
 * What it is for is the half a canned server cannot check: **whether the provider accepts what pig
 * sends.** `packages/ai/test/` has 167 tests over 4,977 lines of hand-written protocol and every one
 * of them answers a server pig wrote itself, from fixtures pig wrote itself — and a fixture written
 * to agree with the code is a failure this project has already had twice (Anthropic's
 * `message_delta` usage, and `retry.maxAttempts`). A replayed thinking signature, an invented tool
 * result, a conversation carried over from another provider and a prompt too long for the window
 * are all things only the far end can rule on.
 *
 * Keys come from where pig's own do — `Auth::discover()`, so `~/.pig/auth.json` is enough on a
 * machine that has signed in, and the environment otherwise. **Never from an argument**: argv is in
 * the process list for anybody on the machine to read.
 *
 * Every call is capped at a few hundred tokens and the overflow case is refused before it is
 * billed, so a full run over five providers costs a fraction of a cent. It is still a real spend
 * and a real rate-limit footprint, which is why nothing runs it automatically.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Overflow;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;

/**
 * One cheap model per provider, and the smallest reasoning-capable one where there is a choice.
 *
 * Cheap on purpose: this asks whether the protocol works, and the protocol does not get better on
 * a larger model. Each row is `[model id, does it reason, does it take images]` — read off
 * `Ai\Models` rather than assumed, so a wrong guess here shows up as `--` rather than as a failure.
 */
const MODELS = [
    'anthropic' => 'claude-sonnet-4-5',
    'openai' => 'gpt-5-mini',
    'google' => 'gemini-2.5-flash',
    'groq' => 'llama-3.3-70b-versatile',
    'xai' => 'grok-3-fast',
    'cerebras' => 'llama3.1-8b',
    'zai' => 'glm-4.5-flash',
    'mistral' => 'mistral-small-latest',
    'github-copilot' => 'gpt-4o',
];

/**
 * Enough of a picture to be a picture: a 16×16 red PNG, 79 bytes.
 *
 * **Not 1×1**, which was the first thing tried and which Anthropic refuses outright with
 * `400 Could not process image` — a valid PNG that the far end will not decode. That is worth
 * recording rather than just fixing: the failure looks exactly like a provider rejecting pig's
 * image encoding, and it is the harness handing over something no camera would produce.
 */
const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR42mO4Y2NDEmIY1TCqYfhqAABhl1QQ5Z/nmAAAAABJRU5ErkJggg==';

final class Live
{
    private int $ok = 0;

    private int $no = 0;

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly Auth $auth)
    {
    }

    public function provider(string $provider, string $id): void
    {
        $model = Models::find($provider, $id);

        if ($model === null) {
            printf("\n%s — no such model in the registry: %s\n", $provider, $id);

            return;
        }

        if (!$this->auth->hasKeyFor($provider)) {
            printf("\n%s — no key, skipped\n", $provider);

            return;
        }

        printf("\n%s / %s   (reasoning: %s, images: %s)\n", $provider, $id,
            $model->reasoning ? 'yes' : 'no', $model->acceptsImages() ? 'yes' : 'no');

        $this->run('text', fn (): string => $this->text($model));
        $this->run('stream order', fn (): string => $this->events($model));
        $this->run('tool call and result', fn (): string => $this->tools($model));
        $this->run('thinking, then replayed', fn (): string => $this->thinking($model));
        $this->run('image input', fn (): string => $this->image($model));
        $this->run('abort keeps usage', fn (): string => $this->abort($model));
        $this->run('empty message', fn (): string => $this->empty($model));
        $this->run('dangling tool call', fn (): string => $this->dangling($model));
        $this->run('overflow is recognised', fn (): string => $this->overflow($model));
        $this->run('usage is complete', fn (): string => $this->usage($model));
        $this->run('max_tokens stops it', fn (): string => $this->truncated($model));
        $this->run('prompt caching works', fn (): string => $this->caching($model));
    }

    /** A conversation from one provider carried on by another — upstream's `handoff.test.ts`. */
    public function handoff(string $from, string $to): void
    {
        $source = Models::find($from, MODELS[$from] ?? '');
        $target = Models::find($to, MODELS[$to] ?? '');

        if ($source === null || $target === null
            || !$this->auth->hasKeyFor($from) || !$this->auth->hasKeyFor($to)) {
            return;
        }

        $this->run("handoff {$from} → {$to}", function () use ($source, $target): string {
            // A real turn from the first provider, thinking and all where it can.
            $first = $this->answer($source, new Context(
                [new UserMessage('Name one colour. One word.')],
                'Answer in one word.',
            ), reasoning: $source->reasoning ? ReasoningEffort::Low : null);

            if ($first->stopReason === StopReason::Error) {
                return "the first turn failed: {$first->errorMessage}";
            }

            // …handed to the second, which is what `TransformMessages` exists for: a thinking block
            // signed by somebody else, and an id shape the second provider never issued.
            $second = $this->answer($target, new Context([
                new UserMessage('Name one colour. One word.'),
                $first,
                new UserMessage('Now name a different one. One word.'),
            ], 'Answer in one word.'));

            return $second->stopReason === StopReason::Error
                ? "the second turn refused it: {$second->errorMessage}"
                : 'ok';
        });
    }

    public function report(): int
    {
        printf("\n%d ok, %d failed\n", $this->ok, $this->no);

        foreach ($this->failures as $failure) {
            printf("  %s\n", $failure);
        }

        return $this->no === 0 ? 0 : 1;
    }

    // ---- the scenarios ---------------------------------------------------------------------

    private function text(Model $model): string
    {
        $message = $this->answer($model, new Context([new UserMessage('Say the single word: hello')]));

        if ($message->stopReason === StopReason::Error) {
            return (string) $message->errorMessage;
        }

        if (self::textOf($message) === '') {
            return 'no text came back';
        }

        return $message->usage->input > 0 ? 'ok' : 'no input tokens reported';
    }

    private function events(Model $model): string
    {
        $seen = [];

        Async::run(function () use ($model, &$seen): void {
            $stream = Stream::simple($model, new Context([new UserMessage('Count: one two three')]),
                new SimpleStreamOptions(maxTokens: 64, apiKey: $this->key($model)));

            foreach ($stream as $event) {
                $seen[] = (new ReflectionClass($event))->getShortName();
            }

            $stream->result()->await();
        });

        $first = $seen[0] ?? '';
        $last = $seen[count($seen) - 1] ?? '';

        if ($first !== 'StartEvent') {
            return "the first event was {$first}, not StartEvent";
        }

        if ($last !== 'DoneEvent') {
            return "the last event was {$last}, not DoneEvent";
        }

        return in_array('TextDeltaEvent', $seen, true) ? 'ok' : 'no text arrived as deltas';
    }

    private function tools(Model $model): string
    {
        $tool = new Tool('get_weather', 'Look up the weather somewhere.', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => 'the city']],
            'required' => ['city'],
        ]);

        $asked = new Context(
            [new UserMessage('What is the weather in Singapore? Use the tool.')],
            'Use the tool when one fits.',
            [$tool],
        );

        $first = $this->answer($model, $asked);

        if ($first->stopReason === StopReason::Error) {
            return (string) $first->errorMessage;
        }

        $calls = $first->toolCalls();

        if ($calls === []) {
            return 'the model did not call the tool';
        }

        $call = $calls[0];

        if (($call->arguments['city'] ?? null) === null) {
            return 'the call arrived without its arguments: ' . json_encode($call->arguments);
        }

        // The half that matters: pig sends the result back under the id the provider issued.
        $second = $this->answer($model, new Context([
            ...$asked->messages,
            $first,
            new ToolResultMessage($call->id, $call->name, [new TextContent('31°C and humid.')]),
        ], $asked->systemPrompt, [$tool]));

        return $second->stopReason === StopReason::Error
            ? "the result was refused: {$second->errorMessage}"
            : 'ok';
    }

    private function thinking(Model $model): string
    {
        if (!$model->reasoning) {
            return '--';
        }

        $asked = new Context([new UserMessage('What is 17 × 23? Think it through.')]);
        $first = $this->answer($model, $asked, reasoning: ReasoningEffort::Low);

        if ($first->stopReason === StopReason::Error) {
            return (string) $first->errorMessage;
        }

        $thoughts = array_filter($first->content, static fn (object $b): bool => $b instanceof ThinkingContent);

        if ($thoughts === []) {
            return 'the model reasoned but sent no thinking block';
        }

        // **This is the scenario no canned server can stand in for.** A thinking block is signed,
        // and the signature is meaningless to anybody but the provider that issued it — so whether
        // pig replays it in a shape the provider accepts is a question only the provider answers.
        $second = $this->answer($model, new Context([
            ...$asked->messages,
            $first,
            new UserMessage('And 17 × 24?'),
        ]), reasoning: ReasoningEffort::Low);

        return $second->stopReason === StopReason::Error
            ? "the replayed signature was refused: {$second->errorMessage}"
            : 'ok';
    }

    private function image(Model $model): string
    {
        if (!$model->acceptsImages()) {
            return '--';
        }

        $message = $this->answer($model, new Context([
            new UserMessage([new TextContent('What colour is this? One word.'), new ImageContent(PIXEL, 'image/png')]),
        ]));

        return $message->stopReason === StopReason::Error ? (string) $message->errorMessage : 'ok';
    }

    private function abort(Model $model): string
    {
        $message = Async::run(function () use ($model): AssistantMessage {
            $controller = new AbortController();
            $stream = Stream::simple(
                $model,
                new Context([new UserMessage('Write a poem of twenty stanzas about the sea.')]),
                new SimpleStreamOptions(maxTokens: 2_048, signal: $controller->signal, apiKey: $this->key($model)),
            );

            $seen = 0;

            foreach ($stream as $event) {
                if ((new ReflectionClass($event))->getShortName() === 'TextDeltaEvent' && ++$seen === 3) {
                    $controller->abort('the live harness pressed escape');
                }
            }

            return $stream->result()->await();
        });

        if ($message->stopReason !== StopReason::Aborted) {
            return "it ended as {$message->stopReason->value} rather than aborted";
        }

        // Upstream's rule and its documented exception: the two OpenAI shapes only report usage in
        // the final chunk, so an aborted turn there has none to report and that is not a failure.
        $late = in_array($model->api->value, ['openai-completions', 'openai-responses'], true);

        if ($late) {
            return $message->usage->input === 0 ? 'ok (no usage before the end, as upstream records)' : 'ok (usage arrived early)';
        }

        return $message->usage->input > 0 ? 'ok' : 'usage was reported early but did not survive the abort';
    }

    private function empty(Model $model): string
    {
        // Left open by the canned-server read: Anthropic drops the message and sends an empty
        // conversation, the other two send a part holding the empty string. What each provider
        // *says* to that is the part only this can answer, and **both answers are legitimate** —
        // upstream's own assertion is deliberately tolerant. So this records the wording rather
        // than judging it. What it does judge is that a refusal here is not mistaken for something
        // else: a 400 about an empty conversation must not read as an overflow, or an empty prompt
        // would set a compaction going.
        $message = $this->answer($model, new Context([new UserMessage('')]));

        if ($message->stopReason !== StopReason::Error) {
            return 'ok, answered anyway';
        }

        if (Overflow::happened($message, $model->contextWindow)) {
            return 'an empty conversation was read as an overflow: ' . self::oneLine((string) $message->errorMessage);
        }

        return 'ok, refused: ' . self::oneLine((string) $message->errorMessage);
    }

    private function usage(Model $model): string
    {
        // Anthropic reports the input at the start of a stream and the output at the end, and the
        // final event may mention neither — which is the `?? 0` that wiped out every input count
        // until `update()` learned to merge. Live, a finished turn has to have both.
        $message = $this->answer($model, new Context([new UserMessage('Say: one two three four five')]));

        if ($message->stopReason === StopReason::Error) {
            return (string) $message->errorMessage;
        }

        $usage = $message->usage;

        if ($usage->input === 0 || $usage->output === 0) {
            return sprintf('input=%d output=%d — one of them went missing', $usage->input, $usage->output);
        }

        if ($usage->totalTokens < $usage->input + $usage->output) {
            return sprintf('total=%d is less than input=%d + output=%d', $usage->totalTokens, $usage->input, $usage->output);
        }

        return $usage->cost->total > 0.0
            ? 'ok'
            : 'ok (the model is priced at zero)';
    }

    private function truncated(Model $model): string
    {
        // One token of room, so the answer cannot finish. Every provider has its own word for it
        // and `Stream` maps them all onto one `StopReason`; a provider whose word changed would
        // otherwise come back as a plain stop and look like a model with nothing to say.
        $message = Async::run(function () use ($model): AssistantMessage {
            $stream = Stream::simple(
                $model,
                new Context([new UserMessage('Write a paragraph about the sea.')]),
                new SimpleStreamOptions(maxTokens: 1, apiKey: $this->key($model)),
            );

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        if ($message->stopReason === StopReason::Error) {
            // Some providers refuse a one-token budget outright, which is an answer too.
            return 'ok, refused a one-token budget: ' . self::oneLine((string) $message->errorMessage);
        }

        return $message->stopReason === StopReason::Length
            ? 'ok'
            : "it came back as {$message->stopReason->value} rather than length";
    }

    private function caching(Model $model): string
    {
        // pig puts `cache_control: ephemeral` on the last block of an Anthropic request and on
        // nothing else, so this is Anthropic's alone until another provider gets one. The claim
        // being checked is not that the field is *sent* — a canned server shows that — but that the
        // far end acts on it: a write on the first call, a read on the second.
        if ($model->provider !== 'anthropic') {
            return '--';
        }

        // Long enough to be worth caching: Anthropic has a minimum below which it does nothing.
        $preamble = str_repeat('The archivist catalogued every letter in the collection by hand. ', 400);
        $context = new Context([new UserMessage($preamble . ' Say the single word: filed')]);

        $first = $this->answer($model, $context);

        if ($first->stopReason === StopReason::Error) {
            return (string) $first->errorMessage;
        }

        $second = $this->answer($model, $context);

        if ($second->stopReason === StopReason::Error) {
            return (string) $second->errorMessage;
        }

        $wrote = $first->usage->cacheWrite + $second->usage->cacheWrite;
        $read = $second->usage->cacheRead;

        if ($wrote === 0 && $read === 0) {
            return sprintf(
                'nothing was cached (write %d/%d, read %d) — the prompt may be under the minimum',
                $first->usage->cacheWrite,
                $second->usage->cacheWrite,
                $read,
            );
        }

        return sprintf('ok (wrote %d, read %d back)', $wrote, $read);
    }

    private function dangling(Model $model): string
    {
        $tool = new Tool('get_weather', 'Look up the weather somewhere.', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);

        // An interrupted turn leaves a call with no result. `TransformMessages` invents one saying
        // so, because every provider rejects the whole conversation otherwise — which is a claim
        // about the providers, and this is where it gets tested.
        $message = $this->answer($model, new Context([
            new UserMessage('What is the weather in Singapore?'),
            new AssistantMessage(
                [new ToolCall('call_abandoned_1', 'get_weather', ['city' => 'Singapore'])],
                $model->api,
                $model->provider,
                $model->id,
                new Pig\Ai\Usage(),
                StopReason::ToolUse,
            ),
            new UserMessage('Never mind, say hello instead.'),
        ], null, [$tool]));

        return $message->stopReason === StopReason::Error
            ? 'refused: ' . self::oneLine((string) $message->errorMessage)
            : 'ok';
    }

    private function overflow(Model $model): string
    {
        // Costs nothing: it is refused before it is billed. `Ai\Utils\Overflow`'s table was built
        // from what each provider says when this happens, and a pattern with no live example
        // beside it is a guess that compacts a conversation which was fine.
        $huge = str_repeat('The quick brown fox jumps over the lazy dog. ', 25_000);
        $message = $this->answer($model, new Context([new UserMessage($huge)]));

        if ($message->stopReason !== StopReason::Error) {
            return 'the provider accepted a prompt larger than its window';
        }

        return Overflow::happened($message, $model->contextWindow)
            ? 'ok'
            : 'not recognised as an overflow: ' . self::oneLine((string) $message->errorMessage);
    }

    // ---- scaffolding ----------------------------------------------------------------------

    private function answer(Model $model, Context $context, ?ReasoningEffort $reasoning = null): AssistantMessage
    {
        return Async::run(function () use ($model, $context, $reasoning): AssistantMessage {
            $stream = Stream::simple($model, $context, new SimpleStreamOptions(
                maxTokens: 512,
                apiKey: $this->key($model),
                reasoning: $reasoning,
            ));

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });
    }

    private function key(Model $model): ?string
    {
        return Async::run(fn (): ?string => $this->auth->apiKey($model->provider));
    }

    private function run(string $label, callable $scenario): void
    {
        Loop::reset();
        $started = microtime(true);

        try {
            $answer = $scenario();
        } catch (Throwable $error) {
            $answer = $error::class . ': ' . self::oneLine($error->getMessage());
        }

        $seconds = microtime(true) - $started;

        if ($answer === '--') {
            printf("  --  %-26s (this model cannot)\n", $label);

            return;
        }

        if (str_starts_with($answer, 'ok')) {
            $this->ok++;
            printf("  ok  %-26s %4.1fs  %s\n", $label, $seconds, $answer === 'ok' ? '' : $answer);

            return;
        }

        $this->no++;
        $this->failures[] = "{$label}: {$answer}";
        printf("  NO  %-26s %4.1fs  %s\n", $label, $seconds, $answer);
    }

    private static function textOf(AssistantMessage $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    private static function oneLine(string $text): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($flat) > 160 ? mb_substr($flat, 0, 157) . '…' : $flat;
    }
}

$wanted = array_slice($argv, 1);
$auth = Auth::discover();
$live = new Live($auth);

foreach (MODELS as $provider => $id) {
    if ($wanted !== [] && !in_array($provider, $wanted, true)) {
        continue;
    }

    $live->provider($provider, $id);
}

// Cross-provider, which needs two keys — every ordered pair that has them would be a lot of calls,
// so this takes the providers named (or found) and walks consecutive pairs.
$have = array_values(array_filter(
    array_keys(MODELS),
    static fn (string $provider): bool => ($wanted === [] || in_array($provider, $wanted, true))
        && $auth->hasKeyFor($provider),
));

for ($i = 1; $i < count($have); $i++) {
    $live->handoff($have[$i - 1], $have[$i]);
}

exit($live->report());
