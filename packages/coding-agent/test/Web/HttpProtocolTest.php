<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\TcpConnection;

final class HttpProtocolTest extends TestCase
{
    public function testInputDetectsCompleteAndIncompleteHttpRequests(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        // 1. Incomplete header
        $this->assertSame(0, Http::input("GET / HTTP/1.1\r\nHost: local", $conn));

        // 2. Complete GET without body
        $getReq = "GET /api/state HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n";
        $this->assertSame(strlen($getReq), Http::input($getReq, $conn));

        // 3. POST with Content-Length (incomplete body)
        $postHead = "POST /api/prompt HTTP/1.1\r\nContent-Length: 10\r\n\r\n";
        $this->assertSame(0, Http::input($postHead . "12345", $conn));

        // 4. POST with complete body
        $postFull = $postHead . "0123456789";
        $this->assertSame(strlen($postFull), Http::input($postFull, $conn));

        $conn->close();
        fclose($clientSock);
    }

    public function testDecodeParsesMethodPathHeadersAndBody(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        $raw = "POST /api/sessions?cwd=%2Ftmp HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\n\r\n{\"key\":\"val\"}";
        $req = Http::decode($raw, $conn);

        $this->assertIsArray($req);
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/sessions', $req['path']);
        $this->assertSame(['cwd' => '/tmp'], $req['query']);
        $this->assertSame('127.0.0.1', $req['headers']['host']);
        $this->assertSame('application/json', $req['headers']['content-type']);
        $this->assertSame('{"key":"val"}', $req['body']);

        $conn->close();
        fclose($clientSock);
    }

    public function testEncodeBuildsValidHttpResponseString(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        $response = Http::encode([
            'status' => 200,
            'headers' => ['X-Custom' => 'Pig'],
            'body' => 'OK',
        ], $conn);

        $this->assertStringContainsString("HTTP/1.1 200 OK\r\n", $response);
        $this->assertStringContainsString("X-Custom: Pig\r\n", $response);
        $this->assertStringContainsString("Content-Length: 2\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\nOK", $response);

        $conn->close();
        fclose($clientSock);
    }

    public function testDefaultSpawnDoesNotPassSessionForNonExistentFile(): void
    {
        $server = new HttpServer(sys_get_temp_dir(), login: \Pig\CodingAgent\Web\WebLogin::off());
        $pool = $server->pool();

        $ref = new \ReflectionClass($pool);
        $spawnProp = $ref->getProperty('spawn');
        $spawnFn = $spawnProp->getValue($pool);

        /** @var \Pig\CodingAgent\Rpc\RpcClient $client */
        $client = $spawnFn(sys_get_temp_dir(), 'non-existent-session-2026-10-04.jsonl');

        $clientRef = new \ReflectionClass($client);
        $argsProp = $clientRef->getProperty('arguments');
        $args = $argsProp->getValue($client);

        $this->assertNotContains('--session', $args);
    }

    public function testEncodeGzipCompressesTextAndJsonResponsesWhenAccepted(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);
        $conn->httpGzip = true;

        $body = str_repeat('{"id":"session-1","opening":"hello world"},', 30);
        $response = Http::encode([
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ], $conn);

        $this->assertStringContainsString("Content-Encoding: gzip\r\n", $response);
        $this->assertStringContainsString("Vary: Accept-Encoding\r\n", $response);
        $rawBody = substr($response, strpos($response, "\r\n\r\n") + 4);
        $this->assertLessThan(strlen($body) / 4, strlen($rawBody));
        $this->assertSame($body, gzdecode($rawBody));

        $conn->close();
        fclose($clientSock);
    }

    public function testSlimForBrowserStripsRedundantLivePayloads(): void
    {
        $slim = new \ReflectionMethod(HttpServer::class, 'slimForBrowser');

        $this->assertNull($slim->invoke(null, ['type' => 'entry_appended', 'entry' => ['id' => 'e1']]));
        $this->assertNull($slim->invoke(null, ['type' => 'turn_end', 'message' => [], 'toolResults' => []]));
        $this->assertNull($slim->invoke(null, ['type' => 'message_update', 'assistantMessageEvent' => ['type' => 'done', 'message' => []]]));

        $delta = $slim->invoke(null, [
            'type' => 'message_update',
            'usage' => ['input' => 100],
            'assistantMessageEvent' => ['type' => 'text_delta', 'delta' => 'hi'],
        ]);
        $this->assertArrayNotHasKey('usage', $delta);
        $this->assertSame('hi', $delta['assistantMessageEvent']['delta']);

        $agentEnd = $slim->invoke(null, ['type' => 'agent_end', 'messages' => [['role' => 'assistant']], 'willRetry' => false]);
        $this->assertArrayNotHasKey('messages', $agentEnd);
        $this->assertFalse($agentEnd['willRetry']);

        $toolEnd = $slim->invoke(null, [
            'type' => 'message_end',
            'message' => ['role' => 'toolResult', 'content' => [['type' => 'image', 'data' => str_repeat('A', 10000)]]],
        ]);
        $this->assertSame(['type' => 'message_end', 'message' => ['role' => 'toolResult']], $toolEnd);

        $assistantEnd = $slim->invoke(null, [
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'stopReason' => 'toolUse',
                'content' => [
                    ['type' => 'thinking', 'thinking' => str_repeat('T', 5000), 'thinkingSignature' => 'SIG'],
                    ['type' => 'toolCall', 'id' => 'c1', 'name' => 'read', 'arguments' => ['path' => 'a.php']],
                ],
            ],
        ]);
        $this->assertSame([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'stopReason' => 'toolUse',
                'content' => [['type' => 'toolCall']],
            ],
        ], $assistantEnd);
    }
}
