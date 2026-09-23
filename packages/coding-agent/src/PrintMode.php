<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\AgentEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\CodingAgent\Rpc\RpcEvents;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
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
        // The same wiring as the other two modes, with the one difference that matters: no UI.
        $this->hooks?->initialize(
            getModel: fn () => $this->session->model(),
            isIdle: fn (): bool => !$this->session->isStreaming(),
            abort: function (): void {
                $this->session->abort();
            },
            hasQueuedMessages: fn (): bool => $this->session->queued() !== [],
            ui: new NoUi(),
            send: function (HookMessage $message, bool $triggerTurn): void {
                $this->session->sendHookMessage($message, $triggerTurn);
            },
            note: function (string $customType, mixed $data): void {
                $this->session->appendHookEntry($customType, $data);
            },
        );

        // Standard output is the answer, so a broken hook goes to standard error. In `json`
        // it would otherwise arrive as a line that is not JSON, in the middle of lines that
        // are, and whatever is reading them would stop there.
        $this->hooks?->onError(function (HookError $error): void {
            $this->complain("hook {$error->hookPath} ({$error->event}): {$error->error}");
        });

        $this->customTools?->withUi(new NoUi());

        if ($this->mode === 'json') {
            $this->session->subscribe($this->emit(...));
        }

        $this->hooks?->emit(new SessionStartEvent());

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
     *             exit rather than a silent empty line
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

        return 0;
    }

    private function emit(AgentEvent $event): void
    {
        $encoded = RpcEvents::encode($event);

        if ($encoded === null) {
            return;
        }

        $line = json_encode(
            $encoded,
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
