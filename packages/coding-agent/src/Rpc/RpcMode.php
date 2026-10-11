<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Closure;
use Pig\Agent\QueueMode;
use Pig\Agent\AgentEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\ImageContent;
use Pig\Ai\Models;
use Pig\Ai\Utils\JsJson;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\ModelRefresh;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use JsonException;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\CompactionEndEvent;
use Pig\CodingAgent\Session\SessionCodec;
use Pig\CodingAgent\Session\SessionEntries;
use Pig\CodingAgent\Settings;
use stdClass;
use Throwable;

/**
 * The second way in: JSON lines on standard input, JSON lines on standard output.
 *
 * For an editor, or anything else that wants the agent without a terminal. One command per
 * line in, and three kinds of line out: a `response` to a command, a session event as the agent
 * works (`RpcEvents`), and an `extension_ui_request` when a hook wants to ask something.
 *
 * ```
 *   {"id":"1","type":"prompt","message":"what does bin/pig do?"}
 *   {"id":"1","type":"response","command":"prompt","success":true,"data":{"disposition":"started"}}
 *   {"type":"message_start","message":{...}}
 *   {"type":"message_update","usage":{...},"assistantMessageEvent":{"type":"text_delta","contentIndex":0,"delta":"It "}}
 *   ...
 *   {"type":"agent_settled","aborted":false}
 * ```
 *
 * **The wire is upstream's** (`modes/rpc/rpc-types.ts`, `rpc-mode.ts`, `json-event.ts`): every command,
 * field, response and event name, so a client written against `pi --mode rpc` drives `pig --mode rpc`
 * unchanged. pig's own additions are additive and named as such — the extra `get_state` fields a web
 * tab draws, `navigate_tree`, and the four commands the web shell needs from its child (`doctor`,
 * `diff`, `bug`, `export_markdown`).
 *
 * `id` is the host's, echoed back on the response so replies can be matched to commands.
 * It is optional, and a command without one gets a response without one. **One response per
 * command, never two**: `prompt` answers once, when it is known what became of the prompt, and a
 * failure after that is the turn's to report, in its events.
 *
 * **Standard input goes on the same event loop as the model's socket**, through
 * `Loop::onReadable()`. That is what makes a hook's `confirm()` work over the wire: the
 * handler's fiber parks on a `Deferred`, the loop keeps reading, and the reply line resumes
 * it. `readline` would have blocked the loop it needs.
 *
 * `rpc-types.ts` has no PHP counterpart, as types: the shapes are this class's methods and
 * `RpcEvents`, which is where a field is named. `rpc-client.ts` is `Rpc\RpcClient`.
 *
 * **Not here from upstream:** `queue_message` is long gone upstream too, and nothing else is missing.
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

    /** @var resource */
    private $err;

    /**
     * @param resource|null $in  standard input unless a test hands in a pipe
     * @param resource|null $out standard output, likewise
     * @param resource|null $err standard error, for what is neither a response nor an event
     * @param (Closure(string, \Pig\CodingAgent\Session\SessionManager, ?Closure): \Pig\CodingAgent\StartedSession)|null $createRuntime
     *        upstream's runtime factory: a session for another working directory, for
     *        `switch_session` to a conversation from another project (`AgentSession::switchTo()`)
     */
    public function __construct(
        private AgentSession $session,
        private string $cwd,
        private ?HookRunner $hooks = null,
        private ?CustomToolSet $customTools = null,
        private ?Settings $settings = null,
        private readonly ?Auth $auth = null,
        $in = null,
        $out = null,
        $err = null,
        private readonly ?Closure $createRuntime = null,
    ) {
        $this->in = $in ?? STDIN;
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
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

        $this->bindSession();

        // Upstream's `main()`: RPC refreshes every fetched catalog in the background, unless
        // `PIG_OFFLINE` says no network.
        if ($this->auth !== null && ModelRefresh::networkEnabled()) {
            ModelRefresh::inBackground($this->auth);
        }

        $this->watcher = Loop::get()->onReadable($this->in, $this->onReadable(...));

        // The web shell's page theme, said before `session_start` so an extension that colours or
        // switches the theme from there already finds the host's. A name this child cannot show
        // leaves the theme as it was; the page sends `set_theme` again once it is bound.
        $hostTheme = getenv('PIG_WEB_THEME');
        if (is_string($hostTheme) && $hostTheme !== '') {
            try {
                $this->ui->useHostTheme($hostTheme);
            } catch (\RuntimeException) {
            }
        }

        $this->hooks?->emit(new SessionStartEvent());
        $this->session->discoverResources('startup');
        $this->report($this->customTools?->notify('start') ?? []);
    }

    /**
     * Upstream's `rebindSession()`: the session's events onto the wire, its mode and shutdown
     * request, and its hooks and custom tools onto this protocol's UI — at startup, and again for
     * the session a `switch_session` to another directory built (`rebind()`).
     */
    private function bindSession(): void
    {
        $this->session->subscribe($this->onAgentEvent(...));
        $this->session->enableCacheWarming();

        $this->session->setMode('rpc');
        $this->session->onShutdownRequest(function (): void {
            Loop::get()->defer(fn () => $this->stop());
        });

        // Each mode wires its own UI; this one's is the protocol.
        $this->hooks?->wire($this->session, $this->ui);

        // Upstream's `extension_error`, with its three fields.
        $this->hooks?->onError(function (HookError $error): void {
            $this->send([
                'type' => 'extension_error',
                'extensionPath' => $error->hookPath,
                'event' => $error->event,
                'error' => $error->error,
            ]);
        });

        $this->customTools?->withUi($this->ui);
        $this->customTools?->withContext(
            fn () => $this->hooks?->context() ?? new HookContext($this->cwd, ui: $this->ui, hasUi: true),
        );
    }

    /**
     * Carry on with the session `switchTo()`'s runtime factory built for another directory —
     * upstream's `runtimeHost.switchSession()` then `rebindSession()`, whose `session_start` is
     * `resume` with the file left. The old session is already shut down and disposed.
     */
    private function rebind(\Pig\CodingAgent\StartedSession $started, ?string $previous): void
    {
        $this->report($this->customTools?->notify('shutdown') ?? []);

        $this->session = $started->session;
        $this->cwd = $started->session->cwd();
        $this->hooks = $started->hooks;
        $this->customTools = $started->customTools;
        $this->settings = $started->session->settings();
        $this->bindSession();

        foreach ($started->warnings as $warning) {
            fwrite($this->err, $warning . "\n");
        }

        $this->hooks->emit(new SessionStartEvent('resume', $previous));
        $this->session->discoverResources('startup');
        $this->report($this->customTools->notify('switch', $previous));
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
     * host says it is finished — it closes the pipe. Split on LF only, with a CR before it
     * stripped, as upstream's `attachJsonlLineReader()`: U+2028 inside a JSON string is not a
     * line break.
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

            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

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
     * runs a whole turn, `compact` and a summarising `navigate_tree` call the model, and anything
     * that reaches a hook may park on a dialog. Running them here would suspend the loop
     * that read the line.
     */
    private function line(string $line): void
    {
        try {
            $command = JsJson::parse($line);
        } catch (JsonException $error) {
            // Upstream's words, and V8's for the part after the colon.
            $this->send(['type' => 'response', 'command' => 'parse', 'success' => false, 'error' => "Failed to parse command: {$error->getMessage()}"]);

            return;
        }

        if (!is_array($command) || array_is_list($command) && $command !== []) {
            // JSON, but not an object: upstream reads `type` off it, finds nothing, and answers
            // the unknown command it is.
            $this->send(['type' => 'response', 'success' => false, 'error' => 'Unknown command: undefined']);

            return;
        }

        if (($command['type'] ?? null) === 'extension_ui_response') {
            $this->ui->answer($command);

            return;
        }

        $id = isset($command['id']) ? (string) $command['id'] : null;
        $type = (string) ($command['type'] ?? '');

        // `prompt` answers from inside the turn, once — see `prompt()`.
        if ($type === 'prompt') {
            Async::spawn(fn () => $this->prompt($command, $id));

            return;
        }

        Async::spawn(function () use ($command, $id, $type): void {
            try {
                $data = $this->dispatch($type, $command);
            } catch (Throwable $error) {
                // Every failure is a response rather than a crash: a host asking for
                // something impossible should be told, not disconnected.
                $this->refuse($id, $type, $error->getMessage());

                return;
            }

            $this->respond($id, $type, $data);
        });
    }

    /**
     * Do what the command says, and answer with its data or nothing.
     *
     * `NO_DATA` is a success without `data`, as upstream's `success(id, command)` sends it; `null`
     * is `data: null`, which upstream sends for `cycle_model` with nothing to cycle to.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>|stdClass|string|null
     * @throws \RuntimeException for anything a host got wrong
     */
    private function dispatch(string $type, array $command): array|stdClass|string|null
    {
        return match ($type) {
            'steer' => ['disposition' => $this->session->steer(self::text($command, 'message'), self::images($command), 'rpc')],
            'follow_up' => ['disposition' => $this->session->followUp(self::text($command, 'message'), self::images($command), 'rpc')],
            'abort' => $this->abort(),
            'clear_queue' => $this->clearQueue(),
            'new_session' => $this->newSession($command),

            'get_state' => $this->state(),

            // "Available" is upstream's word for "there is a key for it", not "pig knows of it":
            // a host drawing a model menu from this list would otherwise offer twenty models and
            // have nineteen of them fail. Without an `Auth` — which only a test builds this
            // without — every model there is, as before.
            'set_model' => $this->setModel($command),
            'cycle_model' => $this->cycleModel($command),
            'get_available_models' => [
                'models' => array_map(self::model(...), $this->auth?->availableModels() ?? Models::all()),
            ],

            'set_thinking_level' => $this->setThinking($command),
            'cycle_thinking_level' => ($level = $this->session->cycleThinkingLevel()) === null ? null : ['level' => $level->value],
            'get_available_thinking_levels' => ['levels' => $this->thinkingLevels()],

            'set_steering_mode' => $this->setQueueMode($command, $this->session->setSteeringMode(...)),
            'set_follow_up_mode' => $this->setQueueMode($command, $this->session->setFollowUpMode(...)),

            'compact' => $this->compact($command),
            'set_auto_compaction' => $this->setAutoCompaction($command),

            'set_auto_retry' => $this->setAutoRetry($command),
            'abort_retry' => $this->abortRetry(),

            'bash' => $this->bash($command),
            'abort_bash' => $this->abortBash(),

            'get_session_stats' => $this->stats(),
            'export_html' => $this->exportHtml($command),
            'switch_session' => $this->switchSession($command),
            'fork' => $this->fork($command),
            'clone' => $this->cloneSession(),
            'get_fork_messages' => ['messages' => $this->session->userMessagesForForking()],
            'get_entries' => $this->entries($command),
            'get_tree' => $this->tree(),
            'get_last_assistant_text' => ['text' => $this->session->lastAssistantText()],
            'set_session_name' => $this->setSessionName($command),

            'get_messages' => ['messages' => $this->encoded($this->session->messages())],

            'get_commands' => ['commands' => $this->commands()],

            // pig's: upstream's `navigateTree()` as a command, which its RPC mode only offers to
            // extensions (`commandContextActions.navigateTree`).
            'navigate_tree' => $this->navigateTree($command),

            // pig's: the host draws in this theme — see `RpcUi::useHostTheme()`. The web shell
            // sends it when a tab binds and whenever the page changes theme.
            'set_theme' => $this->setTheme($command),

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

    /** A success with no `data` key at all — upstream's `success(id, command)`. */
    private const string NO_DATA = "\0no-data";

    /** @param array<string, mixed>|stdClass|string|null $data */
    private function respond(?string $id, string $command, array|stdClass|string|null $data): void
    {
        $response = [
            ...($id === null ? [] : ['id' => $id]),
            'type' => 'response',
            'command' => $command,
            'success' => true,
        ];

        $this->send($data === self::NO_DATA ? $response : [...$response, 'data' => $data]);
    }

    private function refuse(?string $id, string $command, string $error): void
    {
        $this->send([
            ...($id === null ? [] : ['id' => $id]),
            'type' => 'response',
            'command' => $command,
            'success' => false,
            'error' => $error,
        ]);
    }

    // ---- prompting -----------------------------------------------------------------------

    /**
     * Upstream's `prompt` case: the turn starts at once, and the one response goes out from
     * `preflightResult` — `{disposition}` once the prompt is accepted, queued or handled.
     *
     * A failure before that is this command's `success: false`. A failure after it is not a
     * second response for the same id — pig used to send a success and then a failure when the
     * agent was busy, telling a host both that the prompt was accepted and that it was not.
     * After acceptance the turn reports its own trouble, in `message_end` and `agent_end`; what
     * escapes even that goes to standard error, which is where a host looks for a dying agent.
     *
     * @param array<string, mixed> $command
     */
    private function prompt(array $command, ?string $id): void
    {
        $accepted = false;

        try {
            $behavior = isset($command['streamingBehavior']) ? (string) $command['streamingBehavior'] : null;

            $this->session->prompt(
                self::text($command, 'message'),
                self::images($command),
                'rpc',
                $behavior,
                function (string $disposition) use (&$accepted, $id): void {
                    $accepted = true;
                    $this->respond($id, 'prompt', ['disposition' => $disposition]);
                },
            );
        } catch (Throwable $error) {
            if (!$accepted) {
                $this->refuse($id, 'prompt', $error->getMessage());

                return;
            }

            fwrite($this->err, "prompt {$id}: {$error->getMessage()}\n");
        }
    }

    /**
     * Upstream's `ImageContent[]`: `{type: "image", data, mimeType}` each.
     *
     * @param array<string, mixed> $command
     * @return list<ImageContent>
     */
    private static function images(array $command): array
    {
        $images = [];

        foreach ((array) ($command['images'] ?? []) as $at => $image) {
            if (!is_array($image) || !is_string($image['data'] ?? null) || !is_string($image['mimeType'] ?? null)) {
                throw new \RuntimeException("'images[{$at}]' must be {type: \"image\", data, mimeType}.");
            }

            $images[] = new ImageContent($image['data'], $image['mimeType']);
        }

        return $images;
    }

    private function abort(): string
    {
        $this->session->abort()->await();

        return self::NO_DATA;
    }

    /**
     * Upstream's `clear_queue`: both queues, as they were typed, then empty.
     *
     * @return array{steering: list<string>, followUp: list<string>}
     */
    private function clearQueue(): array
    {
        $queued = $this->session->queuedByKind();
        $this->session->clearQueue();

        return $queued;
    }

    // ---- state ---------------------------------------------------------------------------

    /**
     * Upstream's `RpcSessionState`, and after it the fields pig's web tab draws.
     *
     * `model` and `sessionName` are left out when there is none, as `JSON.stringify` drops
     * an undefined field; `sessionFile` likewise. `sessionId` is the session file's id, or the
     * in-memory session's under `--no-session` (`AgentSession::sessionId()`), as upstream's is.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $model = $this->session->model();
        $store = $this->session->store();
        $name = $this->session->getSessionName();

        return [
            ...($model === null ? [] : ['model' => self::model($model)]),
            'thinkingLevel' => $this->session->thinkingLevel()->value,
            'isStreaming' => $this->session->isStreaming(),
            'isCompacting' => $this->session->isCompacting(),
            'steeringMode' => $this->session->steeringMode()->value,
            'followUpMode' => $this->session->followUpMode()->value,
            ...($store === null ? [] : ['sessionFile' => $store->path]),
            'sessionId' => $this->session->sessionId(),
            ...($name === null ? [] : ['sessionName' => $name]),
            'autoCompactionEnabled' => $this->settings?->compactionEnabled() ?? true,
            'messageCount' => count($this->session->messages()),
            'pendingMessageCount' => count($this->session->queued()),

            // pig's, for the web shell: none of them is upstream's, and a pi client ignores them.
            'isBashRunning' => $this->session->isBashRunning(),
            'isPersisted' => $store?->isPersisted() ?? false,
            'contextTokens' => $this->session->contextTokens(),
            'autoRetryEnabled' => $this->settings?->retryEnabled() ?? true,
            'isRetrying' => $this->session->isRetrying(),
            // Under a virtual model, where the latest response actually went — what the TUI's
            // footer draws after the arrow. Null under a physical selection.
            'routedModel' => ($routed = $this->session->routedModel()) === null ? null : [
                'provider' => $routed->model->provider,
                'id' => $routed->model->id,
                'thinkingLevel' => $routed->thinkingLevel?->value,
            ],
            // A tab is labelled by the session's name or its opening line, and grouped by
            // directory. Reading them off the file on the shell side would mean the shell
            // knowing the session format; the child already does.
            'cwd' => $this->session->cwd(),
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

    /**
     * Upstream's `SessionStats`: the counts, the tokens in one object, the cost, and the context
     * window's use — left out when there is no model or window to measure against.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $stats = $this->session->stats();
        $store = $this->session->store();
        $usage = $this->session->contextUsage();

        return [
            ...($store === null ? [] : ['sessionFile' => $store->path]),
            'sessionId' => $this->session->sessionId(),
            'userMessages' => $stats->userMessages,
            'assistantMessages' => $stats->assistantMessages,
            'toolCalls' => $stats->toolCalls,
            'toolResults' => $stats->toolResults,
            'totalMessages' => $stats->totalMessages,
            'tokens' => [
                'input' => $stats->input,
                'output' => $stats->output,
                'cacheRead' => $stats->cacheRead,
                'cacheWrite' => $stats->cacheWrite,
                'total' => $stats->totalTokens(),
            ],
            'cost' => $stats->cost,
            ...($usage === null ? [] : ['contextUsage' => [
                'tokens' => $usage->tokens,
                'contextWindow' => $usage->contextWindow,
                'percent' => $usage->percent,
            ]]),
        ];
    }

    // ---- the model -----------------------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function setModel(array $command): array
    {
        $id = self::text($command, 'modelId');
        $provider = isset($command['provider']) && $command['provider'] !== '' ? (string) $command['provider'] : null;

        // A combined `provider/id` with no separate provider is the other spelling of the same
        // request, and a host sending it got "Model not found" for a model that exists.
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
            throw new \RuntimeException('Model not found: ' . ($provider === null ? $id : "{$provider}/{$id}"));
        }

        $level = isset($command['thinkingLevel']) ? ThinkingLevel::tryFrom((string) $command['thinkingLevel']) : null;
        $this->session->setModel($model, $level);

        return self::model($model);
    }

    /**
     * The next model along, which is ctrl+p in the terminal — upstream's `{model, thinkingLevel,
     * isScoped}`, `isScoped` saying whether the rotation was `--models`' scope or every model with
     * a key. `null` for a machine with one usable model, or one model in the scope.
     *
     * The rotation, the key check and the thinking level a scope entry carries are all
     * `AgentSession::cycleModel()`'s. `direction: "backward"` is pig's, for shift+ctrl+p.
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

        return [
            'model' => self::model($choice->model),
            'thinkingLevel' => $choice->thinking->value,
            'isScoped' => $this->session->modelScope() !== [],
        ];
    }

    /**
     * Upstream's `set_steering_mode` and `set_follow_up_mode`: whether messages typed mid-run are
     * handed over together or one at a time, one command per queue.
     *
     * @param array<string, mixed> $command
     * @param Closure(QueueMode): void $set
     */
    private function setQueueMode(array $command, Closure $set): string
    {
        $wanted = self::text($command, 'mode');

        $set(
            QueueMode::tryFrom($wanted)
                ?? throw new \RuntimeException("No such queue mode: '{$wanted}'. There is "
                    . implode(' and ', array_map(static fn (QueueMode $m): string => $m->value, QueueMode::cases())) . '.'),
        );

        return self::NO_DATA;
    }

    /**
     * Upstream's `set_thinking_level`: `session.setThinkingLevel(command.level)`, which clamps a
     * level the model does not offer to the nearest one it does — `AgentSession::setThinkingLevel()`
     * does the clamping, so the answer is the empty success either way and `get_state` says what
     * was set. A string that is not a thinking level at all is still refused: there is nothing to
     * clamp from.
     *
     * @param array<string, mixed> $command
     */
    private function setThinking(array $command): string
    {
        $wanted = ThinkingLevel::tryFrom(self::text($command, 'level'))
            ?? throw new \RuntimeException('No such thinking level: ' . self::text($command, 'level'));

        $this->session->setThinkingLevel($wanted);

        return self::NO_DATA;
    }

    /**
     * Upstream's `getAvailableThinkingLevels()`: `["off"]` for a model that cannot reason, which
     * `AgentSession::availableThinkingLevels()` answers as an empty list for its own callers.
     *
     * @return list<string>
     */
    private function thinkingLevels(): array
    {
        $levels = $this->session->availableThinkingLevels();

        return $levels === [] ? [ThinkingLevel::Off->value] : array_map(static fn (ThinkingLevel $l): string => $l->value, $levels);
    }

    // ---- making room ---------------------------------------------------------------------

    /**
     * Upstream's `compact`, answering its `CompactionResult`.
     *
     * `AgentSession::compact()` returns the entry the terminal draws; the result is what its
     * `CompactionEndEvent` carries, which arrives while `compact()` runs — so it is caught on the
     * way past, matched to the entry by identity, rather than worked out a second time here.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function compact(array $command): array
    {
        $instructions = isset($command['customInstructions'])
            ? (string) $command['customInstructions']
            : null;

        $ended = [];
        $unsubscribe = $this->session->subscribe(static function (AgentEvent $event) use (&$ended): void {
            if ($event instanceof CompactionEndEvent) {
                $ended[] = $event;
            }
        });

        try {
            $summary = $this->session->compact($instructions === '' ? null : $instructions);
        } finally {
            $unsubscribe();
        }

        if ($summary === null) {
            // Upstream's `compact()` throws on a cancel; the summariser was stopped part-way.
            throw new \RuntimeException('Compaction cancelled');
        }

        foreach ($ended as $event) {
            if ($event->summary === $summary && $event->result !== null) {
                return RpcEvents::compactionResult($event->result);
            }
        }

        throw new \RuntimeException('The compaction finished without saying what it wrote.');
    }

    /** @param array<string, mixed> $command */
    private function setAutoCompaction(array $command): string
    {
        $this->settings?->set('compaction.enabled', (bool) ($command['enabled'] ?? true));

        return self::NO_DATA;
    }

    // ---- waiting out a provider -----------------------------------------------------------

    /** @param array<string, mixed> $command */
    private function setAutoRetry(array $command): string
    {
        $this->settings?->setRetryEnabled(($command['enabled'] ?? true) === true);

        return self::NO_DATA;
    }

    private function abortRetry(): string
    {
        $this->session->abortRetry();

        return self::NO_DATA;
    }

    // ---- bash ----------------------------------------------------------------------------

    /**
     * Upstream's `bash`: the command runs, its output streams as `bash_execution_update` events
     * carrying this command's id, and the answer is upstream's `BashResult` — `output`, `exitCode`
     * (left out for a command that was cancelled, as upstream's `undefined` is), `cancelled`,
     * `truncated`, and `fullOutputPath` when the output was too long to keep.
     *
     * `excludeFromContext` is `!!`: the command is kept in the session, out of the model's sight.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function bash(array $command): array
    {
        $execution = $this->session->executeBash(
            self::text($command, 'command'),
            ($command['excludeFromContext'] ?? false) === true,
            null,
            isset($command['id']) ? (string) $command['id'] : null,
        );

        return [
            'output' => $execution->output,
            ...($execution->exitCode === null ? [] : ['exitCode' => $execution->exitCode]),
            'cancelled' => $execution->cancelled,
            'truncated' => $execution->truncated,
            ...($execution->fullOutputPath === null ? [] : ['fullOutputPath' => $execution->fullOutputPath]),
        ];
    }

    private function abortBash(): string
    {
        $this->session->abortBash();

        return self::NO_DATA;
    }

    // ---- the session ---------------------------------------------------------------------

    /**
     * Upstream's `get_entries`: every entry in append order, the header left out, and `since` as a
     * cursor — only the entries strictly after it. An id the file has not got is a refusal, as
     * upstream's is, rather than everything: a host that lost its place should know it.
     *
     * An entry pig cannot write back as a line — one a newer tool wrote, that pig carries as a
     * node holding nothing — is not in the list; its children still are, with their parent id.
     *
     * @param array<string, mixed> $command
     * @return array{entries: list<array<string, mixed>>, leafId: string|null}
     */
    private function entries(array $command): array
    {
        $store = $this->session->store();
        $entries = $store?->entries() ?? [];

        if (isset($command['since'])) {
            $since = (string) $command['since'];
            $at = null;

            foreach ($entries as $index => $entry) {
                if ($entry->id === $since) {
                    $at = $index;
                    break;
                }
            }

            if ($at === null) {
                throw new \RuntimeException("Entry not found: {$since}");
            }

            $entries = array_slice($entries, $at + 1);
        }

        $encoded = [];

        foreach ($entries as $entry) {
            $line = SessionEntries::encode($entry->message, $entry->id, $entry->parentId);

            if ($line !== null) {
                $encoded[] = $line;
            }
        }

        return ['entries' => $encoded, 'leafId' => $store?->leaf()];
    }

    /**
     * Upstream's `get_tree`: `{entry, children, label?}` nodes, roots first, and the leaf.
     *
     * @return array{tree: list<array<string, mixed>>, leafId: string|null}
     */
    private function tree(): array
    {
        $store = $this->session->store();

        return ['tree' => $store === null ? [] : $this->treeNodes($store->tree(), $store), 'leafId' => $store?->leaf()];
    }

    /**
     * @param list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}> $nodes
     * @return list<array<string, mixed>>
     */
    private function treeNodes(array $nodes, \Pig\CodingAgent\Session\SessionManager $store): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $id = (string) $node['id'];
            $entry = SessionEntries::encode($node['message'], $id, $store->entry($id)['parent'] ?? null);

            if ($entry === null) {
                continue;
            }

            $out[] = [
                'entry' => $entry,
                'children' => $this->treeNodes($node['children'], $store),
                ...($node['label'] === null ? [] : ['label' => $node['label']]),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $command */
    private function setSessionName(array $command): string
    {
        $name = trim(self::text($command, 'name'));

        if ($name === '') {
            throw new \RuntimeException('Session name cannot be empty');
        }

        $this->session->setSessionName($name);

        return self::NO_DATA;
    }

    /**
     * Upstream's `get_commands`: what `prompt` can be sent with a slash — the hooks' commands, the
     * prompt templates, and the skills as `skill:name`. The terminal's own (`/settings` and the
     * rest) are not here, as upstream's are not: they are the screen's, and a `prompt` would send
     * them to the model as text.
     *
     * `sourceInfo` is upstream's shape. pig keeps less provenance than upstream's `SourceInfo` — a
     * template or a skill knows the root it was found under, a hook its path — so `source`,
     * `scope` and `origin` are read off that: `user` and `project` roots are `local` at that
     * scope, a package's is `package`, and a path given on the command line or by an extension is
     * `temporary`. See `sourceInfo()`.
     *
     * @return list<array<string, mixed>>
     */
    private function commands(): array
    {
        $commands = [];

        foreach ($this->hooks?->commands()[0] ?? [] as $name => $command) {
            $commands[] = [
                'name' => (string) $name,
                ...($command->description === '' ? [] : ['description' => $command->description]),
                'source' => 'extension',
                'sourceInfo' => $this->sourceInfo(
                    $command->hookPath,
                    str_starts_with($command->hookPath, rtrim($this->cwd, '/') . '/') ? 'project' : 'user',
                ),
            ];
        }

        foreach ($this->session->fileCommands() as $template) {
            $commands[] = [
                'name' => $template->name,
                'description' => $template->description,
                'source' => 'prompt',
                'sourceInfo' => $this->sourceInfo($template->path ?? '', trim($template->source, '()')),
            ];
        }

        foreach ($this->session->skills() as $skill) {
            $commands[] = [
                'name' => "skill:{$skill->name}",
                'description' => $skill->description,
                'source' => 'skill',
                'sourceInfo' => $this->sourceInfo($skill->path, $skill->source, $skill->baseDir),
            ];
        }

        return $commands;
    }

    /**
     * Upstream's `SourceInfo` from the root label pig keeps: `user`, `project`, `package`, `path`
     * (a command-line `--skill` or `--prompt-template`) or `extension` (a hook's
     * `resources_discover`).
     *
     * @return array{path: string, source: string, scope: string, origin: string, baseDir?: string}
     */
    private function sourceInfo(string $path, string $root, ?string $baseDir = null): array
    {
        [$source, $scope, $origin] = match ($root) {
            'user' => ['local', 'user', 'top-level'],
            'project' => ['local', 'project', 'top-level'],
            'package' => ['package', 'user', 'package'],
            'path' => ['cli', 'temporary', 'top-level'],
            default => [$root, 'temporary', 'top-level'],
        };

        return [
            'path' => $path,
            'source' => $source,
            'scope' => $scope,
            'origin' => $origin,
            ...($baseDir === null ? [] : ['baseDir' => $baseDir]),
        ];
    }

    /**
     * pig's `set_theme`: `{theme}` is what the host draws in, so `theme()` is that one and
     * extensions may list and switch themes. A theme this child cannot show fails, and the theme
     * stays as it was.
     *
     * @param array<string, mixed> $command
     */
    private function setTheme(array $command): string
    {
        $this->ui->useHostTheme(self::text($command, 'theme'));

        return self::NO_DATA;
    }

    /**
     * Upstream's `navigateTree(targetId, {summarize, customInstructions, replaceInstructions,
     * label})` as a command, answering its `{cancelled, aborted?, editorText?, summaryEntry?}`.
     *
     * Not an upstream RPC command — upstream offers it to extensions only — and pig's because the
     * web shell has the tree to offer. Going back to something somebody said goes back to *before*
     * it, and `editorText` is what they said, for the host's input box. `summaryEntry` is the
     * `branch_summary` entry written for the branch left behind, when one was asked for.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function navigateTree(array $command): array
    {
        $instructions = isset($command['customInstructions']) ? (string) $command['customInstructions'] : null;

        $jump = $this->session->goTo(
            self::text($command, 'targetId'),
            ($command['summarize'] ?? false) === true,
            $instructions === '' ? null : $instructions,
            replaceInstructions: isset($command['replaceInstructions']) ? $command['replaceInstructions'] === true : null,
            label: isset($command['label']) ? (string) $command['label'] : null,
        );

        $this->report($this->customTools?->notify('tree') ?? []);

        $store = $this->session->store();
        $summaryId = $jump->summary === null ? null : $store?->entryOf($jump->summary);
        $summaryEntry = $summaryId === null ? null : SessionEntries::encode($jump->summary, $summaryId, $store?->entry($summaryId)['parent'] ?? null);

        return [
            'cancelled' => $jump->cancelled,
            ...($jump->aborted ? ['aborted' => true] : []),
            ...($jump->editorText === null ? [] : ['editorText' => $jump->editorText]),
            ...($summaryEntry === null ? [] : ['summaryEntry' => $summaryEntry]),
        ];
    }

    /**
     * Throw this conversation away and start another — upstream's `new_session`, with its
     * `parentSession` written on the new file's header.
     *
     * The hook, the abort and the emptied queue are `AgentSession::startNew()`'s, not this
     * file's: they were missing here and present in the terminal, and one of the two having
     * them is how they went missing. What is left is this mode's own share — telling the
     * custom tools.
     *
     * @param array<string, mixed> $command
     * @return array{cancelled: bool}
     */
    private function newSession(array $command): array
    {
        $parent = isset($command['parentSession']) && $command['parentSession'] !== '' ? (string) $command['parentSession'] : null;
        $switch = $this->session->startNew($parent);

        if (!$switch->switched) {
            return ['cancelled' => true];
        }

        $this->report($this->customTools?->notify('switch', $switch->previous) ?? []);

        return ['cancelled' => false];
    }

    /**
     * Fork the conversation into a new session file from before one of the person's messages,
     * answering upstream's `{text, cancelled}` — the message's words, for the host to put in its
     * prompt.
     *
     * @param array<string, mixed> $command
     */
    private function fork(array $command): array
    {
        $fork = $this->session->fork(self::text($command, 'entryId'));

        if (!$fork->switched) {
            return ['cancelled' => true];
        }

        $this->report($this->customTools?->notify('fork', $fork->previous) ?? []);

        return ['text' => $fork->editorText ?? '', 'cancelled' => false];
    }

    /** This conversation as it stands, in a new session file — upstream's `clone`. */
    private function cloneSession(): array
    {
        $leaf = $this->session->store()?->leaf();

        if ($leaf === null) {
            throw new \RuntimeException('Cannot clone session: no current entry selected');
        }

        $fork = $this->session->fork($leaf, 'at');

        if (!$fork->switched) {
            return ['cancelled' => true];
        }

        $this->report($this->customTools?->notify('fork', $fork->previous) ?? []);

        return ['cancelled' => false];
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
        // Upstream's: a session from another project carries on in its directory, in a runtime
        // built for it; one whose directory has gone is `MissingSessionCwdError`'s message, as an
        // error — nobody here to ask whether to carry on in this one.
        $createRuntime = $this->createRuntime;
        $switch = $this->session->switchTo(
            self::text($command, 'sessionPath'),
            relocate: $createRuntime === null ? null : static fn (string $cwd, \Pig\CodingAgent\Session\SessionManager $opened): \Pig\CodingAgent\StartedSession => $createRuntime($cwd, $opened, null),
        );

        if (!$switch->switched) {
            // `cancelled` rather than an error: a hook saying no is an answer, not a failure, and
            // upstream returns `false` from the same place.
            return ['cancelled' => true];
        }

        if ($switch->replacement !== null) {
            $this->rebind($switch->replacement, $switch->previous);

            return ['cancelled' => false];
        }

        $this->report($this->customTools?->notify('switch', $switch->previous) ?? []);

        // `{cancelled}`, upstream's whole answer: the file, the model it restored and the rest are
        // `get_state`'s to say, and saying them twice is two places to disagree.
        return ['cancelled' => false];
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

    /**
     * Upstream's `export_html`, `outputPath` honoured — the session as one HTML file.
     *
     * @param array<string, mixed> $command
     * @return array{path: string}
     */
    private function exportHtml(array $command): array
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

    /**
     * Upstream's Model object, as `rpc-commands.md` lists it: the costs in dollars per million
     * tokens. `headers` stays out — a `models.json` entry may carry a key in one.
     *
     * @return array<string, mixed>
     */
    private static function model(\Pig\Ai\Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            'input' => $model->input,
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
            'cost' => [
                'input' => $model->pricing->input,
                'output' => $model->pricing->output,
                'cacheRead' => $model->pricing->cacheRead,
                'cacheWrite' => $model->pricing->cacheWrite,
            ],
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
