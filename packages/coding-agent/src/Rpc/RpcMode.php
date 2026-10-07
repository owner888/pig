<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Pig\Agent\QueueMode;
use Pig\Agent\AgentEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\ImageContent;
use Pig\Ai\Models;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionCodec;
use Pig\CodingAgent\Settings;
use Throwable;

/**
 * The second way in: JSON lines on standard input, JSON lines on standard output.
 *
 * For an editor, or anything else that wants the agent without a terminal. One command per
 * line in, and three kinds of line out: a `response` to a command, an `event` as the agent
 * works, and a `hook_ui_request` when a hook wants to ask something.
 *
 * ```
 *   {"id":"1","type":"prompt","message":"what does bin/pig do?"}
 *   {"id":"1","type":"response","command":"prompt","success":true}
 *   {"type":"message_start","message":{...}}
 *   {"type":"message_update","message":{...},"delta":{"type":"text_delta","contentIndex":0,"delta":"It "}}
 *   ...
 * ```
 *
 * `id` is the host's, echoed back on the response so replies can be matched to commands.
 * It is optional, and a command without one gets a response without one.
 *
 * **Standard input goes on the same event loop as the model's socket**, through
 * `Loop::onReadable()`. That is what makes a hook's `confirm()` work over the wire: the
 * handler's fiber parks on a `Deferred`, the loop keeps reading, and the reply line resumes
 * it. `readline` would have blocked the loop it needs.
 *
 * Ported from upstream's `modes/rpc/rpc-mode.ts`. Its `rpc-types.ts` has no counterpart:
 * that file is TypeScript types for the wire shape, and the wire shape here is this class's
 * docblock plus `RpcEvents`. Its `rpc-client.ts` is `Rpc\RpcClient` — which exists for the reason
 * this docblock used to give for not having it. "A host writes JSON lines in whatever language it
 * is in" is true, and it left the protocol with no test *as a protocol*: `RpcModeTest` hands this
 * class two streams, so nothing checked the binary starting, a line crossing a pipe, or a field
 * name. The first thing `RpcClient` found was that `bin/pig --mode rpc` could not start at all.
 *
 * **Four of upstream's commands are not here**, and the reasons divide in two:
 *
 * - `queue_message` and `set_queue_mode`: the anchor commit split the agent's one queue into
 *   `steer()` and `followUp()`, so there is no single queue to add to or set a mode on.
 *   `steer` and `follow_up` are the two commands that replace them, which is honest rather
 *   than guessing which one a `queue_message` meant.
 * - `branch`: upstream forks a conversation into a second session file. pig branches inside
 *   one — `go_to`, with `get_branch` for the points to go to.
 * - `export_html`'s `outputPath` is honoured, but the command is `export` here.
 */
final class RpcMode
{
    /** How much to take off standard input at a time. */
    private const int CHUNK = 65_536;

    /** Everything read and not yet split into a line. */
    private string $buffer = '';

    private ?string $watcher = null;

    private readonly RpcUi $ui;

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /**
     * @param resource|null $in  standard input unless a test hands in a pipe
     * @param resource|null $out standard output, likewise
     */
    public function __construct(
        private readonly AgentSession $session,
        private readonly string $cwd,
        private readonly ?HookRunner $hooks = null,
        private readonly ?CustomToolSet $customTools = null,
        private readonly ?Settings $settings = null,
        private readonly ?Auth $auth = null,
        $in = null,
        $out = null,
    ) {
        $this->in = $in ?? STDIN;
        $this->out = $out ?? STDOUT;
        $this->ui = new RpcUi($this->send(...));
    }

    /**
     * Wire everything up and serve until standard input closes.
     *
     * Blocking, like `InteractiveMode::run()`: it is the program.
     */
    public function run(): void
    {
        $this->start();

        try {
            Loop::get()->run();
        } finally {
            $this->stop();
        }
    }

    /** @internal for tests, which drive the loop themselves */
    public function start(): void
    {
        stream_set_blocking($this->in, false);

        $this->session->subscribe($this->onAgentEvent(...));

        $this->session->setMode('rpc');
        $this->session->onShutdownRequest(function (): void {
            Loop::get()->defer(fn () => $this->stop());
        });

        // Each mode wires its own UI; this one's is the protocol.
        $this->hooks?->initialize(
            getModel: fn () => $this->session->model(),
            isIdle: fn (): bool => !$this->session->isStreaming(),
            abort: function (): void {
                $this->session->abort();
            },
            hasQueuedMessages: fn (): bool => $this->session->queued() !== [],
            signal: fn () => $this->session->signal(),
            ui: $this->ui,
            send: function (HookMessage $message, bool $triggerTurn): void {
                $this->session->sendHookMessage($message, $triggerTurn);
            },
            note: function (string $customType, mixed $data): void {
                $this->session->appendHookEntry($customType, $data);
            },
            getApiKey: fn (Model $m) => $this->session->keyFor($m),
            setSessionName: fn (string $name) => $this->session->setSessionName($name),
            getSessionName: fn () => $this->session->getSessionName(),
        );

        $this->hooks?->onError(function (HookError $error): void {
            $this->send([
                'type' => 'hook_error',
                'hookPath' => $error->hookPath,
                'event' => $error->event,
                'error' => $error->error,
            ]);
        });

        $this->customTools?->withUi($this->ui);
        $this->customTools?->withContext(
            fn () => $this->hooks?->context() ?? new HookContext($this->cwd, ui: $this->ui, hasUi: true),
        );

        $this->watcher = Loop::get()->onReadable($this->in, $this->onReadable(...));

        $this->hooks?->emit(new SessionStartEvent());
        $this->report($this->customTools?->notify('start') ?? []);
    }

    public function stop(): void
    {
        if ($this->watcher !== null) {
            Loop::get()->cancel($this->watcher);
            $this->watcher = null;
        }

        $this->hooks?->emit(new SessionShutdownEvent());
        $this->report($this->customTools?->notify('shutdown') ?? []);
        $this->session->dispose();
    }

    // ---- reading -------------------------------------------------------------------------

    /**
     * Take whatever arrived and act on every whole line in it.
     *
     * Buffered because a read is bytes, not lines: a long prompt can arrive in pieces and a
     * fast host can send three commands in one. End of input ends the loop, which is how a
     * host says it is finished — it closes the pipe.
     */
    private function onReadable(): void
    {
        $chunk = fread($this->in, self::CHUNK);

        if ($chunk === false || ($chunk === '' && feof($this->in))) {
            Loop::get()->stop();

            return;
        }

        $this->buffer .= $chunk;

        while (($break = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $break);
            $this->buffer = substr($this->buffer, $break + 1);

            if (trim($line) !== '') {
                try {
                    $this->line($line);
                } catch (Throwable $e) {
                    $this->send([
                        'type' => 'response',
                        'command' => 'dispatch',
                        'success' => false,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * One line: a command, or a reply to a question a hook asked.
     *
     * Each command runs in a fiber of its own, because several of them suspend — `prompt`
     * runs a whole turn, `compact` and a summarising `go_to` call the model, and anything
     * that reaches a hook may park on a dialog. Running them here would suspend the loop
     * that read the line.
     */
    private function line(string $line): void
    {
        $command = json_decode($line, true);

        if (!is_array($command)) {
            $this->send(['type' => 'response', 'command' => 'parse', 'success' => false, 'error' => 'not a JSON object']);

            return;
        }

        if (($command['type'] ?? null) === 'hook_ui_response') {
            $this->ui->answer($command);

            return;
        }

        Async::spawn(function () use ($command): void {
            $id = isset($command['id']) ? (string) $command['id'] : null;
            $type = (string) ($command['type'] ?? '');

            try {
                $data = $this->dispatch($type, $command);
            } catch (Throwable $error) {
                // Every failure is a response rather than a crash: a host asking for
                // something impossible should be told, not disconnected.
                $this->send(array_filter([
                    'id' => $id,
                    'type' => 'response',
                    'command' => $type,
                    'success' => false,
                    'error' => $error->getMessage(),
                ], static fn (mixed $v): bool => $v !== null));

                return;
            }

            $response = array_filter([
                'id' => $id,
                'type' => 'response',
                'command' => $type,
                'success' => true,
            ], static fn (mixed $v): bool => $v !== null);

            $this->send($data === null ? $response : [...$response, 'data' => $data]);
        });
    }

    /**
     * Do what the command says, and answer with its data or nothing.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     * @throws \RuntimeException for anything a host got wrong
     */
    private function dispatch(string $type, array $command): ?array
    {
        return match ($type) {
            'prompt' => $this->prompt($command),
            'steer' => $this->queue($command, steer: true),
            'follow_up' => $this->queue($command, steer: false),
            'abort' => $this->abort(),

            'get_state' => $this->state(),
            'get_messages' => ['messages' => $this->encoded($this->session->messages())],
            'get_last_assistant_text' => ['text' => $this->session->lastAssistantText()],
            'get_session_stats' => $this->stats(),

            // "Available" is upstream's word for "there is a key for it", not "pig knows of it":
            // a host drawing a model menu from this list would otherwise offer twenty models and
            // have nineteen of them fail. Without an `Auth` — which only a test builds this
            // without — every model there is, as before.
            'get_available_models' => [
                'models' => array_map(self::model(...), $this->auth?->availableModels() ?? Models::all()),
            ],
            'set_model' => $this->setModel($command),
            'cycle_model' => $this->cycleModel($command),

            'set_queue_mode' => $this->setQueueMode($command),

            'set_thinking_level' => $this->setThinking($command),
            'cycle_thinking_level' => ['level' => $this->session->cycleThinkingLevel()?->value],

            'compact' => $this->compact($command),
            'set_auto_compaction' => $this->setAutoCompaction($command),

            'set_auto_retry' => $this->setAutoRetry($command),
            'abort_retry' => $this->abortRetry(),

            'bash' => $this->bash($command),
            'abort_bash' => $this->abortBash(),

            'get_branch' => $this->branch(),
            'go_to' => $this->goTo($command),
            'new_session' => $this->newSession(),
            'switch_session' => $this->switchSession($command),
            'export' => $this->export($command),

            // Four the web shell used to answer over HTTP from its one session and cannot now,
            // because it has none: they are the child's. Each is the terminal's slash command
            // made into a command, and `/diff --staged` is `{type: "diff", args: "--staged"}`.
            'doctor' => $this->doctor(),
            'diff' => $this->diff($command),
            'bug' => $this->bug($command),
            'export_markdown' => $this->exportMarkdown($command),

            default => throw new \RuntimeException("Unknown command: {$type}"),
        };
    }

    // ---- prompting -----------------------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function prompt(array $command): ?array
    {
        // Read before the spawn, not inside it. A missing `message` is the host's mistake and
        // belongs on this command's response — thrown inside the fiber it would arrive as a
        // second, id-less failure *after* the success this method had already returned, so a
        // host would be told both that the prompt was accepted and that it was not.
        $message = self::text($command, 'message');
        $id = isset($command['id']) ? (string) $command['id'] : null;
        $images = [];

        foreach ((array) ($command['images'] ?? []) as $image) {
            if (is_array($image) && isset($image['data'], $image['mimeType'])) {
                $images[] = new ImageContent((string) $image['data'], (string) $image['mimeType']);
            }
        }

        // The response goes out before the turn finishes — the events are the turn. A host
        // that waited for the response to mean "done" would be waiting for the wrong thing,
        // which is why `agent_end` exists.
        Async::spawn(function () use ($message, $images, $id): void {
            try {
                $this->session->prompt($message, $images, 'rpc');
            } catch (Throwable $error) {
                $this->send(array_filter([
                    'id' => $id,
                    'type' => 'response',
                    'command' => 'prompt',
                    'success' => false,
                    'error' => $error->getMessage(),
                ], static fn (mixed $v): bool => $v !== null));
            }
        });

        return null;
    }

    /** @param array<string, mixed> $command */
    private function queue(array $command, bool $steer): ?array
    {
        $message = self::text($command, 'message');

        if ($steer) {
            $this->session->steer($message, 'rpc');
        } else {
            $this->session->followUp($message, 'rpc');
        }

        return null;
    }

    private function abort(): ?array
    {
        $this->session->abort()->await();

        return null;
    }

    // ---- state ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function state(): array
    {
        $model = $this->session->model();

        return [
            'model' => $model === null ? null : self::model($model),
            'thinkingLevel' => $this->session->thinkingLevel()->value,
            'isStreaming' => $this->session->isStreaming(),
            // Upstream's `RpcSessionState` declares both of these and pig's answer carried
            // neither: a host had no way to know a summarisation was running, and could set the
            // queue mode without ever being able to read it back. `sessionId` is the third field
            // on that interface and stays out — pig has no session identity apart from the file,
            // which `sessionFile` already is.
            'isCompacting' => $this->session->isCompacting(),
            'queueMode' => $this->session->queueMode()->value,
            'isBashRunning' => $this->session->isBashRunning(),
            'sessionFile' => $this->session->store()?->path,
            'isPersisted' => $this->session->store()?->isPersisted() ?? false,
            'messageCount' => count($this->session->messages()),
            'queuedMessageCount' => count($this->session->queued()),
            'contextTokens' => $this->session->contextTokens(),
            'autoCompactionEnabled' => $this->settings?->compactionEnabled() ?? true,
            'autoRetryEnabled' => $this->settings?->retryEnabled() ?? true,
            'isRetrying' => $this->session->isRetrying(),
            // Three fields upstream's state has no need of and the web shell's tab bar does: a
            // tab is labelled by the session's name or its opening line, and grouped by
            // directory. Reading them off the file on the shell side would mean the shell
            // knowing the session format; the child already does.
            'cwd' => $this->session->cwd(),
            'sessionName' => $this->session->getSessionName(),
            'opening' => $this->opening(),
        ];
    }

    /** The first thing the person said, for a label — `SessionManager::describe()`'s rule. */
    private function opening(): string
    {
        foreach ($this->session->messages() as $message) {
            if ($message instanceof \Pig\Ai\UserMessage) {
                foreach ($message->content as $block) {
                    if ($block instanceof \Pig\Ai\TextContent && trim($block->text) !== '') {
                        return mb_substr((string) preg_replace('/\s+/u', ' ', trim($block->text)), 0, 60);
                    }
                }
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $stats = $this->session->stats();

        return [
            'userMessages' => $stats->userMessages,
            'assistantMessages' => $stats->assistantMessages,
            'toolCalls' => $stats->toolCalls,
            'toolResults' => $stats->toolResults,
            'totalMessages' => $stats->totalMessages,
            'input' => $stats->input,
            'output' => $stats->output,
            'cacheRead' => $stats->cacheRead,
            'cacheWrite' => $stats->cacheWrite,
            'totalTokens' => $stats->totalTokens(),
            'cost' => $stats->cost,
        ];
    }

    // ---- the model -----------------------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function setModel(array $command): array
    {
        $id = self::text($command, 'modelId');
        $provider = isset($command['provider']) && $command['provider'] !== '' ? (string) $command['provider'] : null;

        // A combined `provider/id` with no separate provider is the other spelling of the same
        // request, and a host sending it got "No such model" for a model that exists.
        if ($provider === null && str_contains($id, '/')) {
            [$provider, $id] = explode('/', $id, 2);
        }

        // **The provider used to be ignored here** — the same bug `HttpServer::resolveModel()`
        // records: `Models::get()` compares *bare* ids, so `"provider/id"` matched nothing and the
        // `?? Models::get($id)` fallback resolved the bare id to the direct provider by
        // `Models::RESOLD`. A host naming `antigravity` got Google's public model of the same
        // name, with `success: true`. Found when the web UI started going through this path.
        $model = $provider === null ? Models::get($id) : Models::find($provider, $id);

        if ($model === null) {
            throw new \RuntimeException('No such model: ' . ($provider === null ? $id : "{$provider}/{$id}"));
        }

        $level = isset($command['thinkingLevel']) ? ThinkingLevel::tryFrom((string) $command['thinkingLevel']) : null;
        $this->session->setModel($model, $level);

        return self::model($model);
    }

    /**
     * The next model along, which is ctrl+p in the terminal.
     *
     * Ported after all: the docblock at the top of this file said `get_available_models` and
     * `set_model` were what it is made of, and that was true for a host — but not for pig, which
     * had the keys reserved in the editor and no method behind them. A host doing the rotation by
     * hand would be a second implementation of it, and this audit's whole subject is what that
     * costs — which is why the rotation, the key check and the thinking level a `--models` scope
     * entry carries are all `AgentSession::cycleModel()`'s.
     *
     * `null` for a machine with one usable model, or one model in the scope, as upstream answers.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     */
    private function cycleModel(array $command): ?array
    {
        $choice = $this->session->cycleModel(
            $this->auth?->availableModels() ?? Models::all(),
            isset($command['direction']) && $command['direction'] === 'backward',
        );

        if ($choice === null) {
            return null;
        }

        return ['model' => self::model($choice->model), 'thinkingLevel' => $choice->thinking->value];
    }

    /** @param array<string, mixed> $command */
    /**
     * Whether messages typed mid-run are handed over together or one at a time.
     *
     * Upstream's command, and the one the left-out table used to explain away: the anchor commit's
     * queue split accounts for `queue_message` — `steer` and `follow_up` replace it — and accounts
     * for nothing about the *mode*. `get_state` reports it and `/settings` has a row for it, so
     * without this a host could read the fact and never change it.
     *
     * @param array<string, mixed> $command
     */
    private function setQueueMode(array $command): ?array
    {
        $wanted = self::text($command, 'mode');

        $this->session->setQueueMode(
            QueueMode::tryFrom($wanted)
                ?? throw new \RuntimeException("No such queue mode: '{$wanted}'. There is "
                    . implode(' and ', array_map(static fn (QueueMode $m): string => $m->value, QueueMode::cases())) . '.'),
        );

        return null;
    }

    private function setThinking(array $command): ?array
    {
        $wanted = ThinkingLevel::tryFrom(self::text($command, 'level'))
            ?? throw new \RuntimeException('No such thinking level: ' . self::text($command, 'level'));

        if (!in_array($wanted, $this->session->availableThinkingLevels(), true)) {
            throw new \RuntimeException("This model cannot think at '{$wanted->value}'.");
        }

        $this->session->setThinkingLevel($wanted);

        return null;
    }

    // ---- making room ---------------------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function compact(array $command): array
    {
        $instructions = isset($command['customInstructions'])
            ? (string) $command['customInstructions']
            : null;

        $summary = $this->session->compact($instructions === '' ? null : $instructions);

        return [
            'cancelled' => $summary === null,
            'summary' => $summary === null ? null : SessionCodec::encode($summary),
        ];
    }

    /** @param array<string, mixed> $command */
    private function setAutoCompaction(array $command): ?array
    {
        $this->settings?->set('compaction.enabled', (bool) ($command['enabled'] ?? true));

        return null;
    }

    // ---- waiting out a provider -----------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function setAutoRetry(array $command): ?array
    {
        $this->settings?->setRetryEnabled(($command['enabled'] ?? true) === true);

        return null;
    }

    private function abortRetry(): ?array
    {
        $this->session->abortRetry();

        return null;
    }

    // ---- bash ----------------------------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function bash(array $command): array
    {
        $text = self::text($command, 'command');
        $id = isset($command['id']) ? (string) $command['id'] : null;

        // The TUI draws a `!command`'s output as it arrives; a host gets the same through
        // `bash_output` events carrying the command's id, so it can find the card. Without
        // them a long-running command — `flutter run`, `npm run dev` — shows nothing until
        // it is stopped, and "nothing" is what a stuck process looks like.
        $execution = $this->session->executeBash(
            $text,
            ($command['remember'] ?? true) === true,
            function (string $output) use ($id): void {
                $this->send(array_filter([
                    'type' => 'bash_output',
                    'id' => $id,
                    'output' => $output,
                ], static fn ($v) => $v !== null));
            },
        );

        // `encode()` is nullable for a message type it does not know, and `BashExecution` is
        // one it knows. A null here would mean the codec had stopped handling it, which is
        // worth failing loudly over rather than putting an empty object on the wire.
        return SessionCodec::encode($execution)
            ?? throw new \RuntimeException('The codec did not encode the bash execution.');
    }

    private function abortBash(): ?array
    {
        $this->session->abortBash();

        return null;
    }

    // ---- the session ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function branch(): array
    {
        $points = [];

        foreach ($this->session->store()?->branch() ?? [] as $point) {
            $points[] = [
                'entryId' => $point['id'],
                'message' => SessionCodec::encode($point['message']),
                'branches' => $point['branches'],
            ];
        }

        return ['points' => $points];
    }

    /** @param array<string, mixed> $command */
    private function goTo(array $command): array
    {
        $instructions = isset($command['customInstructions']) ? (string) $command['customInstructions'] : null;

        $jump = $this->session->goTo(
            isset($command['entryId']) ? (string) $command['entryId'] : null,
            ($command['summarise'] ?? false) === true,
            $instructions === '' ? null : $instructions,
        );

        $this->report($this->customTools?->notify('tree') ?? []);

        return [
            'moved' => $jump->moved,
            'aborted' => $jump->aborted,
            'summary' => $jump->summary === null ? null : SessionCodec::encode($jump->summary),
            // What was said at the point gone back to, when that point is something somebody said:
            // upstream's `editorText`, and a host with an input box wants it there for the same
            // reason the terminal puts it in the prompt.
            'editorText' => $jump->editorText,
        ];
    }

    /**
     * Throw this conversation away and start another.
     *
     * The hook, the abort and the emptied queue are `AgentSession::startNew()`'s, not this
     * file's: they were missing here and present in the terminal, and one of the two having
     * them is how they went missing. What is left is this mode's own share — telling the host
     * which file it is writing now, and telling the custom tools.
     */
    private function newSession(): array
    {
        $switch = $this->session->startNew();

        if (!$switch->switched) {
            return ['cancelled' => true];
        }

        $this->report($this->customTools?->notify('switch', $switch->previous) ?? []);

        return [
            'cancelled' => false,
            'sessionFile' => $this->session->store()?->path,
            'isPersisted' => $this->session->store()?->isPersisted() ?? false,
        ];
    }

    /**
     * Open another conversation and carry on in it.
     *
     * Three checks were missing here and present in the terminal — the hooks are asked
     * through `mayLeave()` there, and upstream aborts the turn in flight and empties the
     * queue. Over RPC none of that happened: a hook that refused to leave a conversation
     * worked for a person and was ignored for a host, and a switch during a turn left that
     * turn writing into the conversation it had just left. They live in
     * `AgentSession::switchTo()` now, which is what makes them true for both. See CLAUDE.md.
     *
     * @param array<string, mixed> $command
     */
    private function switchSession(array $command): array
    {
        $switch = $this->session->switchTo(self::text($command, 'sessionPath'));

        if (!$switch->switched) {
            // `cancelled` rather than an error: a hook saying no is an answer, not a failure, and
            // upstream returns `false` from the same place.
            return ['cancelled' => true];
        }

        $this->report($this->customTools?->notify('switch', $switch->previous) ?? []);

        return [
            'cancelled' => false,
            'sessionFile' => $this->session->store()?->path,
            'messageCount' => $switch->messages,
            // The model that conversation was on, not the one this process happened to start
            // with: `switchTo()` restored it, and a host asking `get_state` next should agree.
            'model' => $this->session->model() === null ? null : self::model($this->session->model()),
        ];
    }

    /** @param array<string, mixed> $command */
    /** @return array<string, mixed> */
    private function doctor(): array
    {
        $report = \Pig\CodingAgent\Doctor\Doctor::inspect($this->session, $this->auth);

        return [
            'plain' => \Pig\CodingAgent\Doctor\Doctor::renderPlain($report),
            'report' => [
                'php' => $report->php,
                'binaries' => $report->binaries,
                'auth' => $report->auth,
                'proxy' => $report->proxy,
                'session' => $report->session,
            ],
        ];
    }

    /** @param array<string, mixed> $command  @return array<string, mixed> */
    private function diff(array $command): array
    {
        $args = isset($command['args']) ? trim((string) $command['args']) : '';
        $cmd = ['git', 'diff', ...($args === '' ? [] : (preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: []))];
        [$code, $stdout, $stderr] = \Pig\Tui\Process::run($cmd, cwd: $this->session->cwd());

        if ($code !== 0) {
            throw new \RuntimeException('git diff failed: ' . ($stderr !== '' ? trim($stderr) : 'not a git repository'));
        }

        return ['diff' => trim($stdout)];
    }

    /** @param array<string, mixed> $command  @return array<string, mixed> */
    private function bug(array $command): array
    {
        $hint = isset($command['hint']) ? trim((string) $command['hint']) : '';
        $includeTranscript = ($command['includeTranscript'] ?? false) === true;
        $report = \Pig\CodingAgent\BugReport::build($this->session, $this->auth, $hint, $includeTranscript);

        return [
            'path' => \Pig\CodingAgent\BugReport::write($report),
            'issueUrl' => \Pig\CodingAgent\BugReport::issueUrl($hint, $report),
            'report' => $report,
        ];
    }

    /**
     * The session as Markdown, or as a PR description when `format` is `pr` — the latter asks the
     * model, so it costs a turn's worth of tokens and is a `bash`-shaped command in that it can
     * take a while.
     *
     * @param array<string, mixed> $command  @return array<string, mixed>
     */
    private function exportMarkdown(array $command): array
    {
        $format = (string) ($command['format'] ?? 'md');

        if ($format === 'pr') {
            return ['format' => 'pr', 'markdown' => \Pig\CodingAgent\Export\MarkdownExport::generatePrDescription($this->session)];
        }

        $store = $this->session->store()
            ?? throw new \RuntimeException('This session is not being saved, so there is nothing to export.');

        return ['format' => 'md', 'markdown' => \Pig\CodingAgent\Export\MarkdownExport::render($store->messages(), $this->session->cwd())];
    }

    private function export(array $command): array
    {
        $store = $this->session->store()
            ?? throw new \RuntimeException('This session is not being saved, so there is nothing to export.');

        $path = isset($command['outputPath']) ? (string) $command['outputPath'] : '';

        // `defaultPath()` rather than a convention of this file's own: where an export goes
        // when nobody said is a decision, and two places making it are two places to disagree.
        return ['path' => HtmlExport::write(
            $store,
            $path === '' ? HtmlExport::defaultPath($store, $this->cwd) : $path,
        )];
    }

    // ---- writing -------------------------------------------------------------------------

    private function onAgentEvent(AgentEvent $event): void
    {
        $encoded = RpcEvents::encode($event);

        if ($encoded !== null) {
            $this->send($encoded);
        }
    }

    /**
     * One JSON object, one line.
     *
     * `JSON_UNESCAPED_UNICODE` and `JSON_UNESCAPED_SLASHES` for the same reason the session
     * file uses them: a host reading a path or a Chinese sentence out of this should find it
     * written as itself. `JSON_INVALID_UTF8_SUBSTITUTE` because a tool that read a binary
     * file must not be able to stop the protocol dead — a replacement character is a worse
     * answer than the bytes and a far better one than a line that never arrives.
     *
     * @param array<string, mixed> $object
     */
    private function send(array $object): void
    {
        $line = json_encode(
            $object,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($line === false || !is_resource($this->out)) {
            return;
        }

        set_error_handler(static fn (): bool => true);
        try {
            fwrite($this->out, $line . "\n");
        } finally {
            restore_error_handler();
        }
    }

    /** @param list<\Pig\CodingAgent\CustomTools\ToolProblem> $problems */
    private function report(array $problems): void
    {
        foreach ($problems as $problem) {
            $this->send(['type' => 'tool_error', 'path' => $problem->path, 'error' => $problem->error]);
        }
    }

    /**
     * @param list<mixed> $messages
     * @return list<array<string, mixed>>
     */
    private function encoded(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $one = SessionCodec::encode($message);

            if ($one !== null) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function model(\Pig\Ai\Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'provider' => $model->provider,
            'api' => $model->api->value,
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
            'reasoning' => $model->reasoning,
        ];
    }

    /**
     * A string a command must carry.
     *
     * @param array<string, mixed> $command
     * @throws \RuntimeException when it is missing, which is a host's mistake and worth saying
     */
    private static function text(array $command, string $field): string
    {
        $value = $command[$field] ?? null;

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \RuntimeException("'{$field}' is required and must be a string.");
        }

        return (string) $value;
    }
}
