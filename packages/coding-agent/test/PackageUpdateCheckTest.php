<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\PackageUpdateCheck;
use Pig\Test\CannedServer;

final class PackageUpdateCheckTest extends TestCase
{
    private string $cwd;
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
        $this->cwd = sys_get_temp_dir() . '/pig-pkg-update-' . bin2hex(random_bytes(6));
        mkdir($this->cwd, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_OFFLINE');
        putenv('PI_OFFLINE');

        if (is_dir($this->cwd)) {
            $this->rmrf($this->cwd);
        }
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function testOfflineModeReturnsEmptyArray(): void
    {
        putenv('PIG_OFFLINE=1');
        $checker = new PackageUpdateCheck();
        $this->assertSame([], $checker->checkForUpdates($this->cwd));
    }

    public function testNoConfiguredPackagesReturnsEmptyArray(): void
    {
        $checker = new PackageUpdateCheck();
        $this->assertSame([], $checker->checkForUpdates($this->cwd));
    }

    public function testOutdatedPackageIsReported(): void
    {
        // 1. Configure a package in project settings
        mkdir($this->cwd . '/.pig', 0o755, true);
        file_put_contents($this->cwd . '/.pig/settings.json', json_encode([
            'packages' => ['npm:my-extension'],
        ]));

        // 2. Install local version 0.1.0
        $installedDir = $this->cwd . '/.pig/npm/node_modules/my-extension';
        mkdir($installedDir, 0o755, true);
        file_put_contents($installedDir . '/package.json', json_encode([
            'name' => 'my-extension',
            'version' => '0.1.0',
        ]));

        // 3. Start loopback server returning latest version 0.2.0
        $payload = json_encode(['version' => '0.2.0']);
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($payload) . "\r\n"
            . "connection: close\r\n\r\n"
            . $payload,
        ]);

        $checker = new PackageUpdateCheck(new HttpClient(5.0), $url . '/');
        $updates = Async::run(fn () => $checker->checkForUpdates($this->cwd));

        $this->assertSame(['my-extension'], $updates);
    }

    public function testUpToDatePackageIsNotReported(): void
    {
        mkdir($this->cwd . '/.pig', 0o755, true);
        file_put_contents($this->cwd . '/.pig/settings.json', json_encode([
            'packages' => ['npm:my-extension'],
        ]));

        $installedDir = $this->cwd . '/.pig/npm/node_modules/my-extension';
        mkdir($installedDir, 0o755, true);
        file_put_contents($installedDir . '/package.json', json_encode([
            'name' => 'my-extension',
            'version' => '0.2.0',
        ]));

        $payload = json_encode(['version' => '0.2.0']);
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($payload) . "\r\n"
            . "connection: close\r\n\r\n"
            . $payload,
        ]);

        $checker = new PackageUpdateCheck(new HttpClient(5.0), $url . '/');
        $updates = Async::run(fn () => $checker->checkForUpdates($this->cwd));

        $this->assertSame([], $updates);
    }
}
