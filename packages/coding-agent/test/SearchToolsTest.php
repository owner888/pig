<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\CodingAgent\Tools\FindTool;
use Pig\CodingAgent\Tools\GrepTool;
use Pig\CodingAgent\Tools\Truncate;
use Pig\Test\AssertsThrows;

/**
 * `find` and `grep`, which are `fd` and `rg` with the output shaped for a model.
 *
 * Both are skipped where the tool is not installed, since there is nothing of ours left
 * to test without it — that is the point of requiring them rather than reimplementing.
 */
final class SearchToolsTest extends ToolTestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Nothing here may reach GitHub: a test that downloads is a test that fails for
        // reasons unrelated to the code.
        putenv('PIG_OFFLINE=1');
        ExternalTool::forget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_OFFLINE');
        ExternalTool::forget();
        parent::tearDown();
    }

    private function needsFd(): void
    {
        if (!ExternalTool::has('fd')) {
            $this->markTestSkipped('fd is not installed');
        }
    }

    private function needsRipgrep(): void
    {
        if (!ExternalTool::has('rg')) {
            $this->markTestSkipped('ripgrep is not installed');
        }
    }

    private function project(): void
    {
        $this->file('src/Main.php', "<?php\nclass Main {}\n");
        $this->file('src/Tools/Walk.php', "<?php\nclass Walk {}\n");
        $this->file('src/Tools/Read.php', "<?php\nclass Read {}\n");
        $this->file('test/MainTest.php', "<?php\nclass MainTest {}\n");
        $this->file('README.md', "# Project\n");
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        return explode("\n", explode("\n\n[", $output)[0]);
    }

    // ---- the tools being missing ---------------------------------------------------

    public function testAMissingToolSaysHowToInstallIt(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        ExternalTool::forget();

        try {
            // With downloading off, what the model gets is one command a person can run.
            $error = $this->assertThrows(
                AgentError::class,
                fn () => $this->run(new FindTool($this->cwd), ['pattern' => '*']),
                'not installed',
            );

            $this->assertStringContainsString('Install it with:', $error->getMessage());
            $this->assertStringContainsString('github.com/sharkdp/fd', $error->getMessage());
        } finally {
            putenv('PATH=' . $path);
            ExternalTool::forget();
        }
    }

    public function testRipgrepIsNamedInItsOwnMessage(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        ExternalTool::forget();

        try {
            $error = $this->assertThrows(
                AgentError::class,
                fn () => $this->run(new GrepTool($this->cwd), ['pattern' => 'x']),
                'ripgrep',
            );

            $this->assertStringContainsString('BurntSushi/ripgrep', $error->getMessage());
        } finally {
            putenv('PATH=' . $path);
            ExternalTool::forget();
        }
    }

    // ---- find ----------------------------------------------------------------------

    public function testFindMatchesANameAtAnyDepth(): void
    {
        $this->needsFd();
        $this->project();

        $found = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php'])));
        sort($found);

        $this->assertSame(['src/Main.php', 'src/Tools/Read.php', 'src/Tools/Walk.php', 'test/MainTest.php'], $found);
    }

    public function testAPatternWithASlashMatchesThePathNotTheName(): void
    {
        $this->needsFd();
        $this->project();

        // fd matches a glob against the file name unless told otherwise, so this shipped
        // once matching nothing at all: every pattern the model wrote with a directory in
        // it came back "No files found", and it fell back to walking the tree with `ls`.
        $found = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => 'src/*.php'])));

        $this->assertSame(['src/Main.php'], $found);
    }

    public function testASingleStarDoesNotCrossADirectoryButTwoDo(): void
    {
        $this->needsFd();
        $this->project();

        $shallow = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => 'src/*.php'])));
        $deep = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => 'src/**/*.php'])));
        sort($deep);

        $this->assertNotContains('src/Tools/Read.php', $shallow);
        $this->assertSame(['src/Main.php', 'src/Tools/Read.php', 'src/Tools/Walk.php'], $deep);
    }

    public function testASlashPatternMatchesAtAnyDepth(): void
    {
        $this->needsFd();
        $this->project();
        $this->file('vendor-ish/src/Deep.php');

        // 'src/*.php' means "in any src directory", not "in the src directory at the
        // root" — the anchoring a --full-path glob would otherwise have.
        $found = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => 'src/*.php'])));
        sort($found);

        $this->assertSame(['src/Main.php', 'vendor-ish/src/Deep.php'], $found);
    }

    public function testAnAlreadyAnchoredPatternIsNotAnchoredTwice(): void
    {
        $this->needsFd();
        $this->project();

        $found = $this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => '**/Tools/*.php'])));
        sort($found);

        $this->assertSame(['src/Tools/Read.php', 'src/Tools/Walk.php'], $found);
    }

    public function testABadPatternIsReportedRatherThanReadAsNoMatches(): void
    {
        $this->needsFd();
        $this->project();

        // fd exits 0 when it found nothing, so a failure that was read as "no matches"
        // hid every real error behind an answer the model had no reason to doubt.
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->run(new FindTool($this->cwd), ['pattern' => '[unclosed']),
            'unclosed character class',
        );

        $this->assertStringNotContainsString('No files found', $error->getMessage());
    }

    public function testPathsComeBackRelativeToTheSearchDirectory(): void
    {
        $this->needsFd();
        $this->project();

        foreach ($this->lines($this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php']))) as $line) {
            // fd prints absolute paths because it was given one; the model wants them
            // the way it would type them.
            $this->assertStringStartsNotWith('/', $line);
        }
    }

    public function testFindSearchesFromAGivenDirectory(): void
    {
        $this->needsFd();
        $this->project();

        $found = $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php', 'path' => 'test']));

        $this->assertSame('MainTest.php', $found);
    }

    public function testNoMatchesSaysSoRatherThanReturningNothing(): void
    {
        $this->needsFd();
        $this->project();

        $this->assertSame(
            'No files found matching pattern',
            $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.rs'])),
        );
    }

    public function testFindSaysWhenItStoppedEarly(): void
    {
        $this->needsFd();

        for ($index = 0; $index < 10; $index++) {
            $this->file("f{$index}.php");
        }

        $output = $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php', 'limit' => 4]));

        $this->assertStringContainsString('[4 result limit reached. Use limit=8 for more', $output);
    }

    public function testFindOnAMissingDirectoryIsAnError(): void
    {
        $this->needsFd();

        $this->assertThrows(
            AgentError::class,
            fn () => $this->run(new FindTool($this->cwd), ['pattern' => '*', 'path' => 'nope']),
            'No such directory',
        );
    }

    public function testGitignoredFilesAreNotFound(): void
    {
        $this->needsFd();
        $this->file('.gitignore', "vendor/\n*.log\n");
        $this->file('src/Main.php');
        $this->file('vendor/huge/thing.php');
        $this->file('debug.log');

        $found = $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*']));

        $this->assertStringNotContainsString('vendor', $found);
        $this->assertStringNotContainsString('debug.log', $found);
        $this->assertStringContainsString('src/Main.php', $found);
    }

    public function testANestedGitignoreAppliesOutsideAGitRepositoryToo(): void
    {
        $this->needsFd();
        $this->file('src/.gitignore', "generated/\n");
        $this->file('src/Main.php');
        $this->file('src/generated/Big.php');
        $this->file('other/generated/Kept.php');

        // fd honours nested .gitignore files on its own only inside a repository;
        // --no-require-git makes it do so anywhere, with the ordinary nesting rules.
        $found = $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php']));

        $this->assertStringNotContainsString('src/generated', $found);
        $this->assertStringContainsString('other/generated/Kept.php', $found);
    }

    public function testHiddenFilesAreSearchedButIgnoredOnesAreNot(): void
    {
        $this->needsFd();
        $this->file('.config.php');
        $this->file('.gitignore', "secret.php\n");
        $this->file('secret.php');

        $found = $this->output($this->run(new FindTool($this->cwd), ['pattern' => '*.php']));

        // A dotfile is often exactly what is being looked for; an ignored file never is.
        $this->assertStringContainsString('.config.php', $found);
        $this->assertStringNotContainsString('secret.php', $found);
    }

    // ---- grep ----------------------------------------------------------------------

    public function testGrepReportsFileLineAndText(): void
    {
        $this->needsRipgrep();
        $this->file('src/Main.php', "<?php\n\nclass Main\n{\n}\n");

        $output = $this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'class Main']));

        $this->assertSame('src/Main.php:3: class Main', $this->lines($output)[0]);
    }

    public function testGrepTakesARegularExpression(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "foo123\nbar\nfoo456\n");

        $found = $this->lines($this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'foo\d+'])));

        $this->assertCount(2, $found);
    }

    public function testALiteralSearchDoesNotTreatThePatternAsARegex(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "price is \$5.00\nprice is 1x00\n");

        $found = $this->lines($this->output(
            $this->run(new GrepTool($this->cwd), ['pattern' => '$5.00', 'literal' => true]),
        ));

        // As a regex, `$5.00` would match neither; as text it matches one.
        $this->assertCount(1, $found);
        $this->assertStringContainsString('$5.00', $found[0]);
    }

    public function testCaseCanBeIgnored(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "Hello\nHELLO\nhello\n");

        $this->assertCount(1, $this->lines($this->output(
            $this->run(new GrepTool($this->cwd), ['pattern' => 'hello']),
        )));

        $this->assertCount(3, $this->lines($this->output(
            $this->run(new GrepTool($this->cwd), ['pattern' => 'hello', 'ignoreCase' => true]),
        )));
    }

    public function testAGlobNarrowsWhichFilesAreSearched(): void
    {
        $this->needsRipgrep();
        $this->file('a.php', "needle\n");
        $this->file('b.txt', "needle\n");

        $found = $this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'needle', 'glob' => '*.php']));

        $this->assertSame('a.php:1: needle', $found);
    }

    public function testContextLinesAreMarkedDifferentlyFromTheMatch(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "one\ntwo\nNEEDLE\nfour\nfive\n");

        $found = $this->lines($this->output(
            $this->run(new GrepTool($this->cwd), ['pattern' => 'NEEDLE', 'context' => 1]),
        ));

        // `:` for the match and `-` for context, the way grep has always done it.
        $this->assertSame(['a.txt-2- two', 'a.txt:3: NEEDLE', 'a.txt-4- four'], $found);
    }

    public function testNoMatchesSaysSo(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "nothing here\n");

        $this->assertSame('No matches found', $this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'zzz'])));
    }

    public function testGrepStopsOnceItHasEnough(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', str_repeat("needle\n", 500));

        $output = $this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'needle', 'limit' => 5]));

        $this->assertCount(5, $this->lines($output));
        $this->assertStringContainsString('[5 match limit reached. Use limit=10 for more', $output);
    }

    public function testALongLineIsShortenedAndTheModelIsTold(): void
    {
        $this->needsRipgrep();
        $this->file('bundle.js', 'x' . str_repeat('y', 2000) . "needle\n");

        $output = $this->output($this->run(new GrepTool($this->cwd), ['pattern' => 'needle']));

        $chars = Truncate::MAX_MATCH_CHARS;
        $this->assertStringContainsString('... [truncated]', $output);
        $this->assertStringContainsString("Some lines shortened to {$chars} characters", $output);
    }

    public function testSearchingOneFileNamesItWithoutAPath(): void
    {
        $this->needsRipgrep();
        $this->file('src/deep/a.txt', "needle\n");

        $output = $this->output($this->run(new GrepTool($this->cwd), [
            'pattern' => 'needle',
            'path' => 'src/deep/a.txt',
        ]));

        $this->assertSame('a.txt:1: needle', $output);
    }

    public function testGitignoredFilesAreNotSearched(): void
    {
        $this->needsRipgrep();
        $this->file('.gitignore', "vendor/\n");
        $this->file('src/Main.php', "needle\n");
        $this->file('vendor/dep.php', "needle\n");

        $this->assertSame('src/Main.php:1: needle', $this->output(
            $this->run(new GrepTool($this->cwd), ['pattern' => 'needle']),
        ));
    }

    public function testGrepOnAMissingPathIsAnError(): void
    {
        $this->needsRipgrep();

        $this->assertThrows(
            AgentError::class,
            fn () => $this->run(new GrepTool($this->cwd), ['pattern' => 'x', 'path' => 'nope']),
            'No such file or directory',
        );
    }

    public function testABadRegexIsReportedRatherThanReadAsNoMatches(): void
    {
        $this->needsRipgrep();
        $this->file('a.txt', "text\n");

        // rg exits 2 for this. Reading that as "no matches" would send the model off
        // looking for why its search found nothing.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->run(new GrepTool($this->cwd), ['pattern' => '[unclosed']),
            'ripgrep exited',
        );
    }
}
