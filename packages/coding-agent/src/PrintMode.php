<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\AgentEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\CodingAgent\Rpc\RpcEvents;
use Pig\CodingAgent\Session\AgentSession;
use Throwable;

/**
 * Say one thing, print the answer, exit.
 *
 * `bin/pig -p "what does this do?"` for a shell script or a pipe, and `--mode json` for the
 * same run with every event on standard output instead — the streaming a terminal would draw,
 * for something that wants to parse it rather than look at it.
 *
 * The third mode, and the smallest, because `RpcMode` did the work: the events are already
 * encoded by `RpcEvents`, and wiring hooks and custom tools without a UI is already a thing
 * that happens. What is left is deciding what to print.
 *
 * Nothing here can ask a question. `NoUi` is the UI, so a hook's `confirm()` is false and a
 * `select()` is null without anyone being asked — which is the fail-safe direction: a
 * `tool_call` guard that cannot reach a person blocks the call rather than waving it through.
 * A hook that wants to behave differently when nobody is watching checks `$ctx->hasUi`.
 *
 * In `json`, the first line is the session's header and every line after it is an event in
 * `RpcEvents`' shape — upstream's `toJsonEvent()`, the same records `--mode rpc` streams.
 *
 * Ported from upstream's `modes/print-mode.ts`. It calls `process.exit(1)` on a failed turn;
 * this returns the code and lets `bin/pig` end the program, which is the one place that knows
 * how.
 */
final class PrintMode
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param 'text'|'json' $mode
     * @param resource|null $out standard output unless a test hands in a pipe
     * @param resource|null $err standard error, likewise
     */
    public function __construct(
        private readonly AgentSession $session,
        private readonly string $mode = 'text',
        private readonly ?HookRunner $hooks = null,
        private readonly ?CustomToolSet $customTools = null,
        $out = null,
        $err = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /**
     * Say everything, then print.
     *
     * @param list<string>       $messages one prompt per turn, in order
     * @param list<ImageContent> $images   attachments for the first one
     * @return int the exit code: 1 if the last turn failed, 0 otherwise
     */
    public function run(array $messages, array $images = []): int
    {
        $this->start();

        $code = 0;

        try {
            foreach ($messages as $at => $message) {
                // Only the first turn carries the attachments — an image sent again with the
                // follow-up would be a second copy of the same picture in the context.
                $this->session->prompt($message, $at === 0 ? $images : []);
            }

            if ($this->mode === 'text') {
                $code = $this->say();
            }
        } catch (Throwable $error) {
            $this->complain($error->getMessage());

            $code = 1;
        } finally {
            $this->stop();
        }

        return $code;
    }

    private function start(): void
    {
        // Upstream's first line in `json`: the session header, so a reader knows which session
        // (id, cwd, parent) the events after it belong to. Before the hooks start, as upstream
        // writes it before binding the extensions. A session that is not being saved has the header
        // its in-memory session keeps, as upstream's `SessionManager.inMemory()` does.
        if ($this->mode === 'json') {
            $this->line($this->session->sessionHeader());
        }

        // No shutdown handler: this mode ends when the prompt does, which is what a quit asks for.
        $this->session->setMode($this->mode === 'json' ? 'json' : 'print');

        // The same wiring as the other two modes, with the one difference that matters: no UI.
        $this->hooks?->wire($this->session, new NoUi());

        // Standard output is the answer, so a broken hook goes to standard error. In `json`
        // it would otherwise arrive as a line that is not JSON, in the middle of lines that
        // are, and whatever is reading them would stop there.
        $this->hooks?->onError(function (HookError $error): void {
            // Upstream's sentence: `Extension error (<path>): <error>`.
            $this->complain("Extension error ({$error->hookPath}): {$error->error}");
        });

        $this->customTools?->withUi(new NoUi());

        // And the context, which this mode did not wire — so a custom tool here was handed
        // `CustomToolSet`'s own stub: no session to read, no model, `abort()` a no-op, and a
        // working directory of `.`, which is whatever directory pig was started from rather
        // than the project. The other two modes wired both, and the comment above claiming
        // "the same wiring, with the one difference that matters" was counting one difference
        // too few.
        $this->customTools?->withContext(
            fn () => $this->hooks?->context() ?? new HookContext($this->session->cwd(), $this->session->store()),
        );

        if ($this->mode === 'json') {
            $this->session->subscribe($this->emit(...));
        }

        $this->hooks?->emit(new SessionStartEvent());
        $this->session->discoverResources('startup');

        foreach ($this->customTools?->notify('start') ?? [] as $problem) {
            $this->complain("tool {$problem->path}: {$problem->error}");
        }
    }

    private function stop(): void
    {
        $this->hooks?->emit(new SessionShutdownEvent());
        $this->customTools?->notify('shutdown');
        $this->session->dispose();
    }

    /**
     * The last answer, as text.
     *
     * Only the text blocks: thinking is the model talking to itself and a tool call is not an
     * answer, so a script reading this gets the thing it asked for and nothing it would have
     * to strip.
     *
     * @return int 1 when the turn ended in an error or an abort, which is worth a non-zero
     *             exit rather than a silent empty line, or was cut off at the output limit
     */
    private function say(): int
    {
        $messages = $this->session->messages();
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            return 0;
        }

        if ($last->stopReason->isFailure()) {
            $this->complain($last->errorMessage ?? "Request {$last->stopReason->value}");

            return 1;
        }

        foreach ($last->content as $block) {
            if ($block instanceof TextContent) {
                fwrite($this->out, $block->text . "\n");
            }
        }

        // The partial answer is printed, but a script must not take it for a whole one (#10799).
        if ($last->stopReason === StopReason::Length) {
            $this->complain('Response was truncated: the model reached its output token limit.');

            return 1;
        }

        return 0;
    }

    private function emit(AgentEvent $event): void
    {
        $encoded = RpcEvents::encode($event);

        if ($encoded !== null) {
            $this->line($encoded);
        }
    }

    /** @param array<string, mixed> $record one JSON line, as `RpcMode::send()` writes it */
    private function line(array $record): void
    {
        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($line !== false) {
            fwrite($this->out, $line . "\n");
        }
    }

    private function complain(string $message): void
    {
        fwrite($this->err, $message . "\n");
    }
}
