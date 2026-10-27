<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use Pig\Async\Loop;
use RuntimeException;

/**
 * Gemini's developer API and Antigravity's Cloud Code Assist endpoint on one loopback port, enough
 * for `pig-gemini-video`: inline and resumable uploads, polling, delete, and Antigravity's
 * `generateContent` / `streamGenerateContent` with a quota wall and models that are not there.
 *
 * In-process, on pig's own loop, the way `FakeHttpMcpServer` is: one request per connection, read
 * whole by `Content-Length`, answered with `Connection: close`.
 */
final class FakeGeminiServer
{
    public const string KEY = 'test-key';

    public const string TOKEN = 'ag-token';

    public const string PROJECT = 'proj-1';

    /** @var list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** Antigravity's answer: `ok`, `nogc` (404 on `generateContent`), `quota` (429). */
    public string $antigravity = 'ok';

    /** Wire models Antigravity answers 404 for. */
    public array $missingModels = [];

    /** Bytes received through the resumable upload, in order. */
    public string $uploaded = '';

    public bool $deleted = false;

    private int $polls = 0;

    private string $uploadMime = '';

    private string $uploadName = '';

    /** @var resource */
    private $socket;

    private string $watcher;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr) ?: throw new RuntimeException("Cannot open test server: {$errstr}");
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->watcher = Loop::get()->onReadable($socket, fn ($listening) => $this->accept($listening));
    }

    public function url(string $prefix = ''): string
    {
        return 'http://' . stream_socket_get_name($this->socket, false) . $prefix;
    }

    public function stop(): void
    {
        Loop::get()->cancel($this->watcher);
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** @return list<string> "METHOD path" of every request */
    public function log(): array
    {
        return array_map(static fn (array $r): string => "{$r['method']} {$r['path']}", $this->requests);
    }

    private function accept(mixed $listening): void
    {
        $connection = stream_socket_accept($listening, 0);
        if ($connection === false) {
            return;
        }

        stream_set_blocking($connection, false);
        $buffer = '';
        $watcher = null;
        $watcher = Loop::get()->onReadable($connection, function ($peer) use (&$buffer, &$watcher): void {
            $data = fread($peer, 1 << 20);
            if (is_string($data)) {
                $buffer .= $data;
            }

            $end = strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                if (feof($peer)) {
                    Loop::get()->cancel($watcher);
                    fclose($peer);
                }

                return;
            }

            $head = substr($buffer, 0, $end);
            $headers = [];
            foreach (array_slice(explode("\r\n", $head), 1) as $line) {
                [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                $headers[strtolower(trim($name))] = trim($value);
            }

            $body = substr($buffer, $end + 4);
            if (strlen($body) < (int) ($headers['content-length'] ?? 0)) {
                return;
            }

            Loop::get()->cancel($watcher);
            [$method, $target] = explode(' ', explode("\r\n", $head)[0]);
            $this->requests[] = ['method' => $method, 'path' => $target, 'headers' => $headers, 'body' => $body];
            [$status, $extra, $reply] = $this->route($method, (string) parse_url($target, PHP_URL_PATH), $headers, $body);

            stream_set_blocking($peer, true);
            $extra = ['Content-Type' => 'application/json', ...$extra, 'Content-Length' => (string) strlen($reply), 'Connection' => 'close'];
            fwrite($peer, "HTTP/1.1 {$status} X\r\n" . implode('', array_map(static fn (string $k, string $v): string => "{$k}: {$v}\r\n", array_keys($extra), $extra)) . "\r\n" . $reply);
            fclose($peer);
        });
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    private function route(string $method, string $path, array $headers, string $body): array
    {
        if (str_starts_with($path, '/ag/')) {
            return $this->antigravityRoute(substr($path, 3), $headers, $body);
        }

        if (($headers['x-goog-api-key'] ?? '') !== self::KEY) {
            return self::json(403, ['error' => ['message' => 'API key not valid. Please pass a valid API key.']]);
        }

        if ($method === 'POST' && preg_match('#^/v1beta/models/([^:]+):generateContent$#', $path, $m) === 1) {
            if ($m[1] === 'bad-model') {
                return self::json(404, ['error' => ['message' => "models/{$m[1]} is not found"]]);
            }

            $parts = json_decode($body, true)['contents'][0]['parts'];
            $video = $parts[0];
            $what = isset($video['inline_data'])
                ? 'inline ' . $video['inline_data']['mime_type'] . ' ' . strlen((string) base64_decode($video['inline_data']['data'])) . ' bytes'
                : 'file ' . $video['file_data']['mime_type'] . ' ' . $video['file_data']['file_uri'];

            return self::json(200, ['candidates' => [['content' => ['parts' => [
                ['text' => 'thinking…', 'thought' => true],
                ['text' => "model={$m[1]}; {$what}; prompt={$parts[1]['text']}"],
            ]], 'finishReason' => 'STOP']]]);
        }

        if ($method === 'POST' && $path === '/upload/v1beta/files' && ($headers['x-goog-upload-command'] ?? '') === 'start') {
            $this->uploaded = '';
            $this->polls = 0;
            $this->uploadMime = $headers['x-goog-upload-header-content-type'] ?? '';
            $this->uploadName = json_decode($body, true)['file']['display_name'] ?? '';

            return self::json(200, [], ['X-Goog-Upload-URL' => $this->url('/upload-session/1')]);
        }

        if ($method === 'POST' && $path === '/upload-session/1') {
            if ((int) ($headers['x-goog-upload-offset'] ?? -1) !== strlen($this->uploaded)) {
                return self::json(400, ['error' => ['message' => 'bad offset']]);
            }

            $this->uploaded .= $body;

            return str_contains($headers['x-goog-upload-command'] ?? '', 'finalize')
                ? self::json(200, ['file' => $this->file('PROCESSING')])
                : self::json(200, []);
        }

        if ($method === 'GET' && $path === '/v1beta/files/abc') {
            $failing = str_contains($this->uploadName, 'broken');
            $state = ++$this->polls < 2 ? 'PROCESSING' : ($failing ? 'FAILED' : 'ACTIVE');

            return self::json(200, $this->file($state) + ($state === 'FAILED' ? ['error' => ['message' => 'video codec unsupported']] : []));
        }

        if ($method === 'DELETE' && $path === '/v1beta/files/abc') {
            $this->deleted = true;

            return self::json(200, []);
        }

        return self::json(404, ['error' => ['message' => "no route {$method} {$path}"]]);
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    private function antigravityRoute(string $path, array $headers, string $body): array
    {
        if (($headers['authorization'] ?? '') !== 'Bearer ' . self::TOKEN || !str_starts_with($headers['user-agent'] ?? '', 'antigravity/')) {
            return self::json(401, ['error' => ['message' => 'who are you']]);
        }

        $request = json_decode($body, true);
        $parts = $request['request']['contents'][0]['parts'] ?? [];
        if (($request['project'] ?? '') !== self::PROJECT || ($request['userAgent'] ?? '') !== 'antigravity' || !isset($parts[0]['inlineData'], $parts[1]['text'])) {
            return self::json(400, ['error' => ['message' => 'bad body shape']]);
        }

        if ($this->antigravity === 'quota') {
            return self::json(429, ['error' => ['message' => 'Individual quota reached. Resets in 3h (token Bearer ' . self::TOKEN . ' echoed)']]);
        }

        $model = $request['model'];
        if (in_array($model, $this->missingModels, true)) {
            return self::json(404, ['error' => ['message' => 'Requested entity was not found.']]);
        }

        $what = "model={$model}; {$parts[0]['inlineData']['mimeType']} " . strlen((string) base64_decode($parts[0]['inlineData']['data'])) . " bytes; prompt={$parts[1]['text']}";

        if ($path === '/v1internal:generateContent') {
            return $this->antigravity === 'nogc'
                ? self::json(404, ['error' => ['message' => 'method not found']])
                : self::json(200, ['response' => ['candidates' => [['content' => ['parts' => [['text' => 'hmm', 'thought' => true], ['text' => $what]]], 'finishReason' => 'STOP']]]]);
        }

        // `streamGenerateContent?alt=sse`: the answer in two events.
        $events = 'data: ' . json_encode(['response' => ['candidates' => [['content' => ['parts' => [['text' => 'stream: ']]]]]]]) . "\n\n"
            . 'data: ' . json_encode(['response' => ['candidates' => [['content' => ['parts' => [['text' => $what]]], 'finishReason' => 'STOP']]]]) . "\n\n";

        return [200, ['Content-Type' => 'text/event-stream'], $events];
    }

    /** @return array<string, string> */
    private function file(string $state): array
    {
        return ['name' => 'files/abc', 'uri' => $this->url('/v1beta/files/abc'), 'state' => $state, 'mimeType' => $this->uploadMime];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    private static function json(int $status, array $data, array $headers = []): array
    {
        return [$status, $headers, (string) json_encode($data)];
    }
}
