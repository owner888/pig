<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Closure;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * The other end of RPC mode: start `bin/pig --mode rpc` and drive it.
 *
 * Upstream's `modes/rpc/rpc-client.ts`. `RpcMode`'s docblock used to say this had no counterpart
 * because "a host writes JSON lines in whatever language it is in", and that is still true of a host
 * written in something else — but it left pig with **no test of the protocol as a protocol**.
 * `RpcModeTest` builds an `RpcMode` in process and hands it two streams, which tests the dispatch
 * and not the wire: not the binary starting, not a line crossing a pipe, not the framing when a
 * chunk boundary falls mid-line. This is what can test that, and a PHP host is the second reason.
 *
 * ```php
 * $client = new RpcClient(cwd: '/some/project');
 * Async::run(function () use ($client): void {
 *     $client->start();
 *     $client->onEvent(static fn (array $event) => print $event['type'] . "\n");
 *     $client->promptAndWait('what does bin/pig do?');
 *     $client->stop();
 * });
 * ```
 *
 * **Every method here suspends its fiber rather than returning a promise**, which is the whole
 * difference from upstream. `send()` parks on a `Deferred` while the loop keeps reading the child's
 * stdout, and the response line with the matching id resumes it — so a host reads like a program
 * that blocks, and nothing actually blocks. Upstream needs `await` on all twenty-odd methods and a
 * `collectEvents()` promise registered *before* the prompt is sent; here `promptAndWait()` is three
 * statements in a row.
 *
 * The commands mirror **pig's** twenty-two, not upstream's. `RpcMode`'s docblock lists the six of
 * upstream's that pig does not have and why; a client with a `queueMessage()` that guessed between
 * `steer` and `follow_up` would be worse than no client.
 */
final class RpcClient
{
    private const int CHUNK = 65_536;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var array<int, string> loop watcher ids, by pipe */
    private array $watchers = [];

    /** Everything read from stdout and not yet split into a line. */
    private string $buffer = '';

    private string $stderr = '';

    /** @var array<string, Deferred> waiting commands, by id */
    private array $pending = [];

    /** @var array<int, Closure(array<string, mixed>): void> */
    private array $listeners = [];

    private int $nextId = 0;

    private int $nextListener = 0;

    /**
     * @param string        $cwd         where the agent works, and where it is started
     * @param string|null   $binary      `bin/pig`, found relative to this package unless given
     * @param list<string>  $arguments   extra options, such as `--model` or `--no-hooks`
     * @param array<string, string> $environment added to this process's own
     * @param float         $timeout     seconds to wait for one response before giving up
     */
    public function __construct(
        private readonly string $cwd = '.',
        private readonly ?string $binary = null,
        private readonly array $arguments = [],
        private readonly array $environment = [],
        private readonly float $timeout = 30.0,
    ) {
    }

    /** Where `bin/pig` is, four directories up from this file. */
    public static function defaultBinary(): string
    {
        return dirname(__DIR__, 4) . '/bin/pig';
    }

    /**
     * Start the agent.
     *
     * **No sleep.** Upstream waits 100ms and then looks at the exit code, which is a race with a
     * number on it: a machine under load fails the check and a child that dies at 150ms passes it.
     * Nothing here needs the child to be ready — the first command waits for its own response, and
     * a child that died says so in that wait, with its standard error attached.
     */
    public function start(): void
    {
        if ($this->process !== null) {
            throw new RpcError('start', 'The client is already started');
        }

        $binary = $this->binary ?? self::defaultBinary();

        if (!is_file($binary)) {
            throw new RpcError('start', "No pig at {$binary}");
        }

        $command = [PHP_BINARY, $binary, '--mode', 'rpc', ...$this->arguments];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->cwd,
            $this->environment === [] ? null : [...getenv(), ...$this->environment],
        );

        if (!is_resource($process)) {
            throw new RpcError('start', "Could not start {$binary}");
        }

        $this->process = $process;
        $this->pipes = $pipes;

        foreach ([0, 1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }

        $this->watchers[1] = Loop::get()->onReadable($pipes[1], function (): void {
            $this->readOut();
        });

        $this->watchers[2] = Loop::get()->onReadable($pipes[2], function (): void {
            $chunk = fread($this->pipes[2], self::CHUNK);

            if (is_string($chunk)) {
                $this->stderr .= $chunk;
            }
        });
    }

    /**
     * Stop the agent, and wait for it.
     *
     * Closing standard input is the way: `RpcMode` sees the EOF, stops its loop and runs its
     * shutdown — the `session_shutdown` hook fires and the custom tools are notified. A SIGTERM
     * would skip all of that, so it is the fallback rather than the method, and SIGKILL the fallback
     * to that.
     */
    public function stop(float $timeout = 5.0): void
    {
        if ($this->process === null) {
            return;
        }

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }

        $deadline = microtime(true) + $timeout;
        $half = microtime(true) + $timeout / 2;
        $terminated = false;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if ($status === false || !$status['running']) {
                break;
            }

            // `Async::delay()` rather than `usleep()`: it parks this fiber on a timer and the loop
            // keeps polling, so the child's shutdown lines are read instead of lost. A sleep would
            // stop the reader that is meant to be draining them.
            Async::delay(0.02);

            // Halfway through, ask properly. `RpcMode` handles SIGTERM as an interrupt rather than
            // as "stop", so this is the nudge for a child that did not notice its input closing —
            // not the normal path.
            if (!$terminated && microtime(true) > $half) {
                proc_terminate($this->process);
                $terminated = true;
            }
        }

        $status = proc_get_status($this->process);

        if ($status !== false && $status['running']) {
            proc_terminate($this->process, 9);
        }

        $this->cleanUp();
    }

    /**
     * Hear every line the agent sends that is not a response to a command.
     *
     * @param  Closure(array<string, mixed>): void $listener
     * @return Closure(): void call it to stop listening
     */
    public function onEvent(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /** Everything the agent wrote to standard error: warnings at startup, and why it died. */
    public function stderr(): string
    {
        return $this->stderr;
    }

    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status !== false && $status['running'];
    }

    // ---- the conversation ----------------------------------------------------------------------

    /** @param list<array<string, mixed>> $images `{data, mimeType}` each, as the wire wants them */
    public function prompt(string $message, array $images = []): void
    {
        $this->send(['type' => 'prompt', 'message' => $message, 'images' => $images]);
    }

    /** Interrupt the turn in flight with something to take into account. */
    public function steer(string $message): void
    {
        $this->send(['type' => 'steer', 'message' => $message]);
    }

    /** Ask this once the turn in flight is done. */
    public function followUp(string $message): void
    {
        $this->send(['type' => 'follow_up', 'message' => $message]);
    }

    public function abort(): void
    {
        $this->send(['type' => 'abort']);
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->send(['type' => 'get_state']) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function messages(): array
    {
        $data = $this->send(['type' => 'get_messages']) ?? [];

        return is_array($data['messages'] ?? null) ? array_values($data['messages']) : [];
    }

    public function lastAssistantText(): ?string
    {
        $data = $this->send(['type' => 'get_last_assistant_text']) ?? [];
        $text = $data['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /** @return array<string, mixed> */
    public function sessionStats(): array
    {
        return $this->send(['type' => 'get_session_stats']) ?? [];
    }

    // ---- the model -----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function availableModels(): array
    {
        $data = $this->send(['type' => 'get_available_models']) ?? [];

        return is_array($data['models'] ?? null) ? array_values($data['models']) : [];
    }

    /** @return array<string, mixed> */
    public function setModel(string $provider, string $modelId): array
    {
        return $this->send(['type' => 'set_model', 'provider' => $provider, 'modelId' => $modelId]) ?? [];
    }

    public function setThinkingLevel(string $level): void
    {
        $this->send(['type' => 'set_thinking_level', 'level' => $level]);
    }

    /** @return string|null the level it moved to, or null when the model cannot think */
    public function cycleThinkingLevel(): ?string
    {
        $data = $this->send(['type' => 'cycle_thinking_level']) ?? [];
        $level = $data['level'] ?? null;

        return is_string($level) ? $level : null;
    }

    // ---- context and retries -------------------------------------------------------------------

    /** @return array<string, mixed> `{cancelled, summary}` */
    public function compact(?string $instructions = null): array
    {
        return $this->send(['type' => 'compact', 'customInstructions' => $instructions]) ?? [];
    }

    public function setAutoCompaction(bool $enabled): void
    {
        $this->send(['type' => 'set_auto_compaction', 'enabled' => $enabled]);
    }

    public function setAutoRetry(bool $enabled): void
    {
        $this->send(['type' => 'set_auto_retry', 'enabled' => $enabled]);
    }

    public function abortRetry(): void
    {
        $this->send(['type' => 'abort_retry']);
    }

    // ---- the shell -----------------------------------------------------------------------------

    /**
     * Run a command in the agent's own shell, the way `!` does in the terminal.
     *
     * `$remember` is what `!` and `!!` are: kept, the output goes into the conversation the model
     * sees; not kept, it was for the person at the keyboard.
     *
     * @return array<string, mixed> `{command, output, exitCode, cancelled, truncated, spillPath}`
     */
    public function bash(string $command, bool $remember = true): array
    {
        return $this->send(['type' => 'bash', 'command' => $command, 'remember' => $remember]) ?? [];
    }

    public function abortBash(): void
    {
        $this->send(['type' => 'abort_bash']);
    }

    // ---- moving around the conversation --------------------------------------------------------

    /** @return array<string, mixed> the points a `go_to` can name */
    public function branch(): array
    {
        return $this->send(['type' => 'get_branch']) ?? [];
    }

    /**
     * Move to a point in the conversation, optionally summarising the branch being left.
     *
     * @return array<string, mixed> `{moved, aborted, summary}`
     */
    public function goTo(?string $entryId, bool $summarise = false, ?string $instructions = null): array
    {
        return $this->send([
            'type' => 'go_to',
            'entryId' => $entryId,
            'summarise' => $summarise,
            'customInstructions' => $instructions,
        ]) ?? [];
    }

    /** @return array<string, mixed> */
    public function newSession(): array
    {
        return $this->send(['type' => 'new_session']) ?? [];
    }

    /** @return array<string, mixed> */
    public function switchSession(string $sessionPath): array
    {
        // `sessionPath`, not `path` — the wire's name, which is the only one that matters here. The
        // first draft of this said `path` and the command silently did nothing but complain about a
        // missing field, which is what the end-to-end test is for.
        return $this->send(['type' => 'switch_session', 'sessionPath' => $sessionPath]) ?? [];
    }

    /** @return array<string, mixed> `{path}` — `outputPath` on the wire */
    public function export(?string $outputPath = null): array
    {
        return $this->send(['type' => 'export', 'outputPath' => $outputPath]) ?? [];
    }

    // ---- waiting -------------------------------------------------------------------------------

    /**
     * Suspend until the agent finishes the turn.
     *
     * No listener has to be registered in advance the way upstream's does, because this parks the
     * fiber that called it: there is no window between sending a prompt and starting to listen.
     */
    public function waitForIdle(float $timeout = 60.0): void
    {
        $this->until(static fn (array $event): bool => ($event['type'] ?? null) === 'agent_end', $timeout);
    }

    /**
     * Every event up to and including the one that ends the turn.
     *
     * @return list<array<string, mixed>>
     */
    public function collectEvents(float $timeout = 60.0): array
    {
        $seen = [];

        $this->until(static function (array $event) use (&$seen): bool {
            $seen[] = $event;

            return ($event['type'] ?? null) === 'agent_end';
        }, $timeout);

        return $seen;
    }

    /**
     * Say it and wait for the answer, with everything that happened.
     *
     * @param  list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    public function promptAndWait(string $message, array $images = [], float $timeout = 60.0): array
    {
        $seen = [];
        $collect = $this->onEvent(static function (array $event) use (&$seen): void {
            $seen[] = $event;
        });

        try {
            $this->prompt($message, $images);
            $this->waitForIdle($timeout);
        } finally {
            $collect();
        }

        return $seen;
    }

    // ---- the wire ------------------------------------------------------------------------------

    /**
     * Send a command and suspend until its response arrives.
     *
     * @param  array<string, mixed> $command without its id, which is added here
     * @return array<string, mixed>|null the response's data, when it carried any
     * @throws RpcError for a refusal, a timeout, or a child that is no longer there
     */
    private function send(array $command): ?array
    {
        $type = (string) ($command['type'] ?? '?');

        if ($this->process === null) {
            throw new RpcError($type, 'The client is not started');
        }

        $id = 'req_' . ++$this->nextId;
        $deferred = new Deferred();
        $this->pending[$id] = $deferred;

        $line = json_encode([...$command, 'id' => $id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            unset($this->pending[$id]);

            throw new RpcError($type, 'The command could not be encoded');
        }

        $timer = Loop::get()->delay($this->timeout, function () use ($id, $type): void {
            $pending = $this->pending[$id] ?? null;
            unset($this->pending[$id]);
            $pending?->error(new RpcError(
                $type,
                sprintf('No answer in %.1fs%s', $this->timeout, $this->trailer()),
            ));
        });

        try {
            $this->write($line . "\n");

            /** @var array<string, mixed> $response */
            $response = $deferred->future->await();
        } finally {
            Loop::get()->cancel($timer);
            unset($this->pending[$id]);
        }

        if (($response['success'] ?? false) !== true) {
            throw new RpcError($type, (string) ($response['error'] ?? 'refused with no reason given'));
        }

        $data = $response['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * Write to the child's standard input, suspending when the pipe is full.
     *
     * A prompt with an image in it is far past a pipe's atomic write, so this cannot be one
     * `fwrite`: the loop has to keep running while the child reads what has been sent so far, or the
     * two ends wait for each other.
     */
    private function write(string $data): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
                throw new RpcError('write', 'The agent closed its standard input' . $this->trailer());
            }

            $written = fwrite($this->pipes[0], substr($data, $offset));

            if ($written === false) {
                throw new RpcError('write', 'Could not write to the agent' . $this->trailer());
            }

            if ($written === 0) {
                $deferred = new Deferred();
                $watcher = Loop::get()->onWritable($this->pipes[0], static function () use ($deferred): void {
                    if (!$deferred->isComplete()) {
                        $deferred->complete(null);
                    }
                });

                try {
                    $deferred->future->await();
                } finally {
                    Loop::get()->cancel($watcher);
                }

                continue;
            }

            $offset += $written;
        }
    }

    /** Read what is there, and dispatch whole lines. */
    private function readOut(): void
    {
        $chunk = fread($this->pipes[1], self::CHUNK);

        if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
            $this->fail('The agent closed its output' . $this->trailer());

            return;
        }

        $this->buffer .= $chunk;

        while (($break = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $break);
            $this->buffer = substr($this->buffer, $break + 1);
            $this->dispatch(trim($line));
        }
    }

    private function dispatch(string $line): void
    {
        if ($line === '') {
            return;
        }

        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            // Not ours. `bin/pig` writes its warnings to standard error, so a line here that is not
            // JSON came from something else in the process — a hook that printed, most likely — and
            // killing the host over it would be worse than ignoring it.
            return;
        }

        $id = $decoded['id'] ?? null;

        if (($decoded['type'] ?? null) === 'response' && is_string($id) && isset($this->pending[$id])) {
            $deferred = $this->pending[$id];
            unset($this->pending[$id]);
            $deferred->complete($decoded);

            return;
        }

        // Everything else is an event, including a response to a command this client did not send.
        foreach ($this->listeners as $listener) {
            $listener($decoded);
        }
    }

    /**
     * Suspend until $wanted says so, or until the agent stops.
     *
     * @param Closure(array<string, mixed>): bool $wanted
     */
    private function until(Closure $wanted, float $timeout): void
    {
        $deferred = new Deferred();
        $unsubscribe = $this->onEvent(static function (array $event) use ($wanted, $deferred): void {
            if (!$deferred->isComplete() && $wanted($event)) {
                $deferred->complete(null);
            }
        });

        $timer = Loop::get()->delay($timeout, function () use ($deferred, $timeout): void {
            if (!$deferred->isComplete()) {
                $deferred->error(new RpcError(
                    'wait',
                    sprintf('The agent was still working after %.1fs%s', $timeout, $this->trailer()),
                ));
            }
        });

        try {
            $deferred->future->await();
        } finally {
            Loop::get()->cancel($timer);
            $unsubscribe();
        }
    }

    /** Fail everything that is waiting, because nothing more is coming. */
    private function fail(string $because): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $deferred) {
            if (!$deferred->isComplete()) {
                $deferred->error(new RpcError('closed', $because));
            }
        }

        foreach ($this->watchers as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->watchers = [];
    }

    /** Standard error on the end of a message, because that is where the reason usually is. */
    private function trailer(): string
    {
        $stderr = trim($this->stderr);

        return $stderr === '' ? '' : ". The agent said: {$stderr}";
    }

    private function cleanUp(): void
    {
        $this->fail('The client was stopped');

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if ($this->process !== null) {
            proc_close($this->process);
            $this->process = null;
        }

        $this->buffer = '';
    }
}
