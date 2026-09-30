<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
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
}
