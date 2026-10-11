<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\DirectoryListing;

/**
 * `/api/dirs` for the working-directory picker: a folder's subfolders, from a path typed the ways
 * a person types one — and the page's `cwd.js` completing a path against a real listing.
 */
final class DirectoryListingTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/pig-dirs-' . bin2hex(random_bytes(4));
        foreach (['Projects/pig', 'Projects/pi', 'Projects/smart-book', 'Music', '.config', 'home/me/work'] as $dir) {
            mkdir("{$this->root}/{$dir}", 0o700, true);
        }
        touch("{$this->root}/Projects/notes.txt");
    }

    #[\Override]
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function list(string $path, bool $hidden = false): array
    {
        return DirectoryListing::list($path, "{$this->root}/Projects", "{$this->root}/home/me", $hidden);
    }

    public function testAFolderIsItsSubfoldersInNameOrderWithoutFilesOrHiddenOnes(): void
    {
        $this->assertSame([
            'ok' => true,
            'path' => $this->root,
            'parent' => dirname($this->root),
            'home' => "{$this->root}/home/me",
            'dirs' => ['home', 'Music', 'Projects'],
            'truncated' => false,
        ], $this->list($this->root));

        $this->assertSame(['.config', 'home', 'Music', 'Projects'], $this->list($this->root, hidden: true)['dirs']);
        $this->assertSame(['pi', 'pig', 'smart-book'], $this->list("{$this->root}/Projects")['dirs'], 'no notes.txt');
    }

    public function testAPathIsReadAsAPersonTypesIt(): void
    {
        $this->assertSame("{$this->root}/Projects", $this->list('')['path'], 'nothing typed: where pig web runs');
        $this->assertSame("{$this->root}/Projects/pig", $this->list('pig')['path'], 'relative to it');
        $this->assertSame("{$this->root}/home/me", $this->list('~')['path']);
        $this->assertSame("{$this->root}/home/me/work", $this->list('~/work/')['path']);
        $this->assertSame("{$this->root}/Projects", $this->list("{$this->root}/Projects/pig/..")['path'], 'resolved');
        $this->assertNull(DirectoryListing::list('/', '/', '/')['parent'], 'the root has none');
    }

    public function testWhatIsNotAFolderSaysSo(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'Not a directory: nowhere'], $this->list('nowhere'));
        $this->assertFalse($this->list("{$this->root}/Projects/notes.txt")['ok']);
    }

    public function testThePageCompletesATypedPathAgainstTheListing(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $fixture = ['listing' => $this->list("{$this->root}/Projects")];
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/web-cwd.mjs')
            . ' ' . escapeshellarg((string) realpath(dirname(__DIR__, 2) . '/src/Web/assets/js'))
            . ' ' . escapeshellarg(base64_encode(json_encode($fixture, JSON_THROW_ON_ERROR))) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web cwd: passed', implode("\n", $out));
    }
}
