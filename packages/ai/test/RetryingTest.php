<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/**
 * The one retry loop for plain requests: what is tried again, what is not, how long it waits,
 * and that an abort cuts the wait short.
 */
final class RetryingTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->server = new CannedServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private static function ok(string $body): array
    {
        return ["HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"];
    }

    public function testATransientStatusAndAClosedConnectionAreTriedAgain(): void
    {
        $base = $this->server->startSequence([
            ["HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\n\r\n"],
            [], // closed without a byte: a transport failure
            self::ok('fine'),
        ]);
        $retries = [];

        // `function`, not `fn`: an arrow function captures `$retries` by value, and the
        // `use (&$retries)` inside it would then write to that copy.
        [$response, $body] = Async::run(function () use ($base, &$retries): array {
            return Retrying::fetch(
                new HttpClient(5.0),
                new Request('GET', $base),
                attempts: 3,
                baseDelay: 0.0,
                onRetry: static function (int $attempt, string $reason, float $wait) use (&$retries): void {
                    $retries[] = [$attempt, $reason, $wait];
                },
            );
        });

        $this->assertSame(200, $response->status);
        $this->assertSame('fine', $body);
        $this->assertSame(3, $this->server->connections());
        $this->assertCount(2, $retries);
        $this->assertSame([1, 'HTTP 503 Service Unavailable', 0.0], $retries[0]);
        $this->assertSame(2, $retries[1][0]);
        $this->assertNotSame('', $retries[1][1], 'the transport failure is said');
    }

    public function testAClientErrorIsAnsweredNotRetried(): void
    {
        $base = $this->server->startSequence([
            ["HTTP/1.1 404 Not Found\r\nContent-Length: 4\r\n\r\ngone"],
            self::ok('never'),
        ]);

        [$response, $body] = Async::run(fn (): array => Retrying::fetch(new HttpClient(5.0), new Request('GET', $base), baseDelay: 0.0));

        $this->assertSame(404, $response->status);
        $this->assertSame('gone', $body);
        $this->assertSame(1, $this->server->connections());
    }

    public function testTheLastAttemptsAnswerIsReturnedWhateverItsStatus(): void
    {
        $base = $this->server->start(["HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\n\r\n"]);

        [$response] = Async::run(fn (): array => Retrying::fetch(new HttpClient(5.0), new Request('GET', $base), attempts: 2, baseDelay: 0.0));

        $this->assertSame(503, $response->status);
        $this->assertSame(2, $this->server->connections());
    }

    public function testTheLastTransportFailureIsThrown(): void
    {
        $base = $this->server->start([]);

        $this->expectException(\RuntimeException::class);
        Async::run(fn (): array => Retrying::fetch(new HttpClient(5.0), new Request('GET', $base), attempts: 2, baseDelay: 0.0));
    }

    public function testRetryAfterIsHonouredAndBackoffGrows(): void
    {
        $base = $this->server->startSequence([
            ["HTTP/1.1 429 Too Many Requests\r\nRetry-After: 0.2\r\nContent-Length: 0\r\n\r\n"],
            self::ok('ok'),
        ]);
        $waits = [];

        $started = microtime(true);
        Async::run(function () use ($base, &$waits): array {
            return Retrying::fetch(new HttpClient(5.0), new Request('GET', $base), baseDelay: 5.0, onRetry: static function (int $attempt, string $reason, float $wait) use (&$waits): void {
                $waits[] = $wait;
            });
        });

        $this->assertSame([0.2], $waits, "the server's wait, not the backoff's 5s");
        $this->assertGreaterThanOrEqual(0.19, microtime(true) - $started);

        $this->assertSame(0.0, Retrying::backoff(0.0, 30.0, 3));
        $this->assertEqualsWithDelta(1.0, Retrying::backoff(1.0, 30.0, 1), 0.25);
        $this->assertEqualsWithDelta(4.0, Retrying::backoff(1.0, 30.0, 3), 1.0);
        $this->assertSame(30.0, Retrying::backoff(1.0, 30.0, 10), 'capped');
    }

    public function testAnAbortCutsTheWaitShort(): void
    {
        $base = $this->server->start(["HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\n\r\n"]);
        $controller = new AbortController();

        $started = microtime(true);
        $this->expectException(AbortError::class);

        try {
            Async::run(function () use ($base, $controller): array {
                \Pig\Async\Loop::get()->delay(0.1, static fn () => $controller->abort('enough'));

                return Retrying::fetch(new HttpClient(5.0), new Request('GET', $base), attempts: 3, baseDelay: 30.0, signal: $controller->signal);
            });
        } finally {
            $this->assertLessThan(5.0, microtime(true) - $started, 'not the 30s backoff');
        }
    }
}
