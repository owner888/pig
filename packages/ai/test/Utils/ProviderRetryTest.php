<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use RuntimeException;

/**
 * Upstream's `utils/provider-retry.ts`: which provider errors are retried, how long the wait is,
 * the cap on a server-requested wait, and an abort during the wait.
 */
final class ProviderRetryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testTheSdkPolicyDecidesWhatIsRetried(): void
    {
        // `x-should-retry` first, then: no status (a connection error) or 408/409/429/5xx.
        $this->assertTrue(ProviderRetry::isRetryableProviderError(new ProviderHttpError('Connection error.')));

        foreach ([408, 409, 429, 500, 503, 529] as $status) {
            $this->assertTrue(ProviderRetry::isRetryableProviderError(new ProviderHttpError('x', $status, [])), (string) $status);
        }

        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertFalse(ProviderRetry::isRetryableProviderError(new ProviderHttpError('x', $status, [])), (string) $status);
        }

        $this->assertTrue(ProviderRetry::isRetryableProviderError(new ProviderHttpError('x', 400, ['x-should-retry' => 'true'])));
        $this->assertFalse(ProviderRetry::isRetryableProviderError(new ProviderHttpError('x', 503, ['X-Should-Retry' => 'false'])));
    }

    public function testTheWaitIsWhatTheServerAskedForElseTheSdksBackoff(): void
    {
        $this->assertSame(250.0, ProviderRetry::getRetryDelayMs(new ProviderHttpError('x', 429, ['retry-after-ms' => '250']), 0, null));
        $this->assertSame(2000.0, ProviderRetry::getRetryDelayMs(new ProviderHttpError('x', 429, ['retry-after' => '2']), 0, null));
        // `parseFloat`: a leading number is a number.
        $this->assertSame(1500.0, ProviderRetry::getRetryDelayMs(new ProviderHttpError('x', 429, ['retry-after' => '1.5 seconds']), 0, null));
        // An HTTP date is the time until it.
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 30);
        $delay = ProviderRetry::getRetryDelayMs(new ProviderHttpError('x', 429, ['retry-after' => $date]), 0, null);
        $this->assertGreaterThan(28_000, $delay);
        $this->assertLessThanOrEqual(30_000, $delay);

        // `Math.min(0.5 * 2 ** retryIndex, 8) * 1000 * (1 - Math.random() * 0.25)`.
        foreach ([[0, 500.0], [1, 1000.0], [3, 4000.0], [6, 8000.0]] as [$index, $ceiling]) {
            $delay = ProviderRetry::getRetryDelayMs(new ProviderHttpError('x', 503, []), $index, null);
            $this->assertLessThanOrEqual($ceiling, $delay);
            $this->assertGreaterThanOrEqual($ceiling * 0.75, $delay);
        }
    }

    public function testAServerRequestedWaitPastTheCapFailsAtOnce(): void
    {
        $error = new ProviderHttpError('429 {"error":"slow"}', 429, ['retry-after' => '61']);

        try {
            ProviderRetry::getRetryDelayMs($error, 0, null);
            $this->fail('a 61 s wait was accepted under the 60 s default');
        } catch (RuntimeException $failure) {
            $this->assertSame('Server requested 61s retry delay (max: 60s). 429 {"error":"slow"}', $failure->getMessage());
        }

        $this->assertSame(61_000.0, ProviderRetry::getRetryDelayMs($error, 0, 0), 'zero disables the cap');
        $this->assertSame(61_000.0, ProviderRetry::getRetryDelayMs($error, 0, 61_000));
    }

    public function testRetriesUpToTheBudgetAndThenThrowsTheLastError(): void
    {
        $attempts = 0;
        $request = static function () use (&$attempts): never {
            $attempts++;

            throw new ProviderHttpError("503 #{$attempts}", 503, ['retry-after-ms' => '0']);
        };

        try {
            Async::run(static fn () => ProviderRetry::retryProviderRequest($request, maxRetries: 2));
            $this->fail('no error');
        } catch (ProviderHttpError $error) {
            $this->assertSame('503 #3', $error->getMessage());
        }

        $this->assertSame(3, $attempts);
    }

    public function testNothingButAProviderErrorIsRetriedAndNorIsANoRetryStatus(): void
    {
        $attempts = 0;
        $request = static function () use (&$attempts): never {
            $attempts++;

            throw new RuntimeException('fetch failed');
        };

        try {
            Async::run(static fn () => ProviderRetry::retryProviderRequest($request, maxRetries: 3));
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $attempts);

        $attempts = 0;
        $request = static function () use (&$attempts): never {
            $attempts++;

            throw new ProviderHttpError('409', 409, []);
        };

        try {
            Async::run(static fn () => ProviderRetry::retryProviderRequest($request, maxRetries: 3, noRetryStatuses: [409]));
        } catch (ProviderHttpError) {
        }

        $this->assertSame(1, $attempts);
    }

    public function testAnAbortDuringTheWaitIsRequestAborted(): void
    {
        $controller = new AbortController();
        Loop::get()->delay(0.05, static fn () => $controller->abort('esc'));

        try {
            Async::run(static fn () => ProviderRetry::retryProviderRequest(
                static fn (): never => throw new ProviderHttpError('503', 503, ['retry-after-ms' => '5000']),
                maxRetries: 1,
                signal: $controller->signal,
            ));
            $this->fail('no error');
        } catch (RuntimeException $error) {
            $this->assertSame('Request aborted', $error->getMessage());
        }
    }

    public function testParseFloatReadsTheLeadingNumberAsJavaScriptDoes(): void
    {
        $this->assertSame(2.5, JsJson::parseFloat(' 2.5s'));
        $this->assertSame(0.5, JsJson::parseFloat('.5'));
        $this->assertSame(1000.0, JsJson::parseFloat('1e3x'));
        $this->assertSame(INF, JsJson::parseFloat('Infinity'));
        $this->assertNan(JsJson::parseFloat('Wed, 21 Oct 2026 07:28:00 GMT'));
        $this->assertNan(JsJson::parseFloat(''));
    }
}
