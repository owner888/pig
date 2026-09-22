<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\SseParser;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;

final class HttpClientTest extends TestCase
{
    use AssertsThrows;

    /** @var list<resource> */
    private array $open = [];

    private string $received = '';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->open = [];
        $this->received = '';
    }

    public function testReadsStatusHeadersAndBody(): void
    {
        $url = $this->serve(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 13\r\n\r\n{\"ok\":true}\r\n"]);

        $result = Async::run(static function () use ($url): array {
            $response = (new HttpClient())->send(new Request('GET', $url));

            return [$response->status, $response->reason, $response->header('content-type'), $response->body->all()];
        });

        $this->assertSame(200, $result[0]);
        $this->assertSame('OK', $result[1]);
        $this->assertSame('application/json', $result[2]);
        $this->assertSame("{\"ok\":true}\r\n", $result[3]);
    }

    public function testSendsAWellFormedRequest(): void
    {
        $url = $this->serve(["HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n"]);

        Async::run(static function () use ($url): void {
            (new HttpClient())->send(new Request(
                'POST',
                $url . 'v1/messages?beta=true',
                ['X-Api-Key' => 'secret', 'Content-Type' => 'application/json'],
                '{"model":"x"}',
            ))->body->all();
        });

        $head = strstr($this->received, "\r\n\r\n", true);

        $this->assertStringContainsString('POST /v1/messages?beta=true HTTP/1.1', (string) $head);
        $this->assertStringContainsString('x-api-key: secret', (string) $head);
        $this->assertStringContainsString('content-length: 13', (string) $head);
        $this->assertStringContainsString('connection: close', (string) $head);
        // Nothing here decompresses, so the server must not be left to decide.
        $this->assertStringContainsString('accept-encoding: identity', (string) $head);
        $this->assertStringContainsString('{"model":"x"}', $this->received);
    }

    public function testJoinsRepeatedHeaders(): void
    {
        $url = $this->serve(["HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Length: 0\r\n\r\n"]);

        $header = Async::run(static function () use ($url): ?string {
            $response = (new HttpClient())->send(new Request('GET', $url));
            $response->body->all();

            return $response->header('set-cookie');
        });

        $this->assertSame('a=1, b=2', $header);
    }

    public function testStreamsAChunkedBodyAsItArrives(): void
    {
        $url = $this->serve([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
            "5\r\nHello\r\n",
            "6\r\n world\r\n",
            "0\r\n\r\n",
        ]);

        $pieces = Async::run(static function () use ($url): array {
            $response = (new HttpClient())->send(new Request('GET', $url));
            $seen = [];

            foreach ($response->body as $piece) {
                $seen[] = $piece;
            }

            return $seen;
        });

        $this->assertSame(['Hello', ' world'], $pieces);
    }

    public function testReadsABodyThatEndsWhenTheConnectionCloses(): void
    {
        // No Content-Length and no chunking: the hang-up is the only frame marker.
        $url = $this->serve(["HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n", 'no length header here']);

        $body = Async::run(static fn (): string => (new HttpClient())->send(new Request('GET', $url))->body->all());

        $this->assertSame('no length header here', $body);
    }

    public function testRejectsAMalformedStatusLine(): void
    {
        $url = $this->serve(["I am not HTTP\r\n\r\n"]);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient())->send(new Request('GET', $url))),
            'Malformed status line',
        );
    }

    public function testABodyShorterThanPromisedIsAnError(): void
    {
        $url = $this->serve(["HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n", 'only twelve']);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(
                static fn () => (new HttpClient())->send(new Request('GET', $url))->body->all(),
            ),
            'of 100 promised bytes',
        );
    }

    public function testAbortingStopsTheBodyMidStream(): void
    {
        $url = $this->serve([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
            "5\r\nHello\r\n",
            // …and then nothing, ever.
        ], closeAfter: false);

        $error = $this->assertThrows(AbortError::class, static function () use ($url): void {
            Async::run(static function () use ($url): void {
                $controller = new AbortController();
                $response = (new HttpClient())->send(new Request('GET', $url), $controller->signal);
                Loop::get()->delay(0.05, static fn () => $controller->abort('user pressed esc'));

                foreach ($response->body as $piece) {
                    // Drain: the first chunk arrives, the second never does.
                }
            });
        });

        $this->assertSame('user pressed esc', $error->getMessage());
    }

    public function testAnSseStreamComesOutAsEvents(): void
    {
        $body = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
            . "event: content_block_delta\ndata: {\"text\":\"Hi\"}\n\n";

        $url = $this->serve([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            sprintf("%x\r\n%s\r\n", strlen($body), $body),
            "0\r\n\r\n",
        ]);

        $events = Async::run(static function () use ($url): array {
            $response = (new HttpClient())->send(new Request('GET', $url));
            $parser = new SseParser();
            $seen = [];

            foreach ($response->body as $piece) {
                foreach ($parser->feed($piece) as $event) {
                    $seen[] = "{$event->type}|{$event->data}";
                }
            }

            return $seen;
        });

        $this->assertSame([
            'message_start|{"type":"message_start"}',
            'content_block_delta|{"text":"Hi"}',
        ], $events);
    }

    /**
     * A server that replies with canned bytes, one piece every 10ms.
     *
     * @param list<string> $pieces
     * @return string base URL, with a trailing slash
     */
    private function serve(array $pieces, bool $closeAfter = true): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new HttpError("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        Loop::get()->onReadable($server, function ($listening) use ($pieces, $closeAfter): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $reader = null;

            $reader = Loop::get()->onReadable($connection, function ($peer) use ($pieces, $closeAfter, &$reader): void {
                $data = fread($peer, 65536);

                if ($data === false || $data === '') {
                    return;
                }

                $this->received .= $data;

                // Reply once the whole request head is in.
                if (!str_contains($this->received, "\r\n\r\n")) {
                    return;
                }

                Loop::get()->cancel((string) $reader);

                foreach ($pieces as $index => $piece) {
                    Loop::get()->delay(0.01 * ($index + 1), static function () use ($peer, $piece): void {
                        fwrite($peer, $piece);
                    });
                }

                if ($closeAfter) {
                    Loop::get()->delay(0.01 * (count($pieces) + 1), static function () use ($peer): void {
                        fclose($peer);
                    });
                }
            });
        });

        $name = stream_socket_get_name($server, false);
        $separator = strrpos((string) $name, ':');

        return 'http://' . substr((string) $name, 0, $separator) . ':' . substr((string) $name, $separator + 1) . '/';
    }
}
