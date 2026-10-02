<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\TrustChoice;

final class ProjectTrustTest extends TestCase
{
    private string $root;
    private string $home;
    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-trust-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->project = $this->root . '/work/acme';
        mkdir($this->home, 0o700, true);
        mkdir($this->project, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testADirectoryWithNothingUnderPigHasNothingToTrust(): void
    {
        $this->assertFalse(ProjectTrust::hasResources($this->project));

        // The trust file does not exist, no question was asked, and the answer is still yes.
        $this->assertTrue(ProjectTrust::resolve($this->project, null, $this->home));
    }

    /** @return iterable<string, array{string}> */
    public static function resources(): iterable
    {
        foreach (ProjectTrust::RESOURCES as $entry) {
            yield $entry => ['.pig/' . $entry];
        }

        yield 'extensions/foo.php' => ['extensions/foo.php'];
        yield 'extensions/bar/index.php' => ['extensions/bar/index.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function testEachKindOfProjectResourceIsOneToTrust(string $relative): void
    {
        $path = $this->project . '/' . $relative;
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, '');

        $this->assertTrue(ProjectTrust::hasResources($this->project));
    }

    public function testAnExtensionsFolderWithNoPhpInItIsNotAResource(): void
    {
        mkdir($this->project . '/extensions', 0o755, true);
        file_put_contents($this->project . '/extensions/README.md', 'nothing to run');

        $this->assertFalse(ProjectTrust::hasResources($this->project));
    }

    public function testWithResourcesAndNobodyToAskTheAnswerIsNo(): void
    {
        mkdir($this->project . '/.pig/hooks', 0o755, true);

        // `-p` on a stranger's repository: no terminal, no saved decision, and the hooks do not run.
        $this->assertFalse(ProjectTrust::resolve($this->project, null, $this->home));
        $this->assertFileDoesNotExist(ProjectTrust::path($this->home), 'a refusal by default is not written down');
    }

    public function testASavedDecisionIsNotAskedAgainAndTheNearestAncestorWins(): void
    {
        mkdir($this->project . '/.pig/hooks', 0o755, true);
        ProjectTrust::remember([$this->root . '/work' => true], $this->home);

        $asked = false;
        $ask = static function () use (&$asked): ?TrustChoice {
            $asked = true;

            return null;
        };

        $this->assertTrue(ProjectTrust::resolve($this->project, $ask, $this->home), 'the parent folder is trusted');
        $this->assertFalse($asked);

        // A narrower answer underneath beats the wider one above it.
        ProjectTrust::remember([$this->project => false], $this->home);
        $this->assertFalse(ProjectTrust::resolve($this->project, $ask, $this->home));
        $this->assertSame(['path' => realpath($this->project), 'decision' => false], ProjectTrust::entry($this->project, $this->home));
    }

    public function testChoosingWritesWhatTheChoiceSaysAndEscapeWritesNothing(): void
    {
        mkdir($this->project . '/.pig/tools', 0o755, true);

        $trusted = ProjectTrust::resolve(
            $this->project,
            static fn (array $choices): TrustChoice => $choices[0],   // "Trust"
            $this->home,
        );

        $this->assertTrue($trusted);
        $this->assertTrue(ProjectTrust::decision($this->project, $this->home));

        $file = json_decode((string) file_get_contents(ProjectTrust::path($this->home)), true);
        $this->assertSame([realpath($this->project) => true], $file, 'keyed by the canonical absolute path');

        // Forget it and escape: untrusted for this run, and the file stays as it was.
        ProjectTrust::remember([$this->project => null], $this->home);
        $this->assertNull(ProjectTrust::decision($this->project, $this->home));
        $this->assertFalse(ProjectTrust::resolve($this->project, static fn (): ?TrustChoice => null, $this->home));
        $this->assertNull(ProjectTrust::decision($this->project, $this->home));
    }

    public function testTrustingTheParentClearsANarrowerAnswerUnderneath(): void
    {
        mkdir($this->project . '/.pig/tools', 0o755, true);
        ProjectTrust::remember([$this->project => false], $this->home);

        $choices = ProjectTrust::choices($this->project);
        $parent = $choices[1];
        $this->assertStringStartsWith('Trust parent folder (', $parent->label);

        ProjectTrust::remember($parent->updates, $this->home);

        $file = json_decode((string) file_get_contents(ProjectTrust::path($this->home)), true);
        $this->assertSame([realpath($this->root . '/work') => true], $file);
    }

    public function testTheSessionOnlyChoicesWriteNothing(): void
    {
        foreach (ProjectTrust::choices($this->project) as $choice) {
            if (str_contains($choice->label, 'this session only')) {
                $this->assertSame([], $choice->updates, $choice->label);
            }
        }
    }

    public function testAnUnreadableTrustFileIsAComplaintRatherThanATrustedProject(): void
    {
        mkdir($this->project . '/.pig/hooks', 0o755, true);
        file_put_contents(ProjectTrust::path($this->home), '{"/x": "yes"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be true, false or null');

        ProjectTrust::decision($this->project, $this->home);
    }

    public function testANullEntryIsOneThatWasTakenBackAndNotAnInvalidOne(): void
    {
        // Upstream's `readTrustFile()` accepts `null` — its "forget this path" writes one — so a
        // `trust.json` pi wrote can carry it, and refusing it was a pig that could not start on a
        // file pi was happy with. A null is walked past to the nearest real decision above it.
        mkdir($this->project . '/.pig/hooks', 0o755, true);
        $parent = ProjectTrust::canonical(dirname($this->project));
        file_put_contents(ProjectTrust::path($this->home), json_encode([
            ProjectTrust::canonical($this->project) => null,
            $parent => false,
        ]));

        $this->assertFalse(ProjectTrust::decision($this->project, $this->home), 'the parent decides');
        $this->assertSame($parent, ProjectTrust::entry($this->project, $this->home)['path']);

        // And a null with nothing above it is no decision at all.
        file_put_contents(ProjectTrust::path($this->home), json_encode([ProjectTrust::canonical($this->project) => null]));
        $this->assertNull(ProjectTrust::decision($this->project, $this->home));

        // Saving over it keeps the file pi-readable: the null is not what pig writes, but it is
        // not refused on the way through either.
        ProjectTrust::remember([$this->project => true], $this->home);
        $this->assertTrue(ProjectTrust::decision($this->project, $this->home));
    }
}
