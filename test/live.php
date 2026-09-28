<?php

declare(strict_types=1);

/**
 * The providers, against the real endpoints.
 *
 * `php test/live.php [provider|provider/model-id …]` — every provider a key can be found for, or
 * only the ones named, and `google/gemini-2.5-pro` to try a model the table below does not name.
 * Each scenario prints one line: `ok`, `no` with what went wrong, or `--` when the model cannot do
 * that thing.
 *
 * **The example is deliberately an id the pin guarantees** rather than the newest model anybody
 * would actually want to test: an id written into documentation dates the documentation, and the
 * one place it must not be stale is the line telling somebody how to reach a model the registry is
 * too old for.
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
use Pig\CodingAgent\CustomModels;

/**
 * One cheap model per provider.
 *
 * Cheap on purpose: this asks whether the protocol works, and the protocol does not get better on
 * a larger model. What each model can do is read off `Ai\Models` rather than assumed, so a
 * scenario a model cannot reach comes back `--` rather than as a failure.
 *
 * **A `404 The model … does not exist` here is the registry being older than the provider**, not a
 * wrong id in this table: `Ai\Models` is 166 models pinned at the anchor commit, 2026-01-02, and
 * a provider that has retired one since answers 404 for a row pig still offers in `--list-models`.
 * Groq did exactly that to `llama-3.3-70b-versatile`. So the fix for such a line is another id —
 * on the command line rather than in this table, since the table is the cheap default and the
 * question is usually about one model. The thing worth knowing is that `--list-models` has the same
 * staleness — see the note in CLAUDE.md, because pinning the registry is deliberate and this is its
 * price. A model named on the command line still has to be *in* the registry: this harness reads
 * the window, the output cap and whether it can think off `Ai\Models`, and a model it has never
 * heard of is reached the way a session reaches one, through `models.json`.
 */
const MODELS = [
    'anthropic' => 'claude-sonnet-4-5',
    'openai' => 'gpt-5-mini',
    'google' => 'gemini-2.5-flash',
    'groq' => 'llama-3.1-8b-instant',
    'xai' => 'grok-3-fast',
    'cerebras' => 'gpt-oss-120b',
    'zai' => 'glm-4.5-flash',
    'mistral' => 'mistral-small-latest',
    'github-copilot' => 'gpt-4.1',
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

    /** @param array<string, string> $offered provider => the model id to use for it */
    public function __construct(
        private readonly Auth $auth,
        private readonly array $offered = MODELS,
    ) {
    }

    public function provider(string $provider, string $id): void
    {
        $model = Models::find($provider, $id);

        if ($model === null) {
            // **Naming what to do, because the commonest way to get here is not a typo.**
            // `Ai\Models` is pinned at the anchor commit, so every model released since is
            // missing from it and this line is what a current model id gets. The message used
            // to stop at "no such model", which is true and leaves somebody looking for the
            // mistake in what they typed.
            printf("\n%s — no such model in the registry: %s\n", $provider, $id);
            printf("      the registry is pinned at the anchor commit (2026-01-02), so a model\n");
            printf("      released since is not in it. Declare it in ~/.pig/models.json under\n");
            printf("      this provider and it is reachable here and from bin/pig.\n");

            return;
        }

        if (!$this->auth->hasKeyFor($provider)) {
            // The id as well, because a model named on the command line is otherwise skipped
            // without ever saying whether the name was the one that took effect.
            printf("\n%s / %s — no key, skipped\n", $provider, $id);

            return;
        }

        printf("\n%s / %s   (reasoning: %s, images: %s)\n", $provider, $id,
            $model->reasoning ? 'yes' : 'no', $model->acceptsImages() ? 'yes' : 'no');

        // **`text` is the pre-flight.** Groq answered nine scenarios with the same
        // `404 The model … does not exist`, and two of them called it `ok` — the empty-message and
        // budget cases accept a refusal as their answer and cannot tell one refusal from another.
        // So the simplest thing the plainest scenario already does is made to count: if the model
        // cannot answer "say hello", nothing after it means anything.
        if (!$this->run('text', fn (): string => $this->text($model))) {
            printf("      the rest is skipped — nothing below this can mean anything\n");

            return;
        }

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
        $source = Models::find($from, $this->offered[$from] ?? '');
        $target = Models::find($to, $this->offered[$to] ?? '');

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
        $message = null;

        Async::run(function () use ($model, &$seen, &$message): void {
            // **512 and not 64.** A reasoning model spends the budget on thinking first, so a tight
            // one produces a turn that stops at the limit with no text in it at all — which the
            // first version of this reported as "no text arrived as deltas", a sentence that reads
            // like a protocol fault and was a budget.
            $stream = Stream::simple($model, new Context([new UserMessage('Count: one two three')]),
                new SimpleStreamOptions(maxTokens: 512, apiKey: $this->key($model)));

            foreach ($stream as $event) {
                $seen[] = (new ReflectionClass($event))->getShortName();
            }

            $message = $stream->result()->await();
        });

        $first = $seen[0] ?? '';
        $last = $seen[count($seen) - 1] ?? '';

        if ($first !== 'StartEvent') {
            return "the first event was {$first}, not StartEvent";
        }

        if ($last !== 'DoneEvent') {
            return "the last event was {$last}, not DoneEvent";
        }

        if (in_array('TextDeltaEvent', $seen, true)) {
            return 'ok';
        }

        // Say enough to tell a protocol fault from a turn that had nothing to say.
        return sprintf(
            'no text arrived as deltas — stopped as %s, blocks: %s, events: %s',
            $message?->stopReason->value ?? 'nothing',
            implode(', ', array_map(
                static fn (object $b): string => (new ReflectionClass($b))->getShortName(),
                $message?->content ?? [],
            )) ?: 'none',
            implode(' ', array_unique($seen)),
        );
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

        // A refusal is this scenario's answer only if it is *about* the message. A 404 for the
        // model or a 401 for the key is the provider saying something else entirely, and counting
        // it here is how Groq's missing model came back as `ok` twice.
        if (!self::isAboutTheRequest($message)) {
            return 'refused for an unrelated reason: ' . self::oneLine((string) $message->errorMessage);
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
        // Sixteen tokens of room, so the answer cannot finish. One was the first try and the
        // Responses API refuses it outright — `max_output_tokens` has a minimum of 16 — so the
        // scenario was answering a question about validation rather than about stop reasons.
        //
        // Every provider has its own word for a truncated answer and `Stream` maps them all onto
        // one `StopReason`; a provider whose word changed would otherwise come back as a plain stop
        // and look like a model with nothing to say.
        $message = Async::run(function () use ($model): AssistantMessage {
            $stream = Stream::simple(
                $model,
                new Context([new UserMessage('Write a paragraph about the sea.')]),
                new SimpleStreamOptions(maxTokens: 16, apiKey: $this->key($model)),
            );

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        if ($message->stopReason === StopReason::Error) {
            // A provider that refuses the budget itself is an answer too — but only if that is
            // what it refused. See `isAboutTheRequest()`.
            return self::isAboutTheRequest($message)
                ? 'ok, refused a sixteen-token budget: ' . self::oneLine((string) $message->errorMessage)
                : 'refused for an unrelated reason: ' . self::oneLine((string) $message->errorMessage);
        }

        if ($message->stopReason === StopReason::Length) {
            return 'ok';
        }

        // **The output count is what tells the two failures apart**, and the first version of this
        // did not report it: a mapping that missed this provider's word for truncation ends the
        // turn at about the budget, and a budget the endpoint *ignored* ends it wherever the model
        // chose. The second is `OpenAiCompat::maxTokensField` naming a field the endpoint does not
        // read — `max_completion_tokens` where it wants `max_tokens` — which means no pig request
        // to it has ever been bounded.
        return sprintf(
            'it came back as %s after %d output tokens against a budget of 16 — %s',
            $message->stopReason->value,
            $message->usage->output,
            $message->usage->output > 24
                ? 'the budget was ignored, so the field name is wrong for this endpoint'
                : 'the budget held, so it is the stop reason that is not being mapped',
        );
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
        //
        // **Sized from the model's own window**, which the first version of this was not: a fixed
        // 1.1MB string overflows Anthropic's 200k and sits comfortably inside gpt-5-mini's 400k and
        // Gemini's 1M, so two providers came back "accepted a prompt larger than its window" when
        // the prompt was nothing of the kind.
        //
        // Single letters rather than prose, because the estimate has to err the *safe* way. Ordinary
        // English is about 4.5 characters to a token, so `Compaction`'s four-per-token would size a
        // prompt **under** the window and this would report the wrong thing again; spaced letters
        // are close to two, so three characters per token of window is comfortably over it — and it
        // keeps a 1M-token model's prompt at 3MB rather than the 8MB prose would need to upload.
        $run = 'a b c d e f g h i j ';
        $huge = str_repeat($run, (int) ceil($model->contextWindow * 3 / strlen($run)));
        $message = $this->answer($model, new Context([new UserMessage($huge)]));

        // **Two shapes, and answering is one of them.** z.ai takes the oversized request, answers,
        // and bills for more input than the window holds — `Overflow::happened()` was given the
        // window argument for exactly that, and the first version of this scenario returned before
        // ever reaching it, so a provider of that kind was reported as a failure with no numbers.
        if (Overflow::happened($message, $model->contextWindow)) {
            // The wording is printed on the way past, because `Overflow`'s table is a row per
            // provider *with the sentence it was written for beside it* — and a row can match for
            // the wrong reason. Three of the twelve patterns are generic (`context length
            // exceeded`, `too many tokens`, `token limit exceeded`), so a provider whose own
            // phrasing has drifted still comes back `ok` while its specific row has quietly
            // stopped matching. Reading the sentence is what tells the two apart.
            return $message->stopReason === StopReason::Error
                ? 'ok, refused: ' . self::oneLine((string) $message->errorMessage)
                : sprintf('ok, the silent kind (answered, billed %d against a %d window)',
                    $message->usage->input + $message->usage->cacheRead, $model->contextWindow);
        }

        if ($message->stopReason === StopReason::Error) {
            return 'not recognised as an overflow: ' . self::oneLine((string) $message->errorMessage);
        }

        // Neither refused nor billed past the window: the far end dropped what did not fit. Worth
        // reporting as what it is rather than as a failure — pig's auto-compaction is driven by
        // its own count against the window, not by an overflow signal, so a provider that
        // truncates silently still compacts on time. What it loses is the late warning.
        return sprintf(
            'ok, silently truncated (sent ~%dk characters, billed %d against a %d window)',
            (int) round(strlen($huge) / 1_000),
            $message->usage->input + $message->usage->cacheRead,
            $model->contextWindow,
        );
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

    /** Whether it passed, so a caller can stop when the ground has gone. */
    private function run(string $label, callable $scenario): bool
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

            return true;
        }

        if (str_starts_with($answer, 'ok')) {
            $this->ok++;
            printf("  ok  %-26s %4.1fs  %s\n", $label, $seconds, $answer === 'ok' ? '' : $answer);

            return true;
        }

        $this->no++;
        $this->failures[] = "{$label}: {$answer}";
        printf("  NO  %-26s %4.1fs  %s\n", $label, $seconds, $answer);

        return false;
    }

    /**
     * Whether a refusal is about what was sent rather than about the account or the model.
     *
     * Two scenarios treat a refusal as their answer — an empty message and a one-line budget — so
     * both need to know the difference. A 400 is the provider judging the request; a 401, 403 or
     * 404 is it saying the key is wrong or the model is not there, which is not an answer to
     * anything either scenario asked. Groq answered nine scenarios with the same
     * `404 The model … does not exist` and two of them printed `ok`.
     */
    private static function isAboutTheRequest(AssistantMessage $message): bool
    {
        return preg_match('/ returned (401|403|404):/', (string) $message->errorMessage) !== 1;
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

// An argument is a provider, or `provider/model-id` to try a model `MODELS` does not name. Split
// on the **first** slash: a provider name never contains one and a model id can (OpenRouter's are
// `vendor/name`).
$wanted = [];

/** @var array<string, string> $named provider => the id that was typed for it */
$named = [];

foreach (array_slice($argv, 1) as $argument) {
    $at = strpos($argument, '/');
    $provider = $at === false ? $argument : substr($argument, 0, $at);

    $wanted[] = $provider;

    if ($at !== false) {
        $named[$provider] = substr($argument, $at + 1);
    }
}

$auth = Auth::discover();

// `models.json` too, the way `bin/pig` does it — an endpoint somebody declared themselves is the
// one part of the registry with no live coverage at all, and it is also the only way to reach a
// provider pig ships no entry for. `install()` is what tells `Auth` where that provider's key
// comes from, so a declared `apiKey` naming an environment variable resolves here exactly as it
// does in a session.
$custom = CustomModels::discover();
$custom->install($auth);

foreach ($custom->problems as $problem) {
    printf("models.json: %s\n", $problem);
}

Models::register($custom->models);

// Every provider with a table of its own, then anything `models.json` added — by its first model,
// since a declared provider has no obvious "cheapest" and whoever wrote the file chose them all.
$offered = MODELS;

foreach ($custom->models as $model) {
    $offered[$model->provider] ??= $model->id;
}

// What was typed wins, and adds a provider neither source offered — the same order every other
// choice in pig follows. `=` rather than `??=` for exactly that reason.
foreach ($named as $provider => $id) {
    $offered[$provider] = $id;
}

$live = new Live($auth, $offered);

// A provider nobody has heard of is said so rather than matching nothing: before this, a typo in
// the one argument this script takes printed a summary of zero scenarios and no reason for it.
foreach ($wanted as $provider) {
    if (!array_key_exists($provider, $offered)) {
        printf("\n%s — no such provider. There is %s.\n", $provider, implode(', ', array_keys($offered)));
    }
}

foreach ($offered as $provider => $id) {
    if ($wanted !== [] && !in_array($provider, $wanted, true)) {
        continue;
    }

    $live->provider($provider, $id);
}

// Cross-provider, which needs two keys — every ordered pair that has them would be a lot of calls,
// so this takes the providers named (or found) and walks consecutive pairs.
$have = array_values(array_filter(
    array_keys($offered),
    static fn (string $provider): bool => ($wanted === [] || in_array($provider, $wanted, true))
        && $auth->hasKeyFor($provider),
));

for ($i = 1; $i < count($have); $i++) {
    $live->handoff($have[$i - 1], $have[$i]);
}

exit($live->report());
