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

    // ---- what the archive is called ------------------------------------------------

    public function testTheArchiveIsNamedTheWayEachProjectNamesIts(): void
    {
        // The naming was one shared rule for both tools for a while, which named a
        // ripgrep archive that does not exist. Pinned here because the only other place
        // it shows up is a 404 on someone else's machine.
        $fd = ToolInstaller::archive('fd', '10.2.0');
        $rg = ToolInstaller::archive('rg', '14.1.1');

        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'aarch64' : 'x86_64';

        [$fdPlatform, $rgPlatform, $extension] = match (PHP_OS_FAMILY) {
            'Darwin' => ['apple-darwin', 'apple-darwin', '.tar.gz'],
            'Linux' => [
                'unknown-linux-gnu',
                $arch === 'aarch64' ? 'unknown-linux-gnu' : 'unknown-linux-musl',
                '.tar.gz',
            ],
            'Windows' => ['pc-windows-msvc', 'pc-windows-msvc', '.zip'],
            default => $this->markTestSkipped('no published build for ' . PHP_OS_FAMILY),
        };

        $this->assertSame("fd-v10.2.0-{$arch}-{$fdPlatform}{$extension}", $fd);
        $this->assertSame("ripgrep-14.1.1-{$arch}-{$rgPlatform}{$extension}", $rg);
    }

    public function testTheUrlCarriesEachProjectsTagStyle(): void
    {
        // fd tags releases v10.2.0, ripgrep tags them 14.1.1 — getting this backwards is
        // another 404.
        $this->assertStringContainsString(
            'https://github.com/sharkdp/fd/releases/download/v10.2.0/',
            ToolInstaller::url('fd', '10.2.0'),
        );

        $this->assertStringContainsString(
            'https://github.com/BurntSushi/ripgrep/releases/download/14.1.1/',
            ToolInstaller::url('rg', '14.1.1'),
        );
    }

    public function testAnUnknownToolHasNoArchiveName(): void
    {
        $this->assertThrows(
            AgentError::class,
            static fn () => ToolInstaller::archive('emacs', '1.0'),
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
