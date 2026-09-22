<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\CodingAgent\Tools\FindTool;
use Pig\CodingAgent\Tools\ToolInstaller;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;

/**
 * Finding `fd` and `rg`, and fetching them when they are not there.
 *
 * The fetch itself is not exercised: it reaches GitHub, and a test that depends on the
 * network is a test that fails for reasons that have nothing to do with the code. What
 * is covered is everything around it — where it looks, in what order, when it refuses,
 * and the redirect handling the download needs.
 */
final class ToolInstallerTest extends ToolTestCase
{
    use AssertsThrows;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Loop::reset();

        $this->home = $this->cwd . '/pig-home';
        mkdir($this->home . '/tools', 0o755, true);
        putenv('PIG_HOME=' . $this->home);
        ExternalTool::forget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('PIG_OFFLINE');
        ExternalTool::forget();
        parent::tearDown();
    }

    /** A file that behaves enough like the real thing to be found and run. */
    private function fakeTool(string $name, string $prints = ''): string
    {
        $path = $this->home . '/tools/' . $name;
        file_put_contents($path, "#!/bin/sh\nprintf '%s' " . escapeshellarg($prints) . "\n");
        chmod($path, 0o755);

        return $path;
    }

    // ---- where it looks ------------------------------------------------------------

    public function testPigsOwnCopyIsPreferredOverThePath(): void
    {
        $mine = $this->fakeTool('fd');

        // Downloaded once, used from then on — even if something later turns up on the
        // PATH that would shadow it.
        $this->assertSame($mine, ExternalTool::fd());
    }

    public function testThePathIsUsedWhenThereIsNoOwnCopy(): void
    {
        if (!ExternalTool::has('rg')) {
            $this->markTestSkipped('no ripgrep on this machine to find');
        }

        $this->assertStringNotContainsString($this->home, ExternalTool::ripgrep());
    }

    public function testTheDirectoryIsUnderTheConfigHome(): void
    {
        $this->assertSame($this->home . '/tools', ToolInstaller::directory());
        $this->assertSame($this->home . '/tools/fd', ToolInstaller::path('fd'));
        $this->assertSame($this->home . '/tools/rg', ToolInstaller::path('rg'));
    }

    public function testHasDoesNotDownloadToAnswer(): void
    {
        putenv('PIG_OFFLINE');
        ExternalTool::forget();

        // If this reached for the network the suite would hang here, not fail.
        $this->assertFalse(ExternalTool::has('definitely-not-a-tool'));
    }

    // ---- refusing to download ------------------------------------------------------

    public function testOfflineModeTurnsFetchingOff(): void
    {
        foreach (['1', 'true', 'YES'] as $value) {
            putenv('PIG_OFFLINE=' . $value);
            $this->assertFalse(ToolInstaller::enabled(), "for PIG_OFFLINE={$value}");
        }

        putenv('PIG_OFFLINE=0');
        $this->assertTrue(ToolInstaller::enabled());
    }

    public function testOfflineWithNoToolSaysBothWaysOut(): void
    {
        putenv('PIG_OFFLINE=1');
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        ExternalTool::forget();

        try {
            $error = $this->assertThrows(
                AgentError::class,
                fn () => $this->run(new FindTool($this->cwd), ['pattern' => '*']),
                'not installed',
            );

            // The command first, on the first line, so a one-line display still shows it.
            $this->assertStringContainsString('Install it with:', strtok($error->getMessage(), "\n"));
            $this->assertStringContainsString('unset PIG_OFFLINE', $error->getMessage());
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testAnUnknownToolIsNotDownloaded(): void
    {
        $this->assertThrows(
            AgentError::class,
            static fn () => ToolInstaller::install('emacs'),
            "Nothing known about 'emacs'",
        );
    }

    // ---- the redirect the download needs -------------------------------------------

    public function testFollowGoesWhereTheLocationHeaderPoints(): void
    {
        $target = new CannedServer();
        $targetUrl = $target->start(["HTTP/1.1 200 OK\r\nContent-Length: 7\r\n\r\nPAYLOAD"]);

        $redirect = new CannedServer();
        $redirectUrl = $redirect->start(["HTTP/1.1 302 Found\r\nLocation: {$targetUrl}\r\nContent-Length: 0\r\n\r\n"]);

        // GitHub answers a release URL with a 302 to its object store, so a download
        // that does not follow one gets an empty body and no error.
        $body = Async::run(static function () use ($redirectUrl): string {
            $response = (new HttpClient(5.0))->follow(new Request('GET', $redirectUrl));

            return $response->body->all();
        });

        $this->assertSame('PAYLOAD', $body);
    }

    public function testFollowGivesUpOnALoop(): void
    {
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 302 Found\r\nLocation: /again\r\nContent-Length: 0\r\n\r\n"]);

        $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0))->follow(new Request('GET', $url))),
            'Too many redirects',
        );
    }

    public function testARelativeLocationIsResolvedAgainstWhereItCameFrom(): void
    {
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"]);

        $body = Async::run(static function () use ($url): string {
            return (new HttpClient(5.0))->follow(new Request('GET', $url . 'anything'))->body->all();
        });

        $this->assertSame('ok', $body);
    }

    public function testANonRedirectIsReturnedUntouched(): void
    {
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 404 Not Found\r\nContent-Length: 4\r\n\r\nnope"]);

        $response = Async::run(static fn () => (new HttpClient(5.0))->follow(new Request('GET', $url)));

        $this->assertSame(404, $response->status);
        $this->assertSame('nope', $response->body->all());
    }
}
