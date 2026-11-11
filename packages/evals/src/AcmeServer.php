<?php

declare(strict_types=1);

namespace Pig\Evals;

use Pig\Async\Loop;
use RuntimeException;
use stdClass;

/**
 * Upstream's `packages/evals/evals/acme-server.ts`: loopback fixture server for the OpenAI-compatible
 * and custom streaming provider evals.
 */
final class AcmeServer
{
    public const string OPENAI_PROVIDER_ID = 'acme';

    public const string OPENAI_MODEL_ID = 'acme-chat';

    public const string OPENAI_PROBE_PROMPT = 'Reply with ACME_OK.';

    public const string OPENAI_PROBE_RESPONSE = 'ACME_OK';

    public const string STREAM_PROVIDER_ID = 'acme-stream';

    public const string STREAM_MODEL_ID = 'acme-stream-chat';

    public const string STREAM_PROBE_PROMPT = 'Reply with ACME_STREAM_OK.';

    public const string STREAM_PROBE_RESPONSE = 'ACME_STREAM_OK';

    public const string STREAM_API_DOCUMENTATION = <<<'JSON'
{
  "name": "Acme Streaming API",
  "request": {
    "method": "POST",
    "path": "/generate",
    "headers": {
      "content-type": "application/json",
      "x-acme-key": "resolved credential"
    },
    "body": {
      "model": "acme-stream-chat",
      "messages": [
        {
          "role": "user",
          "content": "Hello"
        }
      ],
      "stream": true
    }
  },
  "response": {
    "contentType": "application/x-ndjson",
    "events": [
      {
        "type": "text_delta",
        "text": "Hello"
      },
      {
        "type": "usage",
        "input_tokens": 3,
        "output_tokens": 2
      },
      {
        "type": "done",
        "reason": "stop"
      }
    ]
  }
}

JSON;

    /** @var list<resource> */
    private array $open = [];

    /** @var list<string> */
    private array $watchers = [];

    private string $serverOrigin = '';

    private bool $receivedValidRequest = false;

    /**
     * @param 'openai'|'stream' $mode
     */
    public function __construct(private readonly string $mode)
    {
    }

    /**
     * @param 'openai'|'stream' $mode
     */
    public static function create(string $mode): self
    {
        return new self($mode);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function start(): void
    {
        $this->stop();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Acme fixture server did not bind a TCP port: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $buffer = '';
            $reader = null;
            $reader = Loop::get()->onReadable($connection, function ($peer) use (&$buffer, &$reader): void {
                $data = fread($peer, 65536);

                if ($data === false || $data === '') {
                    if (feof($peer)) {
                        Loop::get()->cancel((string) $reader);
                    }

                    return;
                }

                $buffer .= $data;
                $split = strpos($buffer, "\r\n\r\n");

                if ($split === false) {
                    return;
                }

                $head = substr($buffer, 0, $split);
                $lines = explode("\r\n", $head);
                $headers = [];

                foreach (array_slice($lines, 1) as $line) {
                    [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                    $headers[strtolower($name)] = $value;
                }

                $body = substr($buffer, $split + 4);

                if (strlen($body) < (int) ($headers['content-length'] ?? 0)) {
                    return;
                }

                Loop::get()->cancel((string) $reader);
                [$method, $target] = explode(' ', $lines[0]) + [1 => '/'];
                $this->handle($peer, $method, $target, $headers, $body);
            });
            $this->watchers[] = (string) $reader;
        });

        $name = (string) stream_socket_get_name($server, false);
        $this->serverOrigin = 'http://' . $name;
    }

    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->watchers = [];

        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->open = [];
    }

    public function reset(): void
    {
        $this->receivedValidRequest = false;
    }

    public function origin(): string
    {
        return $this->serverOrigin;
    }

    public function baseUrl(): string
    {
        return $this->serverOrigin . '/v1';
    }

    public function validRequestReceived(): bool
    {
        return $this->receivedValidRequest;
    }

    /**
     * @param resource $peer
     * @param array<string, string> $headers
     */
    private function handle(mixed $peer, string $method, string $target, array $headers, string $body): void
    {
        $expectedPath = $this->mode === 'openai' ? '/v1/chat/completions' : '/generate';

        if ($target !== $expectedPath) {
            self::reject($peer, 404, 'Unknown endpoint');

            return;
        }

        if ($method !== 'POST') {
            self::reject($peer, 405, 'Expected POST');

            return;
        }

        if (!str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            self::reject($peer, 415, 'Expected application/json');

            return;
        }

        try {
            $object = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::reject($peer, 400, 'Invalid JSON');

            return;
        }

        if (!$object instanceof stdClass || !is_array($payload)) {
            self::reject($peer, 422, 'Expected a JSON object');

            return;
        }

        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $prompt = self::extractUserPrompt($messages);

        if ($this->mode === 'stream') {
            if (($headers['x-acme-key'] ?? null) !== 'resolved-stream-key') {
                self::reject($peer, 401, 'Invalid Acme Stream credential');

                return;
            }

            if (($payload['model'] ?? null) !== self::STREAM_MODEL_ID || $prompt === null || ($payload['stream'] ?? null) !== true) {
                self::reject($peer, 422, 'Invalid Acme Stream request');

                return;
            }

            $this->receivedValidRequest = $prompt === self::STREAM_PROBE_PROMPT;
            $ndjson = json_encode(['type' => 'text_delta', 'text' => 'ACME_'], JSON_THROW_ON_ERROR) . "\n"
                . json_encode(['type' => 'text_delta', 'text' => 'STREAM_OK'], JSON_THROW_ON_ERROR) . "\n"
                . json_encode(['type' => 'usage', 'input_tokens' => 4, 'output_tokens' => 3], JSON_THROW_ON_ERROR) . "\n"
                . json_encode(['type' => 'done', 'reason' => 'stop'], JSON_THROW_ON_ERROR) . "\n";
            self::respond($peer, 200, ['content-type' => 'application/x-ndjson'], $ndjson);

            return;
        }

        if (($headers['authorization'] ?? null) !== 'Bearer resolved-acme-key') {
            self::reject($peer, 401, 'Invalid Acme credential');

            return;
        }

        if (($payload['model'] ?? null) !== self::OPENAI_MODEL_ID || $prompt === null || ($payload['stream'] ?? null) !== true) {
            self::reject($peer, 422, 'Invalid OpenAI-compatible request');

            return;
        }

        $this->receivedValidRequest = $prompt === self::OPENAI_PROBE_PROMPT;
        $chunk1 = json_encode([
            'id' => 'chatcmpl-acme',
            'object' => 'chat.completion.chunk',
            'created' => 0,
            'model' => self::OPENAI_MODEL_ID,
            'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => self::OPENAI_PROBE_RESPONSE], 'finish_reason' => null]],
        ], JSON_THROW_ON_ERROR);
        $chunk2 = json_encode([
            'id' => 'chatcmpl-acme',
            'object' => 'chat.completion.chunk',
            'created' => 0,
            'model' => self::OPENAI_MODEL_ID,
            'choices' => [['index' => 0, 'delta' => new stdClass(), 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);
        $sse = "data: {$chunk1}\n\ndata: {$chunk2}\n\ndata: [DONE]\n\n";
        self::respond($peer, 200, ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache'], $sse);
    }

    /**
     * @param list<mixed> $messages
     */
    private static function extractUserPrompt(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? null;

            if (is_string($content)) {
                return $content;
            }

            if (is_array($content) && $content !== []) {
                $parts = [];

                foreach ($content as $part) {
                    if (!is_array($part) || ($part['type'] ?? null) !== 'text' || !is_string($part['text'] ?? null)) {
                        return null;
                    }

                    $parts[] = $part['text'];
                }

                return implode('', $parts);
            }

            return null;
        }

        return null;
    }

    /**
     * @param resource $peer
     */
    private static function reject(mixed $peer, int $status, string $message): void
    {
        self::respond($peer, $status, ['content-type' => 'text/plain'], $message);
    }

    /**
     * @param resource $peer
     * @param array<string, string> $headers
     */
    private static function respond(mixed $peer, int $status, array $headers, string $body): void
    {
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            default => 'Error',
        };
        $out = "HTTP/1.1 {$status} {$reason}\r\n";

        foreach ($headers as $name => $value) {
            $out .= "{$name}: {$value}\r\n";
        }

        $out .= 'content-length: ' . strlen($body) . "\r\nconnection: close\r\n\r\n" . $body;
        fwrite($peer, $out);
        fclose($peer);
    }
}
