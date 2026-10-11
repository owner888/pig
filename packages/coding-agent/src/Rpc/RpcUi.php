<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Closure;
use Pig\Ai\Utils\Uuid;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\Themes;

/**
 * `HookUi` over the wire: the question goes out as an `extension_ui_request` line, the answer comes
 * back as an `extension_ui_response` — upstream's names and fields (`rpc-types.ts`), so a client
 * written against pi answers pig's hooks unchanged.
 *
 * `TerminalUi` with a different transport, and the same trick underneath — the handler's
 * fiber parks on a `Deferred` while the loop keeps turning, and the line that arrives on
 * standard input completes it. Which is why this could not have been written before the
 * dialogs were: a blocking `confirm()` needs somewhere to block that is not the loop.
 *
 * The host is another program rather than a person, so two things differ from the terminal:
 *
 * - **Every request has an id**, and the reply names it. A host may answer three questions
 *   in any order, or never answer one at all. The told-not-asked ones (`notify`, `setStatus`,
 *   `setWidget`, `setTitle`, `set_editor_text`) carry one too, as upstream's do.
 * - **There is no "only one at a time".** The terminal refuses a second dialog because a
 *   second one would steal the keyboard from the first; a host has no keyboard to steal, and
 *   an editor showing two prompts at once is its own business.
 *
 * Ported from the `createExtensionUIContext()` closure inside upstream's `rpc-mode.ts`, method for
 * method — including the ones that send nothing: `setWorkingMessage()`, `setWorkingVisible()`,
 * `setWorkingIndicator()`, `setHiddenThinkingLabel()` and `setToolsExpanded()` need the TUI's loader
 * or transcript and are no-ops over RPC, as upstream's are. pig used to send each as a request of
 * its own (`set_working_message` and the rest) that no upstream client knows. `custom()` returns
 * null: it hands back a `Pig\Tui\Component`, and there is no terminal here to draw one on.
 */
final class RpcUi implements HookUi
{
    /** @var array<string, Deferred> questions asked and not yet answered, by id */
    private array $pending = [];

    /** @param Closure(array<string, mixed>): void $send one JSON line out */
    public function __construct(
        private readonly Closure $send,
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
    public function select(string $title, array $options, ?int $timeout = null): ?string
    {
        return self::text($this->ask(
            ['method' => 'select', 'title' => $title, 'options' => $options, ...self::timeout($timeout)],
            timeout: $timeout,
        ));
    }

    #[\Override]
    public function confirm(string $title, string $message, ?int $timeout = null): bool
    {
        $reply = $this->ask(
            ['method' => 'confirm', 'title' => $title, 'message' => $message, ...self::timeout($timeout)],
            timeout: $timeout,
        );

        // Cancelled, unanswerable, or answered with anything but a yes: all false, which is
        // what makes a `tool_call` guard safe against a host that does not implement this.
        return ($reply['confirmed'] ?? false) === true && ($reply['cancelled'] ?? false) !== true;
    }

    #[\Override]
    public function input(string $title, string $placeholder = '', ?\Pig\Async\AbortSignal $signal = null, ?int $timeout = null): ?string
    {
        return self::text($this->ask([
            'method' => 'input',
            'title' => $title,
            'placeholder' => $placeholder,
            ...self::timeout($timeout),
        ], $signal, $timeout));
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

    /** One-way: a notification is told, not asked. Upstream's field is `notifyType`. */
    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $this->tell(['method' => 'notify', 'message' => $message, 'notifyType' => $level]);
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
        // A cleared status is the key left out, as upstream's `statusText: undefined` is on the wire.
        $this->tell(['method' => 'setStatus', 'statusKey' => $key, ...($text === null ? [] : ['statusText' => $text])]);
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

    /** The global theme, as upstream's RPC UI context answers; the host does the rendering. */
    #[\Override]
    public function theme(): Theme
    {
        return Themes::theme();
    }

    /** Upstream's: "paste handling not supported in RPC mode — falls back to setEditorText". */
    #[\Override]
    public function pasteToEditor(string $text): void
    {
        $this->setEditorText($text);
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        $this->tell(['method' => 'setTitle', 'title' => $title]);
    }

    /** Not supported over RPC, as upstream's is not: the message is the TUI loader's. */
    #[\Override]
    public function setWorkingMessage(?string $message = null): void
    {
    }

    /** Not supported over RPC, as upstream's is not: the loader is the TUI's. */
    #[\Override]
    public function setWorkingVisible(bool $visible): void
    {
    }

    /** Not supported over RPC, as upstream's is not: the indicator is the TUI loader's. */
    #[\Override]
    public function setWorkingIndicator(?array $options = null): void
    {
    }

    /** Not supported over RPC, as upstream's is not: the label is drawn by the TUI's transcript. */
    #[\Override]
    public function setHiddenThinkingLabel(?string $label = null): void
    {
    }

    #[\Override]
    public function getToolsExpanded(): bool
    {
        return false;
    }

    /** Not supported over RPC, as upstream's is not: there is no transcript here to expand. */
    #[\Override]
    public function setToolsExpanded(bool $expanded): void
    {
    }

    /**
     * Lines go to the host, and none — a cleared widget — is `widgetLines` left out, as upstream's
     * undefined is. A factory does not go at all, because it builds a terminal component and there
     * is no terminal — upstream sends only `string[] | undefined` and drops the rest the same way.
     * `widgetPlacement` is upstream's `aboveEditor` or `belowEditor` — `HookUi`'s `above` and
     * `below` in upstream's words — and left out when the hook did not say, as upstream's
     * `options?.placement` is.
     */
    #[\Override]
    public function setWidget(string $key, array|Closure|null $content, array $options = []): void
    {
        if ($content instanceof Closure) {
            return;
        }

        $placement = match ($options['placement'] ?? null) {
            null => null,
            'above' => 'aboveEditor',
            'below' => 'belowEditor',
            default => throw new \InvalidArgumentException("No such widget placement: '{$options['placement']}'. There is above and below."),
        };

        $this->tell([
            'method' => 'setWidget',
            'widgetKey' => $key,
            ...($content === null ? [] : ['widgetLines' => array_values($content)]),
            ...($placement === null ? [] : ['widgetPlacement' => $placement]),
        ]);
    }

    #[\Override]
    public function setHeader(?Closure $factory): void
    {
    }

    #[\Override]
    public function setFooter(?Closure $factory): void
    {
    }

    #[\Override]
    public function getAllThemes(): array
    {
        return [];
    }

    #[\Override]
    public function getTheme(string $name): ?Theme
    {
        return null;
    }

    #[\Override]
    public function setTheme(string|Theme $theme): array
    {
        // Theme switching not supported in RPC mode
        return ['success' => false, 'error' => 'Theme switching not supported in RPC mode'];
    }

    #[\Override]
    public function onTerminalInput(callable $handler): Closure
    {
        return static fn () => null;
    }

    /**
     * Ask, and park until the answer arrives.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed> the reply, whatever shape the host sent
     */
    private function ask(array $request, ?\Pig\Async\AbortSignal $signal = null, ?int $timeout = null): array
    {
        if ($signal?->aborted() === true) {
            return ['cancelled' => true];
        }

        $id = self::id();
        $answer = new Deferred();
        $this->pending[$id] = $answer;

        // Aborted from this side, or out of time: the question is dropped and answered as a
        // cancel, which is upstream's `createDialogPromise()` — it resolves the default and tells
        // the host nothing, and a late answer to the id is one nobody is waiting for any more.
        $withdraw = function () use ($id, $answer): void {
            if (!$answer->isComplete()) {
                unset($this->pending[$id]);
                $answer->complete(['cancelled' => true]);
            }
        };
        $listener = $signal?->onAbort($withdraw);
        $timer = $timeout !== null && $timeout > 0 ? Loop::get()->delay($timeout / 1000, $withdraw) : null;

        $this->send(['id' => $id, ...$request]);

        try {
            $reply = $answer->future->await();
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
            }

            if ($signal !== null && $listener !== null) {
                $signal->removeListener($listener);
            }
        }

        return is_array($reply) ? $reply : [];
    }

    /** @param array<string, mixed> $request one told, not asked: an id of its own, as upstream gives it */
    private function tell(array $request): void
    {
        $this->send(['id' => self::id(), ...$request]);
    }

    /** @param array<string, mixed> $request */
    private function send(array $request): void
    {
        ($this->send)(['type' => 'extension_ui_request', ...$request]);
    }

    /**
     * A fresh id for each request — upstream's `crypto.randomUUID()`. Random rather than counted:
     * a counter restarts with every pig, and a browser tab left over from the last one reconnects
     * to the next still holding a dialog of the same id, which its "one question at a time" rule
     * then cancels on sight — measured, one run in two, before the ids stopped repeating.
     */
    private static function id(): string
    {
        return Uuid::v7();
    }

    /**
     * Upstream's `timeout: opts?.timeout` on the request, which `JSON.stringify` leaves out when it
     * is undefined.
     *
     * @return array{timeout?: int}
     */
    private static function timeout(?int $timeout): array
    {
        return $timeout === null ? [] : ['timeout' => $timeout];
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

    #[\Override]
    public function addAutocompleteProvider(Closure $factory): void
    {
    }

    #[\Override]
    public function setEditorComponent(?Closure $factory): void
    {
    }

    #[\Override]
    public function getEditorComponent(): ?Closure
    {
        return null;
    }
}
