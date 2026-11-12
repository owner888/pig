<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\BuiltinExtensions;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Tools\ToolSet;

/**
 * The model answers a question about pig by reading `docs/` — the system prompt's `docs` section
 * sends it there — so what pig can do has to be written there, and the pages have to be in the
 * package. AGENTS.md's rules on documentation, as tests.
 */
final class DocsTest extends TestCase
{
    private const string DOCS = __DIR__ . '/../../../docs';

    private string|false $before = false;

    #[\Override]
    protected function setUp(): void
    {
        // The suite builds its prompts without documentation (`test/bootstrap.php`); here it is the subject.
        $this->before = getenv('PIG_DOCS_DIR');
        putenv('PIG_DOCS_DIR=' . realpath(self::DOCS));
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->before === false ? 'PIG_DOCS_DIR' : "PIG_DOCS_DIR={$this->before}");
    }

    public function testEveryPageTheSystemPromptNamesIsInThePackage(): void
    {
        $section = SystemPrompt::docsSection();
        $this->assertNotNull($section, 'the package carries its documentation: run php scripts/sync-docs.php');

        preg_match_all('#\bdocs/([a-z0-9-]+\.md)#', $section, $named);
        $this->assertGreaterThan(10, count($named[1]));

        foreach (array_unique($named[1]) as $page) {
            $this->assertFileExists(self::DOCS . '/' . $page, "the docs section names docs/{$page}");
        }

        // The two pages a question about pig's own behaviour is answered from.
        $this->assertContains('settings.md', $named[1]);
        $this->assertContains('cli.md', $named[1]);
    }

    /**
     * A tool pig itself ships that `cli.md` does not name is one the model cannot be asked about:
     * it would read the source to learn whether the tool exists and how it is turned on.
     *
     * In a process of its own: loading the built-in extensions registers their providers, routes
     * and hooks for good, and the tests after this one would run with them.
     */
    #[RunInSeparateProcess]
    public function testEveryToolPigShipsIsDocumentedInTheCliPage(): void
    {
        $tools = ToolSet::ALL;

        foreach (array_keys(BuiltinExtensions::FOLDERS) as $name) {
            $path = BuiltinExtensions::pathOf("builtin:{$name}");
            $this->assertNotNull($path, $name);
            $api = new ExtensionApi(sys_get_temp_dir(), $path, $name);
            (require $path)($api);

            foreach ($api->tools() as $tool) {
                $tools[] = $tool->name;
            }
        }

        $this->assertContains('codemode', $tools);
        $page = (string) file_get_contents(self::DOCS . '/cli.md');

        foreach ($tools as $tool) {
            $this->assertStringContainsString("`{$tool}`", $page, "docs/cli.md says nothing of the {$tool} tool");
        }

        // `tool_search` registers only while a server needs it, so it is not found above.
        $this->assertStringContainsString('`tool_search`', $page);
        // How a tool that is off by default is turned on, in the words a person would paste.
        $this->assertStringContainsString('"defaultTools": ["+codemode"]', $page);
    }

    /** Where the site's repository is beside this one, the package's copy is the site's pages. */
    public function testThePackagesPagesAreTheSitesEnglishPages(): void
    {
        $site = dirname(__DIR__, 4) . '/smart-book/app/Views/pig/docs/en';

        if (!is_file($site . '/extensions.md')) {
            $this->markTestSkipped("the site's repository is not beside this one");
        }

        $pages = static fn (string $dir): array => array_map(basename(...), glob($dir . '/*.md') ?: []);
        $this->assertSame($pages($site), $pages(self::DOCS), 'run php scripts/sync-docs.php');

        foreach ($pages($site) as $page) {
            $this->assertFileEquals("{$site}/{$page}", self::DOCS . "/{$page}", "docs/{$page} is not the site's: run php scripts/sync-docs.php");
        }
    }
}
