<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Settings;

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

    /**
     * Every skill the conventional directories and the settings give, through the same two steps a
     * session takes: `PackageManager::resolveLocalResources()`, then `Skills::fromResources()`.
     *
     * @param array<string, mixed> $user    the person's settings
     * @param array<string, mixed> $project the project's settings
     * @return array{0: list<Skill>, 1: list<\Pig\CodingAgent\Prompt\SkillWarning>}
     */
    private function load(array $user = [], array $project = [], bool $trusted = true): array
    {
        $agentDir = $this->home . '/.pig/agent';

        foreach ([$agentDir => $user, $this->cwd . '/.pig' => $project] as $dir => $values) {
            if ($values !== []) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0o755, true);
                }

                file_put_contents($dir . '/settings.json', json_encode($values));
            }
        }

        $settings = Settings::load($this->cwd, $agentDir, $trusted);

        return Skills::fromResources((new PackageManager($this->cwd, $settings, $agentDir))->resolveLocalResources()->skills);
    }

    /** @param list<Skill> $skills @return list<string> */
    private static function names(array $skills): array
    {
        return array_map(static fn (Skill $s): string => $s->name, $skills);
    }

    // ---- where they are found ----------------------------------------------------------

    public function testUpstreamsFourDirectoriesAreReadAndNoOtherToolsAre(): void
    {
        $this->skill('home/.pig/agent/skills/from-pig', "name: from-pig\ndescription: one");
        $this->skill('project/.pig/skills/from-project', "name: from-project\ndescription: two");
        $this->skill('home/.agents/skills/from-agents', "name: from-agents\ndescription: three");
        $this->skill('project/.agents/skills/from-project-agents', "name: from-project-agents\ndescription: four");
        // Not upstream's any more, so not pig's: another tool's folder is that tool's.
        $this->skill('home/.claude/skills/from-claude', "name: from-claude\ndescription: no");
        $this->skill('home/.codex/skills/from-codex', "name: from-codex\ndescription: no");
        $this->skill('home/.pi/agent/skills/from-pi', "name: from-pi\ndescription: no");
        $this->skill('project/.pi/skills/from-pi-project', "name: from-pi-project\ndescription: no");

        [$skills, $warnings] = $this->load();

        $this->assertSame([], $warnings);
        $this->assertEqualsCanonicalizing(['from-pig', 'from-project', 'from-agents', 'from-project-agents'], self::names($skills));
    }

    public function testAnUntrustedProjectGivesNoSkills(): void
    {
        $this->skill('home/.pig/agent/skills/mine', "name: mine\ndescription: one");
        $this->skill('project/.pig/skills/theirs', "name: theirs\ndescription: two");
        $this->skill('project/.agents/skills/theirs-too', "name: theirs-too\ndescription: three");

        [$skills] = $this->load(trusted: false);

        $this->assertSame(['mine'], self::names($skills));
    }

    public function testAncestorAgentsSkillsAreReadUpToTheRepositoryRoot(): void
    {
        mkdir($this->root . '/repo/.git', 0o755, true);
        mkdir($this->root . '/repo/packages/app', 0o755, true);
        $this->cwd = $this->root . '/repo/packages/app';
        $this->skill('repo/.agents/skills/at-root', "name: at-root\ndescription: one");
        $this->skill('repo/packages/.agents/skills/in-between', "name: in-between\ndescription: two");
        // Above the repository: not this project's.
        $this->skill('.agents/skills/above', "name: above\ndescription: no");

        [$skills] = $this->load();

        $this->assertEqualsCanonicalizing(['at-root', 'in-between'], self::names($skills));
    }

    public function testAMarkdownFileDirectlyInThePigSkillsDirectoryIsASkillButNotInAgentsSkills(): void
    {
        mkdir($this->home . '/.pig/agent/skills', 0o755, true);
        file_put_contents($this->home . '/.pig/agent/skills/loose.md', "---\nname: loose\ndescription: a file on its own\n---\n");
        mkdir($this->home . '/.agents/skills/nested', 0o755, true);
        file_put_contents($this->home . '/.agents/skills/root.md', "---\nname: root\ndescription: no\n---\n");
        // Upstream's `agents` mode: below the root, a `.md` in a directory with no `SKILL.md` is one.
        file_put_contents($this->home . '/.agents/skills/nested/below.md', "---\nname: below\ndescription: yes\n---\n");

        [$skills] = $this->load();

        $this->assertEqualsCanonicalizing(['loose', 'below'], self::names($skills));
    }

    public function testAMarkdownFileWithNoDescriptionIsSkippedWithoutAWord(): void
    {
        mkdir($this->home . '/.pig/agent/skills', 0o755, true);
        file_put_contents($this->home . '/.pig/agent/skills/README.md', "# Notes\n");

        $this->assertSame([[], []], $this->load());
    }

    public function testASkillMdClaimsItsDirectoryAndTheWalkStopsThere(): void
    {
        $this->skill('home/.pig/agent/skills/outer', "name: outer\ndescription: one");
        $this->skill('home/.pig/agent/skills/outer/examples/inner', "name: inner\ndescription: an example inside a skill");

        [$skills] = $this->load();

        $this->assertSame(['outer'], self::names($skills));
    }

    public function testTheSkillsSettingAddsPathsResolvedFromEachScopesBase(): void
    {
        $this->skill('home/.pig/agent/elsewhere/by-user', "name: by-user\ndescription: one");
        $this->skill('project/.pig/more/by-project', "name: by-project\ndescription: two");
        $this->skill('absolute/by-path', "name: by-path\ndescription: three");

        [$skills] = $this->load(['skills' => ['elsewhere', $this->root . '/absolute']], ['skills' => ['more']]);

        $this->assertEqualsCanonicalizing(['by-user', 'by-project', 'by-path'], self::names($skills));
        $sources = array_combine(self::names($skills), array_map(static fn (Skill $s): string => $s->source, $skills));
        $this->assertSame('user', $sources['by-user']);
        $this->assertSame('project', $sources['by-project']);
    }

    public function testAnExclamationPatternInTheSettingTurnsAnAutoDiscoveredSkillOff(): void
    {
        $this->skill('home/.pig/agent/skills/keep-me', "name: keep-me\ndescription: one");
        $this->skill('home/.pig/agent/skills/drop-me', "name: drop-me\ndescription: two");

        [$skills] = $this->load(['skills' => ['!drop-*']]);

        $this->assertSame(['keep-me'], self::names($skills));
    }

    public function testAMinusPathTakesOneOutAndAPlusPathPutsOneBack(): void
    {
        $this->skill('home/.pig/agent/skills/a', "name: a\ndescription: one");
        $this->skill('home/.pig/agent/skills/b', "name: b\ndescription: two");

        [$skills] = $this->load(['skills' => ['!*', '+skills/a']]);
        $this->assertSame(['a'], self::names($skills));

        [$skills] = $this->load(['skills' => ['-skills/b']]);
        $this->assertSame(['a'], self::names($skills));
    }

    public function testTheOldObjectFormIsReportedAndNotRead(): void
    {
        $this->skill('typed/mine', "name: mine\ndescription: from a custom directory");
        mkdir($this->home . '/.pig/agent', 0o755, true);
        file_put_contents($this->home . '/.pig/agent/settings.json', json_encode(['skills' => ['enabled' => true, 'customDirectories' => [$this->root . '/typed']]]));

        $settings = Settings::load($this->cwd, $this->home . '/.pig/agent');

        $this->assertSame([], $settings->resourceEntries('skills', 'user'));
        $this->assertStringContainsString('the old object form is not read', implode("\n", $settings->problems()));
    }

    public function testNoSkillsAnywhereIsAnEmptyListAndNotAnError(): void
    {
        $this->assertSame([[], []], $this->load());
    }

    public function testDotDirectoriesAndDependencyFoldersAreNotSearched(): void
    {
        $this->skill('home/.pig/agent/skills/.git/hidden', "name: hidden\ndescription: no");
        $this->skill('home/.pig/agent/skills/node_modules/dep', "name: dep\ndescription: no");
        $this->skill('home/.pig/agent/skills/vendor/dep', "name: dep\ndescription: no");
        $this->skill('home/.pig/agent/skills/real', "name: real\ndescription: yes");

        [$skills] = $this->load();

        $this->assertSame(['real'], self::names($skills));
    }

    // ---- collisions ---------------------------------------------------------------------

    public function testTheFirstDiscoveredWinsAndTheCollisionIsSaid(): void
    {
        $this->skill('home/.pig/agent/skills/both', "name: both\ndescription: from the user");
        $project = $this->skill('project/.pig/skills/both', "name: both\ndescription: from the project");

        [$skills, $warnings] = $this->load();

        // Upstream's precedence: the project's directory ranks before the person's.
        $this->assertCount(1, $skills);
        $this->assertSame('from the project', $skills[0]->description);
        $this->assertSame('project', $skills[0]->source);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('name "both" collision', $warnings[0]->message);
        $this->assertStringContainsString($project, $warnings[0]->message);
        $this->assertStringContainsString('home/.pig/agent/skills/both/SKILL.md', $warnings[0]->path);
    }

    public function testASettingsEntryRanksBeforeTheDirectoryOfItsScope(): void
    {
        $this->skill('home/.pig/agent/skills/mine', "name: mine\ndescription: from the directory");
        $this->skill('home/.pig/agent/listed/mine', "name: mine\ndescription: from the setting");

        [$skills] = $this->load(['skills' => ['listed']]);

        $this->assertSame('from the setting', $skills[0]->description);
    }

    public function testACommandLinePathComesAfterEverythingElse(): void
    {
        $this->skill('home/.pig/agent/skills/mine', "name: mine\ndescription: from home");
        $this->skill('typed/mine', "name: mine\ndescription: from the flag");
        $this->skill('typed/other', "name: other\ndescription: only on the flag");

        [$skills, $warnings] = Skills::fromPaths([$this->root . '/typed'], $this->cwd, $this->home . '/.pig/agent');

        $this->assertSame(['mine', 'other'], self::names($skills));
        $this->assertSame('path', $skills[0]->source);
        $this->assertSame([], $warnings);
        $this->assertSame('skill path does not exist', Skills::fromPaths([$this->root . '/nowhere'], $this->cwd)[1][0]->message);
    }

    public function testOneFileReachedThroughTwoRootsIsOneSkill(): void
    {
        $this->skill('shared/linked', "name: linked\ndescription: one copy");
        mkdir($this->home . '/.pig/agent/skills', 0o755, true);
        symlink($this->root . '/shared/linked', $this->home . '/.pig/agent/skills/linked');

        [$skills, $warnings] = $this->load(['skills' => [$this->root . '/shared']]);

        // Symlinking one folder of skills into another root is a normal way to keep a
        // single copy, and it is not a collision to complain about.
        $this->assertCount(1, $skills);
        $this->assertSame([], $warnings);
    }

    // ---- what makes a skill valid -----------------------------------------------------------

    public function testASkillWithNoDescriptionIsNotLoadedAtAll(): void
    {
        $this->skill('home/.pig/agent/skills/nameless', 'name: nameless');

        [$skills, $warnings] = $this->load();

        // The description is the only thing the model sees, so one without it could
        // never be chosen — it would sit in the prompt as a name nobody can use.
        $this->assertSame([], $skills);
        $this->assertStringContainsString('description is required', $warnings[0]->message);
    }

    public function testAFrontmatterThatIsNotYamlIsRefusedWithWhy(): void
    {
        // The usual one: a second `: ` in a plain value, which YAML reads as a nested mapping.
        $path = $this->skill('home/.pig/agent/skills/colon', "name: colon\ndescription: Use when: the user asks");
        $this->skill('home/.pig/agent/skills/quoted', "name: quoted\ndescription: \"Use when: the user asks\"");

        [$skills, $warnings] = $this->load();

        $this->assertSame(['quoted'], self::names($skills), 'quoted, the same sentence is fine');
        $this->assertCount(1, $warnings);
        $this->assertSame($path, $warnings[0]->path);
        $this->assertSame('Nested mappings are not allowed in compact mappings at line 3', $warnings[0]->message);
    }

    public function testAFolderOfNotesWithBrokenFrontmatterIsPassedOverQuietly(): void
    {
        // Not a `SKILL.md`: a note that was never meant to be a skill is not complained about.
        mkdir($this->home . '/.pig/agent/skills', 0o755, true);
        file_put_contents($this->home . '/.pig/agent/skills/notes.md', "---\ndescription: a: b\n---\nnotes");

        $this->assertSame([[], []], $this->load());
    }

    public function testAFoldedOrLiteralDescriptionIsReadWhole(): void
    {
        $this->skill('home/.pig/agent/skills/folded', "description: >\n  Reads the whole\n  folded paragraph.\nname: folded");
        $this->skill('home/.pig/agent/skills/literal', "description: |\n  First line.\n  Second line.");

        [$skills] = $this->load();
        $descriptions = array_combine(self::names($skills), array_map(static fn (Skill $s): string => $s->description, $skills));

        // It came back as `>` and `|`, the line it was announced on.
        $this->assertSame(['folded' => 'Reads the whole folded paragraph.', 'literal' => "First line.\nSecond line."], $descriptions);
    }

    public function testOnlyABooleanTrueTurnsModelInvocationOff(): void
    {
        $this->skill('home/.pig/agent/skills/off', "description: d\ndisable-model-invocation: true");
        $this->skill('home/.pig/agent/skills/string', "description: d\ndisable-model-invocation: \"true\"");

        [$skills] = $this->load();
        $disabled = array_combine(self::names($skills), array_map(static fn (Skill $s): bool => $s->disableModelInvocation, $skills));

        $this->assertSame(['off' => true, 'string' => false], $disabled);
    }

    public function testWithNoNameTheFolderIsTheName(): void
    {
        $this->skill('home/.pig/agent/skills/from-the-folder', 'description: named by its folder');

        [$skills, $warnings] = $this->load();

        $this->assertSame('from-the-folder', $skills[0]->name);
        $this->assertSame([], $warnings);
    }

    public function testANameThatDiffersFromItsFolderIsNotComplainedAbout(): void
    {
        $this->skill('home/.pig/agent/skills/folder', "name: other-name\ndescription: fine");

        [$skills, $warnings] = $this->load();

        $this->assertSame('other-name', $skills[0]->name);
        $this->assertSame([], $warnings);
    }

    public function testASkillWithSomethingWrongIsStillLoadedAndStillComplainedAbout(): void
    {
        $this->skill('home/.pig/agent/skills/Mismatched', "name: not_the_folder\ndescription: still useful");

        [$skills, $warnings] = $this->load();

        // A name two characters off is worth saying and not worth refusing over.
        $this->assertCount(1, $skills);
        $this->assertSame('not_the_folder', $skills[0]->name);
        $this->assertSame(
            ['name contains invalid characters (must be lowercase a-z, 0-9, hyphens only)'],
            array_map(static fn ($w): string => $w->message, $warnings),
        );
    }

    public function testFieldsOutsideTheSpecAreNotComplainedAbout(): void
    {
        $this->skill(
            'home/.pig/agent/skills/complete',
            "name: complete\ndescription: everything\nlicense: MIT\ncompatibility: pig\nallowed-tools: read\ndescripton: a typo",
        );

        [$skills, $warnings] = $this->load();

        $this->assertCount(1, $skills);
        $this->assertSame([], $warnings);
    }

    public function testDisableModelInvocationKeepsTheSkillButOutOfThePrompt(): void
    {
        $this->skill('home/.pig/agent/skills/manual', "name: manual\ndescription: by hand only\ndisable-model-invocation: true");
        $this->skill('home/.pig/agent/skills/quoted', "name: quoted\ndescription: a string is not true\ndisable-model-invocation: \"true\"");
        $this->skill('home/.pig/agent/skills/auto', "name: auto\ndescription: offered");

        [$skills, $warnings] = $this->load();
        $byName = array_combine(self::names($skills), $skills);

        $this->assertSame([], $warnings);
        $this->assertTrue($byName['manual']->disableModelInvocation);
        $this->assertFalse($byName['quoted']->disableModelInvocation);
        $prompt = Skills::forPrompt($skills);
        $this->assertStringNotContainsString('<name>manual</name>', $prompt);
        $this->assertStringContainsString('<name>auto</name>', $prompt);
        $this->assertSame('', Skills::forPrompt([$byName['manual']]));
    }

    public function testQuotedValuesLoseTheirQuotes(): void
    {
        $this->skill('home/.pig/agent/skills/quoted', "name: \"quoted\"\ndescription: 'in single quotes'");

        [$skills] = $this->load();

        $this->assertSame('quoted', $skills[0]->name);
        $this->assertSame('in single quotes', $skills[0]->description);
    }

    public function testAFileWithNoFrontmatterAtAllIsSkipped(): void
    {
        $path = $this->root . '/home/.pig/agent/skills/plain/SKILL.md';
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, "# Just a document\n\nNo frontmatter here.\n");

        [$skills, $warnings] = $this->load();

        $this->assertSame([], $skills);
        $this->assertStringContainsString('description is required', $warnings[0]->message);
    }

    public function testWindowsLineEndingsAreRead(): void
    {
        $path = $this->root . '/home/.pig/agent/skills/crlf/SKILL.md';
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
        $this->skill('home/.pig/agent/skills/tidy', "name: tidy\ndescription: tidy up a file");

        [$skills] = $this->load();
        $prompt = Skills::forPrompt($skills);

        $this->assertStringContainsString('<name>tidy</name>', $prompt);
        $this->assertStringContainsString('<description>tidy up a file</description>', $prompt);

        // The path, because a description with nothing to read is an instruction the
        // model cannot obey.
        $this->assertStringContainsString('<location>' . $skills[0]->path . '</location>', $prompt);
        $this->assertStringContainsString('Use the read tool', $prompt);
    }

    public function testADescriptionInChineseIsMeasuredInCharactersNotBytes(): void
    {
        // 800 characters, well inside the spec's 1024 — and 2400 bytes, well outside it. The
        // ceiling is a character count in the spec and a code-unit count upstream, which for
        // anything either project reads is the same number; `strlen()` is neither.
        $long = str_repeat('描述', 400);
        $this->skill('home/.pig/agent/skills/cjk', "name: cjk\ndescription: {$long}");

        [$skills, $warnings] = $this->load();

        $this->assertCount(1, $skills);
        $this->assertSame([], $warnings);
    }

    public function testANameInTheWrongAlphabetIsCountedInCharactersToo(): void
    {
        $name = str_repeat('名', 40);
        $this->skill("home/.pig/agent/skills/{$name}", "name: {$name}\ndescription: something");

        [, $warnings] = $this->load();
        $said = implode(' | ', array_map(static fn ($one): string => $one->message, $warnings));

        // It is still the wrong alphabet, and that is worth one complaint. The length is not
        // one: forty characters is forty, where `strlen()` makes it a hundred and twenty.
        $this->assertStringContainsString('invalid characters', $said);
        $this->assertStringNotContainsString('exceeds 64', $said);
    }

    public function testASkillWhoseDescriptionIsNotUtf8StillHasOneInThePrompt(): void
    {
        $this->skill('home/.pig/agent/skills/logs', "name: logs\ndescription: reads a " . chr(0x80) . " log");

        [$skills] = $this->load();
        $prompt = Skills::forPrompt($skills);

        // `htmlspecialchars()` answers the **empty string** for input that is not UTF-8, so the
        // skill went into the prompt with `<description></description>`: listed, loadable, and
        // impossible for the model to ever choose, with nothing anywhere saying why.
        $this->assertStringContainsString('reads a', $prompt);
        $this->assertStringNotContainsString('<description></description>', $prompt);
        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
    }

    public function testAngleBracketsInADescriptionCannotBreakTheBlock(): void
    {
        $this->skill('home/.pig/agent/skills/xml', "name: xml\ndescription: use <read> & <write> carefully");

        [$skills] = $this->load();
        $prompt = Skills::forPrompt($skills);

        $this->assertStringContainsString('&lt;read&gt; &amp; &lt;write&gt;', $prompt);
        $this->assertStringNotContainsString('<read>', $prompt);
    }
}
