<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Changelog;

/** `CHANGELOG.md`, read for `/changelog` and for the note shown once after an upgrade. */
final class ChangelogTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/pig-changelog-' . bin2hex(random_bytes(6)) . '.md';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** @return list<Changelog> */
    private function parse(string $markdown): array
    {
        file_put_contents($this->path, $markdown);

        return Changelog::parse($this->path);
    }

    /** @param list<Changelog> $entries */
    private static function versions(array $entries): array
    {
        return array_map(static fn (Changelog $e): string => "{$e->major}.{$e->minor}.{$e->patch}", $entries);
    }

    public function testEachVersionHeadingStartsAnEntryAndKeepsWhatFollowsIt(): void
    {
        $entries = $this->parse(
            "# Changelog\n\n## [0.2.0] — 2026-03-01\n\n- Added a thing.\n- And another.\n\n## 0.1.0\n\n- The first one.\n",
        );

        $this->assertSame(['0.2.0', '0.1.0'], self::versions($entries), 'in the order the file writes them');
        $this->assertStringContainsString('- Added a thing.', $entries[0]->content);
        $this->assertStringContainsString('- And another.', $entries[0]->content);
        $this->assertStringNotContainsString('The first one', $entries[0]->content, 'the next heading ends it');

        // The heading itself is part of the content, because what is rendered is the section.
        $this->assertStringStartsWith('## [0.2.0]', $entries[0]->content);
    }

    public function testBothSpellingsOfAVersionHeadingWork(): void
    {
        // The brackets are Keep a Changelog's and optional, here as upstream.
        $this->assertSame(['1.0.0', '0.9.0'], self::versions($this->parse("## [1.0.0]\n\nx\n\n## 0.9.0\n\ny\n")));
    }

    public function testAHeadingWithNoVersionEndsTheEntryAndStartsNothing(): void
    {
        $entries = $this->parse("## [0.2.0]\n\n- Released.\n\n## Unreleased\n\n- Not versioned.\n");

        // Upstream's behaviour and the right one for a file people edit by hand: folding an
        // `## Unreleased` section into the release above it would file unreleased notes under a
        // released number.
        $this->assertSame(['0.2.0'], self::versions($entries));
        $this->assertStringNotContainsString('Not versioned', $entries[0]->content);
    }

    public function testTextBeforeTheFirstVersionIsNotPartOfAnything(): void
    {
        $entries = $this->parse("# Changelog\n\nAll notable changes, etc.\n\n## 0.1.0\n\n- First.\n");

        $this->assertCount(1, $entries);
        $this->assertStringNotContainsString('All notable changes', $entries[0]->content);
    }

    public function testAMissingFileIsNotAProblem(): void
    {
        // Most installations have no changelog, and a warning on every start is how people
        // learn to skip warnings.
        $this->assertSame([], Changelog::parse($this->path . '-nope'));
    }

    public function testAFileWithNoVersionsInItYieldsNothing(): void
    {
        $this->assertSame([], $this->parse("# Changelog\n\nNothing here yet.\n"));
    }

    // ---- what is new ----------------------------------------------------------------------

    public function testVersionsAreComparedAsNumbersAndNotAsText(): void
    {
        $entries = $this->parse("## 0.10.0\n\nx\n\n## 0.9.0\n\ny\n");

        // The whole reason the parts are ints: `"0.10.0" > "0.9.0"` is false as strings, so a
        // text comparison would decide the newest release is older than the one before it.
        $this->assertSame(['0.10.0'], self::versions(Changelog::newerThan($entries, '0.9.0')));
        $this->assertSame([], self::versions(Changelog::newerThan($entries, '0.10.0')), 'not newer than itself');
    }

    public function testEveryPartOfTheVersionIsCompared(): void
    {
        $entries = $this->parse("## 1.0.0\n\na\n\n## 0.3.1\n\nb\n\n## 0.3.0\n\nc\n");

        $this->assertSame(['1.0.0', '0.3.1'], self::versions(Changelog::newerThan($entries, '0.3.0')));
    }

    public function testAVersionThatIsNotOneMeansEverythingIsNew(): void
    {
        $entries = $this->parse("## 0.2.0\n\na\n\n## 0.1.0\n\nb\n");

        // Upstream's `Number()` of a missing part, kept: showing the whole file once beats
        // silently showing nothing because a settings file had a typo in it.
        $this->assertCount(2, Changelog::newerThan($entries, 'nonsense'));
        $this->assertCount(2, Changelog::newerThan($entries, ''));
    }

    public function testJoiningPutsABlankLineBetweenEntries(): void
    {
        $entries = $this->parse("## 0.2.0\n\na\n\n## 0.1.0\n\nb\n");

        $this->assertSame("## 0.2.0\n\na\n\n## 0.1.0\n\nb", Changelog::join($entries));
    }

    public function testPigsOwnPathIsBesideTheReadme(): void
    {
        $this->assertSame(dirname(__DIR__, 3) . '/CHANGELOG.md', Changelog::path());

        // pig has no releases and therefore no file, so this answers nothing today — which is
        // the same answer somebody who deleted theirs gets, and is why it is worth having the
        // machinery working before the file exists rather than written the day it appears.
        $this->assertSame([], Changelog::parse());
    }
}
