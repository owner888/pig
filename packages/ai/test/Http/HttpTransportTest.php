<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Providers\Google;
use Pig\Async\AbortSignal;

/**
 * Whatever only sends takes an `HttpTransport`, so a decorator can stand in for `HttpClient`
 * anywhere — the seam extensions wrap to count or limit their requests in one place.
 */
final class HttpTransportTest extends TestCase
{
    /** `follow()` is `HttpClient`'s own, so a class that follows redirects keeps the client. */
    private const array FOLLOWS_REDIRECTS = ['packages/coding-agent/src/Cli/UpdateCheck.php'];

    public function testHttpClientIsATransport(): void
    {
        $this->assertInstanceOf(HttpTransport::class, new HttpClient());
    }

    public function testNoParameterOrPropertyInTheSourceAsksForTheConcreteClient(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];

        foreach (glob($root . '/packages/*/src', GLOB_ONLYDIR) ?: [] as $source) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $relative = substr((string) $file, strlen($root) + 1);

                if (!str_ends_with($relative, '.php') || in_array($relative, self::FOLLOWS_REDIRECTS, true)) {
                    continue;
                }

                $code = (string) file_get_contents((string) $file);

                if (preg_match('/(?<![\w\\\\])\??HttpClient \$|\): \??HttpClient\b/', $code) === 1) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'Type these with HttpTransport: they only send.');
    }

    public function testADecoratorStandsInForTheClient(): void
    {
        $transport = new class () implements HttpTransport {
            #[\Override]
            public function send(Request $request, ?AbortSignal $signal = null): Response
            {
                throw new \RuntimeException('not sent');
            }
        };

        // A provider takes it where it took the client.
        $this->assertInstanceOf(Google::class, new Google($transport));
    }
}
