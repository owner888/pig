<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\Skills;

/** Finding the skills on a machine, and what to say about the ones that are wrong. */
final class SkillsTest extends TestCase
{
    private string $root;

    private string $home;

    private string $cwd;

    private string|false $realHome = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-skills-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home, 0o755, true);
        mkdir($this->cwd, 0o755, true);

        // The roots are under the person's home, so the real one has to be out of the
        // way — otherwise this reads whatever skills the machine running it happens to
        // have, and passes or fails accordingly.
        $this->realHome = getenv('HOME');
        putenv('HOME=' . $this->home);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->realHome === false ? 'HOME' : 'HOME=' . $this->realHome);
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** Write a SKILL.md at $relative, under the temp root. */
    private function skill(string $relative, string $frontmatter, string $body = 'Do the thing.'): string
    {
        $path = $this->root . '/' . $relative . '/SKILL.md';
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, "---\n{$frontmatter}\n---\n\n{$body}\n");

        return $path;
    }

    /** @return array{0: list<Skill>, 1: list<\Pig\CodingAgent\Prompt\SkillWarning>} */
    private function load(array $extraDirs = [], array $ignored = [], array $only = []): array
    {
        return Skills::load($this->cwd, $this->home . '/.pig', $extraDirs, $ignored, $only);
    }

    // ---- where they are found ----------------------------------------------------------

    public function testAllFiveStandardRootsAreRead(): void
    {
        $this->skill('home/.codex/skills/from-codex', "name: from-codex\ndescription: one");
        $this->skill('home/.claude/skills/from-claude', "name: from-claude\ndescription: two");
        $this->skill('project/.claude/skills/claude-project', "name: claude-project\ndescription: three");
        $this->skill('home/.pig/skills/from-pig', "name: from-pig\ndescription: four");
        $this->skill('project/.pig/skills/pig-project', "name: pig-project\ndescription: five");

        [$skills] = $this->load();
        $names = array_map(static fn (Skill $s): string => $s->name, $skills);

        // Another agent's skills are read as pig's own: someone who wrote a skill once
        // should not have to write it again per agent.
        sort($names);
        $this->assertSame(['claude-project', 'from-claude', 'from-codex', 'from-pig', 'pig-project'], $names);
    }

    public function testEachSkillKnowsWhichRootItCameFrom(): void
    {
        $this->skill('home/.pig/skills/mine', "name: mine\ndescription: one");

        [$skills] = $this->load();

        $this->assertSame('user', $skills[0]->source);
        $this->assertSame($this->root . '/home/.pig/skills/mine', $skills[0]->baseDir);
    }

    public function testPigRootsAreSearchedToAnyDepth(): void
    {
        $this->skill('home/.pig/skills/group/nested', "name: nested\ndescription: deep");

        [$skills] = $this->load();

        $this->assertSame(['nested'], array_map(static fn (Skill $s): string => $s->name, $skills));
    }

    public function testTheClaudeRootIsSearchedOneLevelOnly(): void
    {
        $this->skill('home/.claude/skills/top', "name: top\ndescription: shallow");
        $this->skill('home/.claude/skills/top/examples/deeper', "name: deeper\ndescription: too deep");

        [$skills] = $this->load();

        // One folder per skill there, so descending further finds a skill's own examples
        // rather than more skills.
        $this->assertSame(['top'], array_map(static fn (Skill $s): string => $s->name, $skills));
    }

    public function testAnExtraDirectoryIsSearchedToo(): void
    {
        $this->skill('elsewhere/extra', "name: extra\ndescription: from --skills-dir");

        [$skills] = $this->load([$this->root . '/elsewhere']);

        $this->assertSame(['extra'], array_map(static fn (Skill $s): string => $s->name, $skills));
        $this->assertSame('custom', $skills[0]->source);
    }

    public function testNoSkillsAnywhereIsAnEmptyListAndNotAnError(): void
    {
        [$skills, $warnings] = $this->load();

        $this->assertSame([], $skills);
        $this->assertSame([], $warnings);
    }

    public function testDotDirectoriesAndDependencyFoldersAreNotSearched(): void
    {
        $this->skill('home/.pig/skills/.git/hidden', "name: hidden\ndescription: no");
        $this->skill('home/.pig/skills/node_modules/dep', "name: dep\ndescription: no");
        $this->skill('home/.pig/skills/real', "name: real\ndescription: yes");

        [$skills] = $this->load();

        $this->assertSame(['real'], array_map(static fn (Skill $s): string => $s->name, $skills));
    }

    // ---- collisions ---------------------------------------------------------------------

    public function testTheFirstRootWinsAndTheSecondIsSaidOutLoud(): void
    {
        $this->skill('home/.codex/skills/both', "name: both\ndescription: from codex");
        $this->skill('home/.pig/skills/both', "name: both\ndescription: from pig");

        [$skills, $warnings] = $this->load();

        $this->assertCount(1, $skills);
        $this->assertSame('from codex', $skills[0]->description);

        // Shadowed silently, the pig one looks like a skill that simply does not work.
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('name taken', $warnings[0]->message);
    }

    public function testOneFileReachedThroughTwoRootsIsOneSkill(): void
    {
        $this->skill('shared/linked', "name: linked\ndescription: one copy");
        mkdir($this->home . '/.pig/skills', 0o755, true);
        symlink($this->root . '/shared/linked', $this->home . '/.pig/skills/linked');

        [$skills, $warnings] = $this->load([$this->root . '/shared']);

        // Symlinking one folder of skills into another root is a normal way to keep a
        // single copy, and it is not a collision to complain about.
        $this->assertCount(1, $skills);
        $this->assertSame([], $warnings);
    }

    // ---- filtering ------------------------------------------------------------------------

    public function testAnIgnoredNameIsLeftOut(): void
    {
        $this->skill('home/.pig/skills/keep-me', "name: keep-me\ndescription: one");
        $this->skill('home/.pig/skills/drop-me', "name: drop-me\ndescription: two");

        [$skills] = $this->load([], ['drop-*']);

        $this->assertSame(['keep-me'], array_map(static fn (Skill $s): string => $s->name, $skills));
    }

    public function testWithAnAllowListNothingElseIsLoaded(): void
    {
        $this->skill('home/.pig/skills/wanted', "name: wanted\ndescription: one");
        $this->skill('home/.pig/skills/other', "name: other\ndescription: two");

        [$skills] = $this->load([], [], ['want*']);

        $this->assertSame(['wanted'], array_map(static fn (Skill $s): string => $s->name, $skills));
    }

    public function testIgnoringBeatsAllowing(): void
    {
        $this->skill('home/.pig/skills/thing', "name: thing\ndescription: one");

        [$skills] = $this->load([], ['thing'], ['thing']);

        $this->assertSame([], $skills);
    }

    // ---- what makes a skill valid -----------------------------------------------------------

    public function testASkillWithNoDescriptionIsNotLoadedAtAll(): void
    {
        $this->skill('home/.pig/skills/nameless', 'name: nameless');

        [$skills, $warnings] = $this->load();

        // The description is the only thing the model sees, so one without it could
        // never be chosen — it would sit in the prompt as a name nobody can use.
        $this->assertSame([], $skills);
        $this->assertStringContainsString('description is required', $warnings[0]->message);
    }

    public function testWithNoNameTheFolderIsTheName(): void
    {
        $this->skill('home/.pig/skills/from-the-folder', 'description: named by its folder');

        [$skills, $warnings] = $this->load();

        $this->assertSame('from-the-folder', $skills[0]->name);
        $this->assertSame([], $warnings);
    }

    public function testASkillWithSomethingWrongIsStillLoadedAndStillComplainedAbout(): void
    {
        $this->skill('home/.pig/skills/Mismatched', "name: not_the_folder\ndescription: still useful");

        [$skills, $warnings] = $this->load();

        // A name two characters off is worth saying and not worth refusing over.
        $this->assertCount(1, $skills);
        $this->assertSame('not_the_folder', $skills[0]->name);

        $messages = implode("\n", array_map(static fn ($w): string => $w->message, $warnings));
        $this->assertStringContainsString('does not match the folder', $messages);
        $this->assertStringContainsString('lowercase letters, digits and hyphens', $messages);
    }

    public function testAnUnknownFrontmatterFieldIsSaid(): void
    {
        $this->skill('home/.pig/skills/typo', "name: typo\ndescripton: misspelled\ndescription: fine");

        [$skills, $warnings] = $this->load();

        $this->assertCount(1, $skills);
        $this->assertStringContainsString('unknown frontmatter field "descripton"', $warnings[0]->message);
    }

    public function testTheSpecsFieldsAreAllAccepted(): void
    {
        $this->skill(
            'home/.pig/skills/complete',
            "name: complete\ndescription: everything\nlicense: MIT\ncompatibility: pig\nallowed-tools: read",
        );

        [$skills, $warnings] = $this->load();

        $this->assertCount(1, $skills);
        $this->assertSame([], $warnings);
    }

    public function testQuotedValuesLoseTheirQuotes(): void
    {
        $this->skill('home/.pig/skills/quoted', "name: \"quoted\"\ndescription: 'in single quotes'");

        [$skills] = $this->load();

        $this->assertSame('quoted', $skills[0]->name);
        $this->assertSame('in single quotes', $skills[0]->description);
    }

    public function testAFileWithNoFrontmatterAtAllIsSkipped(): void
    {
        $path = $this->root . '/home/.pig/skills/plain/SKILL.md';
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, "# Just a document\n\nNo frontmatter here.\n");

        [$skills, $warnings] = $this->load();

        $this->assertSame([], $skills);
        $this->assertStringContainsString('description is required', $warnings[0]->message);
    }

    public function testWindowsLineEndingsAreRead(): void
    {
        $path = $this->root . '/home/.pig/skills/crlf/SKILL.md';
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, "---\r\nname: crlf\r\ndescription: written on Windows\r\n---\r\n\r\nBody.\r\n");

        [$skills] = $this->load();

        $this->assertSame('written on Windows', $skills[0]->description);
    }

    // ---- what the model is told ---------------------------------------------------------------

    public function testNoSkillsAddsNothingToThePrompt(): void
    {
        $this->assertSame('', Skills::forPrompt([]));
    }

    public function testThePromptCarriesTheNameDescriptionAndPath(): void
    {
        $this->skill('home/.pig/skills/tidy', "name: tidy\ndescription: tidy up a file");

        [$skills] = $this->load();
        $prompt = Skills::forPrompt($skills);

        $this->assertStringContainsString('<name>tidy</name>', $prompt);
        $this->assertStringContainsString('<description>tidy up a file</description>', $prompt);

        // The path, because a description with nothing to read is an instruction the
        // model cannot obey.
        $this->assertStringContainsString('<location>' . $skills[0]->path . '</location>', $prompt);
        $this->assertStringContainsString('Use the read tool', $prompt);
    }

    public function testAngleBracketsInADescriptionCannotBreakTheBlock(): void
    {
        $this->skill('home/.pig/skills/xml', "name: xml\ndescription: use <read> & <write> carefully");

        [$skills] = $this->load();
        $prompt = Skills::forPrompt($skills);

        $this->assertStringContainsString('&lt;read&gt; &amp; &lt;write&gt;', $prompt);
        $this->assertStringNotContainsString('<read>', $prompt);
    }
}
