<?php

declare(strict_types=1);

namespace PigWebSearch;

use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Socket;
use RuntimeException;
use Throwable;

/**
 * A page rendered by the Chrome on this machine, read through the DevTools Protocol.
 *
 * `fetch_web_page` reads what the server sent, which for a growing share of the web is an empty
 * shell and a script that builds the page. This is the other half: launch the Chrome that is
 * already installed in headless mode, navigate, wait for the load event, and read the text out
 * of the live DOM. Nothing is downloaded and nothing is bundled — no Puppeteer, no driver, no
 * npm. The whole of what Puppeteer does for this one task is about two hundred lines, and they
 * are these.
 *
 * Three pieces, each the narrowest that answers the question:
 *
 * - **Launching**: `--remote-debugging-port=0` makes Chrome pick a free port and print
 *   `DevTools listening on ws://127.0.0.1:PORT/devtools/browser/…` on stderr, so there is no
 *   port to guess and no `/json` endpoint to poll. The line is read with the loop turning, so
 *   the UI stays live while Chrome starts.
 * - **A WebSocket client**: pig has a WebSocket *server* protocol for its Web UI; a client is
 *   the same frames the other way round, plus masking, which RFC 6455 requires of a client and
 *   forbids of a server. Written here rather than widened there, because masking is the whole
 *   difference and a flag on the server class would be a flag two callers could get backwards.
 * - **CDP over one socket**: `Target.createTarget` + `Target.attachToTarget(flatten)` gives a
 *   session id to put on every page command, so the browser-level socket from the stderr line
 *   is the only connection ever opened.
 *
 * Chrome is **optional**. `locate()` answers null where none is installed, and the tool says so
 * rather than failing three layers down — a machine without a browser is a capability, not an
 * error, which is `Process::capture()`'s rule too.
 */
final class HeadlessBrowser
{
    /** Where Chrome lives, by platform; first hit wins. */
    private const array CANDIDATES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
        '/usr/bin/microsoft-edge',
    ];

    /** How long Chrome gets to print its DevTools line. */
    private const float LAUNCH_TIMEOUT = 15.0;

    /** @var resource */
    private $process;

    private Socket $socket;

    private string $profile;

    private int $nextId = 0;

    /** Frames read off the socket and not yet claimed by anyone, oldest first. */
    private string $buffer = '';

    /** @var list<array<string, mixed>> events that arrived while a command was being awaited */
    private array $events = [];

    private function __construct()
    {
    }

    /** The Chrome binary, or null when this machine has none. `CHROME_PATH` wins when it is set. */
    public static function locate(): ?string
    {
        $env = getenv('CHROME_PATH');

        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }

        foreach (self::CANDIDATES as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        foreach (['google-chrome', 'chromium', 'chromium-browser', 'chrome'] as $name) {
            $found = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

            if ($found !== '' && is_executable($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Launch Chrome and connect to it. Always pair with `close()`, in a `finally`.
     *
     * @throws RuntimeException when there is no Chrome, or it did not come up
     */
    public static function launch(?AbortSignal $signal = null, ?string $binary = null): self
    {
        $binary ??= self::locate();

        if ($binary === null) {
            throw new RuntimeException(
                'No Chrome or Chromium is installed on this machine, so the page cannot be rendered. '
                . 'Install Google Chrome (or set CHROME_PATH), or use fetch_web_page for the server-side HTML.',
            );
        }

        $browser = new self();
        $browser->profile = sys_get_temp_dir() . '/pig-chrome-' . bin2hex(random_bytes(6));

        $command = [
            $binary,
            '--headless=new',
            '--remote-debugging-port=0',
            '--user-data-dir=' . $browser->profile,
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-gpu',
            '--disable-extensions',
            '--disable-background-networking',
            '--hide-scrollbars',
            '--mute-audio',
            '--window-size=1280,900',
            'about:blank',
        ];

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start {$binary}");
        }

        $browser->process = $process;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            $endpoint = $browser->awaitDevToolsLine($pipes[2], $pipes[1], $signal);
            $browser->connect($endpoint, $signal);
        } catch (Throwable $error) {
            $browser->close();

            throw $error;
        }

        return $browser;
    }

    /**
     * Render a page and read its text.
     *
     * @return array{title: string, url: string, text: string}
     */
    public function read(string $url, float $timeout = 25.0, ?AbortSignal $signal = null): array
    {
        $target = $this->command('Target.createTarget', ['url' => 'about:blank']);
        $targetId = (string) ($target['targetId'] ?? '');
        $attached = $this->command('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true]);
        $session = (string) ($attached['sessionId'] ?? '');

        if ($session === '') {
            throw new RuntimeException('Chrome did not hand back a page session.');
        }

        $this->command('Page.enable', [], $session);
        $navigated = $this->command('Page.navigate', ['url' => $url], $session);

        if (isset($navigated['errorText']) && $navigated['errorText'] !== '') {
            throw new RuntimeException("Chrome could not load {$url}: {$navigated['errorText']}");
        }

        $this->awaitEvent('Page.loadEventFired', $session, $timeout, $signal);

        // The load event is the HTML and its synchronous scripts; a framework that fetches its
        // data afterwards is still drawing. Half a second is what Puppeteer users wait too, and
        // it is bounded rather than "network idle", which some pages never reach.
        Async::delay(0.5);

        $script = <<<'JS'
            (() => {
                for (const el of document.querySelectorAll('script,style,noscript,svg,canvas,iframe,template,nav,header,footer,aside,[aria-hidden="true"]')) {
                    el.remove();
                }
                return JSON.stringify({
                    title: document.title,
                    url: location.href,
                    text: document.body ? document.body.innerText : '',
                });
            })()
            JS;

        $result = $this->command('Runtime.evaluate', ['expression' => $script, 'returnByValue' => true], $session);
        $decoded = json_decode((string) ($result['result']['value'] ?? ''), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Chrome rendered the page but its text could not be read back.');
        }

        try {
            $this->command('Target.closeTarget', ['targetId' => $targetId]);
        } catch (Throwable) {
            // The whole browser is about to be closed; a page that would not close first is nothing.
        }

        return [
            'title' => (string) ($decoded['title'] ?? ''),
            'url' => (string) ($decoded['url'] ?? $url),
            'text' => self::tidy((string) ($decoded['text'] ?? '')),
        ];
    }

    /** Stop Chrome and remove its throwaway profile. Safe to call twice. */
    public function close(): void
    {
        if (isset($this->socket) && !$this->socket->isClosed()) {
            try {
                $this->send(['id' => ++$this->nextId, 'method' => 'Browser.close']);
            } catch (Throwable) {
                // Killed below either way.
            }

            $this->socket->close();
        }

        if (isset($this->process) && is_resource($this->process)) {
            // Chrome takes its renderers down with it on SIGTERM, which is more than can be
            // said for a shell — see `Shell::killTree()` for the general case this is not.
            proc_terminate($this->process);
            proc_close($this->process);
        }

        if (isset($this->profile) && is_dir($this->profile)) {
            self::remove($this->profile);
        }
    }

    // ---- launching ------------------------------------------------------------------------------

    /**
     * @param resource $stderr
     * @param resource $stdout
     */
    private function awaitDevToolsLine($stderr, $stdout, ?AbortSignal $signal): string
    {
        $deadline = microtime(true) + self::LAUNCH_TIMEOUT;
        $seen = '';

        while (microtime(true) < $deadline) {
            $signal?->throwIfAborted();

            $chunk = fread($stderr, 65536);
            $seen .= is_string($chunk) ? $chunk : '';
            // stdout is drained too, or a chatty Chrome blocks on a full pipe nobody reads.
            fread($stdout, 65536);

            if (preg_match('#DevTools listening on (ws://\S+)#', $seen, $m) === 1) {
                return $m[1];
            }

            $status = proc_get_status($this->process);

            if (!$status['running']) {
                throw new RuntimeException('Chrome exited before it was ready: ' . trim(substr($seen, -600)));
            }

            Async::delay(0.05);
        }

        throw new RuntimeException('Chrome did not become ready within ' . self::LAUNCH_TIMEOUT . 's.');
    }

    private function connect(string $endpoint, ?AbortSignal $signal): void
    {
        $parts = parse_url($endpoint);
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 9222);
        $path = (string) ($parts['path'] ?? '/');

        $this->socket = Socket::connect($host, $port, false, 10.0, $signal);

        $key = base64_encode(random_bytes(16));
        $this->socket->write(
            "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n",
            10.0,
            $signal,
        );

        $head = '';

        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = $this->socket->read(8192, 10.0, $signal);

            if ($chunk === null) {
                throw new RuntimeException('Chrome closed the DevTools connection during the handshake.');
            }

            $head .= $chunk;
        }

        [$response, $rest] = explode("\r\n\r\n", $head, 2);

        if (!str_starts_with($response, 'HTTP/1.1 101')) {
            throw new RuntimeException('Chrome refused the DevTools WebSocket: ' . strtok($response, "\r\n"));
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (!str_contains($response, $expected)) {
            throw new RuntimeException('Chrome answered the WebSocket handshake with the wrong accept key.');
        }

        $this->buffer = $rest;
    }

    // ---- CDP ------------------------------------------------------------------------------------

    /**
     * Send a command and wait for its reply; events that arrive meanwhile are kept for `awaitEvent()`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> the `result` object
     */
    private function command(string $method, array $params = [], ?string $session = null, float $timeout = 15.0): array
    {
        $id = ++$this->nextId;
        $message = ['id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];

        if ($session !== null) {
            $message['sessionId'] = $session;
        }

        $this->send($message);
        $deadline = microtime(true) + $timeout;

        while (true) {
            $incoming = $this->receive(max(0.1, $deadline - microtime(true)));

            if (($incoming['id'] ?? null) === $id) {
                if (isset($incoming['error'])) {
                    $error = $incoming['error'];

                    throw new RuntimeException("{$method} failed: " . (is_array($error) ? ($error['message'] ?? 'unknown error') : 'unknown error'));
                }

                return is_array($incoming['result'] ?? null) ? $incoming['result'] : [];
            }

            if (isset($incoming['method'])) {
                $this->events[] = $incoming;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("{$method}: Chrome did not answer within {$timeout}s.");
            }
        }
    }

    /** @return array<string, mixed> the event */
    private function awaitEvent(string $method, string $session, float $timeout, ?AbortSignal $signal): array
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            foreach ($this->events as $at => $event) {
                if (($event['method'] ?? null) === $method && ($event['sessionId'] ?? null) === $session) {
                    unset($this->events[$at]);

                    return $event;
                }
            }

            $signal?->throwIfAborted();

            if (microtime(true) > $deadline) {
                throw new RuntimeException("The page did not finish loading within {$timeout}s.");
            }

            $incoming = $this->receive(max(0.1, $deadline - microtime(true)), $signal);

            if (isset($incoming['method'])) {
                $this->events[] = $incoming;
            }
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $payload = json_encode($message, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Could not encode a DevTools command.');
        }

        $this->socket->write(self::clientFrame($payload), 10.0);
    }

    /**
     * The next complete text message from Chrome, fragments reassembled, control frames answered.
     *
     * @return array<string, mixed>
     */
    private function receive(float $timeout, ?AbortSignal $signal = null): array
    {
        $message = '';

        while (true) {
            $frame = $this->nextFrame($timeout, $signal);

            switch ($frame['opcode']) {
                case 0x9: // ping
                    $this->socket->write(self::clientFrame($frame['payload'], 0xA), 10.0);
                    break;
                case 0x8:
                    throw new RuntimeException('Chrome closed the DevTools connection.');
                case 0x1:
                case 0x0:
                    $message .= $frame['payload'];

                    if ($frame['fin']) {
                        $decoded = json_decode($message, true);

                        if (!is_array($decoded)) {
                            throw new RuntimeException('Chrome sent a DevTools message that is not JSON.');
                        }

                        return $decoded;
                    }
                    break;
                default:
                    // Binary and anything reserved: CDP never sends them; skipped rather than fatal.
                    break;
            }
        }
    }

    /** @return array{fin: bool, opcode: int, payload: string} */
    private function nextFrame(float $timeout, ?AbortSignal $signal): array
    {
        while (true) {
            $frame = self::parseFrame($this->buffer);

            if ($frame !== null) {
                [$parsed, $used] = $frame;
                $this->buffer = substr($this->buffer, $used);

                return $parsed;
            }

            $chunk = $this->socket->read(1 << 20, $timeout, $signal);

            if ($chunk === null) {
                throw new RuntimeException('Chrome closed the DevTools connection.');
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * One unmasked server frame off the front of $buffer, or null when it is not all there yet.
     *
     * @return array{0: array{fin: bool, opcode: int, payload: string}, 1: int}|null
     */
    private static function parseFrame(string $buffer): ?array
    {
        $length = strlen($buffer);

        if ($length < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $masked = ($second & 0x80) !== 0;
        $size = $second & 0x7F;
        $offset = 2;

        if ($size === 126) {
            if ($length < 4) {
                return null;
            }

            $size = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($size === 127) {
            if ($length < 10) {
                return null;
            }

            $size = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            $offset += 4;
        }

        if ($length < $offset + $size) {
            return null;
        }

        $payload = substr($buffer, $offset, $size);

        if ($masked) {
            // A server must not mask, and Chrome does not; handled anyway so a frame that did
            // would be read rather than fed to json_decode as noise.
            $mask = substr($buffer, $offset - 4, 4);
            $unmasked = '';

            for ($i = 0; $i < $size; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }

            $payload = $unmasked;
        }

        return [['fin' => ($first & 0x80) !== 0, 'opcode' => $first & 0x0F, 'payload' => $payload], $offset + $size];
    }

    /** A masked client frame, as RFC 6455 §5.3 requires of anything a client sends. */
    public static function clientFrame(string $payload, int $opcode = 0x1): string
    {
        $size = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($size < 126) {
            $head .= chr(0x80 | $size);
        } elseif ($size < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $size);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $size);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $size; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $head . $mask . $masked;
    }

    // ---- text -----------------------------------------------------------------------------------

    /** Collapse the runs of blank lines `innerText` leaves where blocks were. */
    private static function tidy(string $text): string
    {
        $lines = [];
        $blank = false;

        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = rtrim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);

            if ($line === '') {
                if (!$blank) {
                    $lines[] = '';
                    $blank = true;
                }

                continue;
            }

            $lines[] = $line;
            $blank = false;
        }

        return trim(implode("\n", $lines));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            // A browser that has just been told to quit is still writing into its profile for
            // a moment: scandir() and rmdir() both warn on a directory that changes under
            // them. The retry loop is the answer to that, not the warning, so the handler
            // covers the whole attempt.
            set_error_handler(static fn (): bool => true);
            try {
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    foreach (scandir($path) ?: [] as $entry) {
                        if ($entry !== '.' && $entry !== '..') {
                            self::remove($path . '/' . $entry);
                        }
                    }

                    if (rmdir($path) || !is_dir($path)) {
                        return;
                    }

                    usleep(20000);
                }
            } finally {
                restore_error_handler();
            }

            return;
        }

        if (file_exists($path) || is_link($path)) {
            // Best-effort cleanup of a profile directory the browser may still be touching.
            set_error_handler(static fn (): bool => true);
            try {
                unlink($path);
            } finally {
                restore_error_handler();
            }
        }
    }
}
