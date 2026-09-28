<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\Attributes\DataProvider;
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
use Pig\Test\CannedServer;

final class HttpClientTest extends TestCase
{
    use AssertsThrows;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    public function testReadsStatusHeadersAndBody(): void
    {
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 13\r\n\r\n{\"ok\":true}\r\n"]);

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
        $url = $this->server->start(["HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n"]);

        Async::run(static function () use ($url): void {
            (new HttpClient())->send(new Request(
                'POST',
                $url . 'v1/messages?beta=true',
                ['X-Api-Key' => 'secret', 'Content-Type' => 'application/json'],
                '{"model":"x"}',
            ))->body->all();
        });

        $head = $this->server->receivedHead();

        $this->assertStringContainsString('POST /v1/messages?beta=true HTTP/1.1', $head);
        $this->assertStringContainsString('x-api-key: secret', $head);
        $this->assertStringContainsString('content-length: 13', $head);
        $this->assertStringContainsString('connection: close', $head);
        // Nothing here decompresses, so the server must not be left to decide.
        $this->assertStringContainsString('accept-encoding: identity', $head);
        $this->assertStringContainsString('{"model":"x"}', $this->server->received());
    }

    public function testJoinsRepeatedHeaders(): void
    {
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Length: 0\r\n\r\n"]);

        $header = Async::run(static function () use ($url): ?string {
            $response = (new HttpClient())->send(new Request('GET', $url));
            $response->body->all();

            return $response->header('set-cookie');
        });

        $this->assertSame('a=1, b=2', $header);
    }

    public function testStreamsAChunkedBodyAsItArrives(): void
    {
        $url = $this->server->start([
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
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n", 'no length header here']);

        $body = Async::run(static fn (): string => (new HttpClient())->send(new Request('GET', $url))->body->all());

        $this->assertSame('no length header here', $body);
    }

    public function testRejectsAMalformedStatusLine(): void
    {
        $url = $this->server->start(["I am not HTTP\r\n\r\n"]);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient())->send(new Request('GET', $url))),
            'Malformed status line',
        );
    }

    public function testABodyShorterThanPromisedIsAnError(): void
    {
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n", 'only twelve']);

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
        $url = $this->server->start([
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

        $url = $this->server->start([
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

    // ---- following a redirect ----------------------------------------------------
    //
    // `follow()` also has four cases in `ToolInstallerTest`, where the method was added
    // for the download. What is here is what neither of the two callers happened to
    // exercise, and the rules `follow()`'s own docblock states.

    /**
     * @param 'GET'|'POST' $method    what the second request should be made with
     * @param bool         $keepsBody whether the body should still be attached to it
     */
    #[DataProvider('redirectsAndWhatTheyDoToTheMethod')]
    public function testARedirectDecidesWhetherTheMethodAndItsBodySurvive(
        int $status,
        string $method,
        bool $keepsBody,
    ): void {
        $target = new CannedServer();
        $targetUrl = $target->start(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"]);

        $url = $this->server->start(
            ["HTTP/1.1 {$status} Redirect\r\nLocation: {$targetUrl}\r\nContent-Length: 0\r\n\r\n"],
        );

        $body = Async::run(static fn (): string => (new HttpClient(5.0))
            ->follow(new Request('POST', $url, ['Content-Type' => 'application/json'], '{"a":1}'))
            ->body->all());

        $this->assertSame('ok', $body);
        $this->assertStringContainsString("{$method} / HTTP/1.1", $target->receivedHead());

        if ($keepsBody) {
            $this->assertStringContainsString('{"a":1}', $target->received());
            $this->assertStringContainsString('content-length: 7', $target->receivedHead());
        } else {
            $this->assertStringNotContainsString('{"a":1}', $target->received());
            $this->assertStringNotContainsString('content-length:', $target->receivedHead());
        }
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function redirectsAndWhatTheyDoToTheMethod(): iterable
    {
        // What every browser does and every server expects: the first three turn a POST
        // into a GET, and the two that exist to avoid that keep both method and body.
        yield '301 moved permanently' => [301, 'GET', false];
        yield '302 found' => [302, 'GET', false];
        yield '303 see other' => [303, 'GET', false];
        yield '307 temporary redirect' => [307, 'POST', true];
        yield '308 permanent redirect' => [308, 'POST', true];
    }

    public function testALocationWithNoSlashOnItIsResolvedAgainstTheDirectory(): void
    {
        // `Location: real.tar.gz` against `/releases/download/v1/tool.tar.gz` is the
        // sibling file, not the root — so the server answers every hop with the same
        // redirect and what is being asserted is where the second request went.
        $url = $this->server->start(
            ["HTTP/1.1 302 Found\r\nLocation: real.tar.gz\r\nContent-Length: 0\r\n\r\n"],
        );

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0))
                ->follow(new Request('GET', $url . 'releases/download/v1/tool.tar.gz'))),
            'Too many redirects',
        );

        $this->assertStringContainsString(
            'GET /releases/download/v1/real.tar.gz HTTP/1.1',
            $this->server->received(),
        );
    }

    public function testFiveIsHowManyRedirectsAreFollowedAndNotHowManyRequestsGoOut(): void
    {
        $url = $this->server->start(["HTTP/1.1 302 Found\r\nLocation: /again\r\nContent-Length: 0\r\n\r\n"]);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0))->follow(new Request('GET', $url))),
            'Too many redirects',
        );

        // MAX_REDIRECTS is five, so six requests leave: the original and five more.
        $this->assertSame(6, substr_count($this->server->received(), ' HTTP/1.1'));
    }

    // ---- the failures that are the caller's fault, and the ones that are the wire's ----

    public function testAUrlWithNoHostIsRefusedByName(): void
    {
        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient())->send(new Request('GET', '/just/a/path'))),
            'Cannot parse URL: "/just/a/path"',
        );
    }

    public function testASchemeThisCannotSpeakIsRefusedByName(): void
    {
        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(
                static fn () => (new HttpClient())->send(new Request('GET', 'ftp://example.invalid/x')),
            ),
            'Unsupported scheme "ftp"',
        );
    }

    public function testTheHostHeaderNamesThePortWhenItIsNotTheDefaultOne(): void
    {
        $url = $this->server->start(["HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n"]);
        $port = (int) substr($url, (int) strrpos(rtrim($url, '/'), ':') + 1);

        Async::run(static fn () => (new HttpClient())->send(new Request('GET', $url))->body->all());

        // The default-port half of the rule — a bare host for :80 and :443 — needs a
        // server on one of those ports, which a test cannot bind.
        $this->assertStringContainsString("host: 127.0.0.1:{$port}", $this->server->receivedHead());
    }

    public function testAHeaderLineWithNoColonInItIsRefused(): void
    {
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nthis line has no colon\r\n\r\n"]);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient())->send(new Request('GET', $url))),
            'Malformed header line: "this line has no colon"',
        );
    }

    public function testAResponseHeadBiggerThanTheLimitIsRefused(): void
    {
        // 80KB of header bytes and no end to them. In 4KB pieces because a single
        // fwrite that size comes back short — the trap this file's own renderer has.
        $url = $this->server->start(array_fill(0, 20, str_repeat('x', 4096)));

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0))->send(new Request('GET', $url))),
            'Response head exceeds 65536 bytes',
        );
    }

    public function testAHeadThatOnlyReachesTheLimitIsTruncatedRatherThanTooBig(): void
    {
        // Exactly MAX_HEAD_BYTES and then the hang-up. The size guard must not fire on
        // the boundary, so what comes back names the truncation — which is also the only
        // test that reaches the truncation message at all.
        $url = $this->server->start(array_fill(0, 16, str_repeat('x', 4096)));

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0))->send(new Request('GET', $url))),
            'Connection closed before the response head was complete',
        );
    }
}
