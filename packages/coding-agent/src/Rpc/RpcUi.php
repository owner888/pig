<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Closure;
use Pig\Async\Deferred;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Theme\Palette;

/**
 * `HookUi` over the wire: the question goes out as a line of JSON, the answer comes back as
 * one.
 *
 * `TerminalUi` with a different transport, and the same trick underneath — the handler's
 * fiber parks on a `Deferred` while the loop keeps turning, and the line that arrives on
 * standard input completes it. Which is why this could not have been written before the
 * dialogs were: a blocking `confirm()` needs somewhere to block that is not the loop.
 *
 * The host is another program rather than a person, so two things differ from the terminal:
 *
 * - **Every request has an id**, and the reply names it. A host may answer three questions
 *   in any order, or never answer one at all.
 * - **There is no "only one at a time".** The terminal refuses a second dialog because a
 *   second one would steal the keyboard from the first; a host has no keyboard to steal, and
 *   an editor showing two prompts at once is its own business.
 *
 * Ported from the `createHookUIContext()` closure inside upstream's `rpc-mode.ts`. Not
 * ported: `custom()`, which hands back a `Pig\Tui\Component` — there is no terminal here to
 * draw one on, and upstream leaves it out of the RPC context for the same reason.
 */
final class RpcUi implements HookUi
{
    /** @var array<string, Deferred> questions asked and not yet answered, by id */
    private array $pending = [];

    private int $next = 0;

    /** @param Closure(array<string, mixed>): void $send one JSON line out */
    public function __construct(
        private readonly Closure $send,
        private readonly Palette $palette,
    ) {
    }

    /**
     * Hand a reply to whichever question was waiting for it.
     *
     * An id nobody is waiting for is dropped rather than complained about: a host that
     * answers twice, or answers something that has already been abandoned, has done
     * something harmless and there is no one here to tell.
     *
     * @param array<string, mixed> $reply
     */
    public function answer(array $reply): void
    {
        $id = (string) ($reply['id'] ?? '');
        $waiting = $this->pending[$id] ?? null;

        if ($waiting === null) {
            return;
        }

        unset($this->pending[$id]);
        $waiting->complete($reply);
    }

    /** Whether anything is still waiting to be answered. */
    public function isWaiting(): bool
    {
        return $this->pending !== [];
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        return self::text($this->ask(['method' => 'select', 'title' => $title, 'options' => $options]));
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        $reply = $this->ask(['method' => 'confirm', 'title' => $title, 'message' => $message]);

        // Cancelled, unanswerable, or answered with anything but a yes: all false, which is
        // what makes a `tool_call` guard safe against a host that does not implement this.
        return ($reply['confirmed'] ?? false) === true && ($reply['cancelled'] ?? false) !== true;
    }

    #[\Override]
    public function input(string $title, string $placeholder = ''): ?string
    {
        return self::text($this->ask([
            'method' => 'input',
            'title' => $title,
            'placeholder' => $placeholder,
        ]));
    }

    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        return self::text($this->ask(['method' => 'editor', 'title' => $title, 'prefill' => $prefill]));
    }

    /**
     * Nothing to draw on, so nothing is drawn.
     *
     * Not an error and not a request the host could answer: the factory builds a terminal
     * component, and there is no terminal. A hook that wants something a host can show uses
     * one of the four above.
     */
    #[\Override]
    public function custom(Closure $factory): mixed
    {
        return null;
    }

    /** One-way: a notification is told, not asked. */
    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $this->tell(['method' => 'notify', 'message' => $message, 'level' => $level]);
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
        $this->tell(['method' => 'set_status', 'statusKey' => $key, 'statusText' => $text]);
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
        $this->tell(['method' => 'set_editor_text', 'text' => $text]);
    }

    /**
     * Always empty.
     *
     * The editor belongs to the host and it does not send its contents unasked. A hook that
     * reads this over RPC gets what upstream's RPC context gives it: nothing.
     */
    #[\Override]
    public function getEditorText(): string
    {
        return '';
    }

    #[\Override]
    public function palette(): Palette
    {
        return $this->palette;
    }

    /**
     * Ask, and park until the answer arrives.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed> the reply, whatever shape the host sent
     */
    private function ask(array $request): array
    {
        $id = 'ui-' . (++$this->next);
        $answer = new Deferred();
        $this->pending[$id] = $answer;

        $this->tell(['id' => $id, ...$request]);

        $reply = $answer->future->await();

        return is_array($reply) ? $reply : [];
    }

    /** @param array<string, mixed> $request */
    private function tell(array $request): void
    {
        ($this->send)(['type' => 'hook_ui_request', ...$request]);
    }

    /**
     * The string a reply carries, or null.
     *
     * Null for a cancellation and null for a reply with no value in it, because a host that
     * sent neither has not chosen anything — which is what a cancelled dialog means.
     *
     * @param array<string, mixed> $reply
     */
    private static function text(array $reply): ?string
    {
        if (($reply['cancelled'] ?? false) === true) {
            return null;
        }

        $value = $reply['value'] ?? null;

        return is_string($value) ? $value : null;
    }
}
