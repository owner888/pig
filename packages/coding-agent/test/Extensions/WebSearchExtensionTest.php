<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use PigWebSearch\HeadlessBrowser;

final class WebSearchExtensionTest extends TestCase
{
    public function testExtensionLoadsAndRegistersToolsAndCommands(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-web-search/index.php';
        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath]);
        $this->assertSame([], $errors);

        $searchExt = null;
        foreach ($loaded as $ext) {
            if ($ext->name === 'pig-web-search') {
                $searchExt = $ext;
                break;
            }
        }

        $this->assertNotNull($searchExt);
        $this->assertSame('pig-web-search', $searchExt->name);

        // Check registered custom tools
        $toolNames = array_map(static fn ($t) => $t->name, $searchExt->api->tools());
        $this->assertContains('web_search', $toolNames);
        $this->assertContains('fetch_web_page', $toolNames);
        $this->assertContains('browse_web_page', $toolNames);

        // Check registered command
        $this->assertArrayHasKey('search', $searchExt->api->commands());
    }

    public function testAClientFrameIsMaskedAndTheSameBytesComeBackOut(): void
    {
        if (!class_exists(HeadlessBrowser::class, false)) {
            require dirname(__DIR__, 4) . "/extensions/pig-web-search/HeadlessBrowser.php";
        }

        foreach (['hi', str_repeat('x', 200), str_repeat('y', 70_000)] as $payload) {
            $frame = HeadlessBrowser::clientFrame($payload);

            $this->assertSame(0x81, ord($frame[0]), 'FIN + text');
            $this->assertSame(0x80, ord($frame[1]) & 0x80, 'a client frame is masked, which RFC 6455 requires');

            // Unmask it the way a server would, and the payload is back.
            $size = ord($frame[1]) & 0x7F;
            $offset = $size === 126 ? 4 : ($size === 127 ? 10 : 2);
            $mask = substr($frame, $offset, 4);
            $masked = substr($frame, $offset + 4);
            $this->assertSame(strlen($payload), strlen($masked));
            $out = '';
            for ($i = 0, $n = strlen($masked); $i < $n; $i++) {
                $out .= $masked[$i] ^ $mask[$i % 4];
            }
            $this->assertSame($payload, $out);
        }
    }

    /**
     * The whole point of the tool, against the Chrome on this machine: text that only exists after
     * the page's JavaScript has run. Skipped where there is no Chrome — a capability, not a failure.
     */
    public function testAPageBuiltByJavascriptIsReadAfterItHasRun(): void
    {
        if (!class_exists(HeadlessBrowser::class, false)) {
            require dirname(__DIR__, 4) . "/extensions/pig-web-search/HeadlessBrowser.php";
        }

        if (HeadlessBrowser::locate() === null) {
            $this->markTestSkipped('no Chrome on this machine');
        }

        $dir = sys_get_temp_dir() . '/pig-spa-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/index.html', <<<'HTML'
            <!doctype html><html><head><title>shell</title><script>
            addEventListener('DOMContentLoaded', () => setTimeout(() => {
              document.getElementById('app').innerHTML = '<h1>Rendered by JavaScript</h1><p>forty-two</p>';
              document.title = 'loaded';
            }, 150));
            </script></head><body><noscript>enable JavaScript</noscript>
            <nav>menu noise</nav><div id="app">Loading…</div><footer>footer noise</footer></body></html>
            HTML);

        $port = 28100 + random_int(0, 200);
        $server = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($server);
        usleep(400_000);
        Loop::reset();

        try {
            $page = Async::run(static function () use ($port): array {
                $browser = HeadlessBrowser::launch();

                try {
                    return $browser->read("http://127.0.0.1:{$port}/", 20.0);
                } finally {
                    $browser->close();
                }
            });

            $this->assertSame('loaded', $page['title'], 'the title the script set, not the one the server sent');
            $this->assertStringContainsString('Rendered by JavaScript', $page['text']);
            $this->assertStringContainsString('forty-two', $page['text']);
            $this->assertStringNotContainsString('Loading', $page['text']);
            $this->assertStringNotContainsString('enable JavaScript', $page['text'], 'noscript is not what a browser shows');
            $this->assertStringNotContainsString('menu noise', $page['text']);
            $this->assertStringNotContainsString('footer noise', $page['text']);
            $this->assertSame([], glob(sys_get_temp_dir() . '/pig-chrome-*') ?: [], 'the throwaway profile is gone');
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($dir . '/index.html');
            rmdir($dir);
        }
    }
}
