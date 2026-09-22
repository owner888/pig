<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Test\AssertsThrows;

final class SystemPromptTest extends ToolTestCase
{
    use AssertsThrows;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // A real ~/.pig would make these tests depend on whoever is running them.
        $this->home = $this->cwd . '/pig-home';
        mkdir($this->home, 0o755, true);
        putenv('PIG_HOME=' . $this->home);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        parent::tearDown();
    }

    /** @param list<string> $tools */
    private function prompt(array $tools = ToolSet::CODING): string
    {
        return SystemPrompt::build($this->cwd, $tools);
    }

    // ---- the tool set --------------------------------------------------------------

    public function testTheDefaultSetIsEnoughToDoWork(): void
    {
        $tools = ToolSet::create($this->cwd);

        $this->assertCount(4, $tools);
        $this->assertInstanceOf(ReadTool::class, $tools[0]);
        $this->assertInstanceOf(BashTool::class, $tools[1]);
    }

    public function testTheReadOnlySetCannotChangeAnything(): void
    {
        $names = array_map(
            static fn (object $tool): string => $tool->definition()->name,
            ToolSet::create($this->cwd, ToolSet::READ_ONLY),
        );

        $this->assertSame(['read', 'grep', 'find', 'ls'], $names);
        // Stronger than asking the model not to.
        $this->assertNotContains('write', $names);
        $this->assertNotContains('bash', $names);
    }

    public function testEveryNamedToolCanBeBuilt(): void
    {
        foreach (ToolSet::ALL as $name) {
            $this->assertSame($name, ToolSet::one($this->cwd, $name)->definition()->name);
        }
    }

    public function testAnUnknownToolNameSaysWhatIsAvailable(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => ToolSet::one($this->cwd, 'telepathy'),
            "Unknown tool 'telepathy'",
        );

        $this->assertStringContainsString('read, bash, edit, write', $error->getMessage());
    }

    // ---- the prompt ----------------------------------------------------------------

    public function testThePromptListsTheToolsTheRunActuallyHas(): void
    {
        $prompt = $this->prompt(['read', 'ls']);

        $this->assertStringContainsString('- read: ', $prompt);
        $this->assertStringContainsString('- ls: ', $prompt);
        $this->assertStringNotContainsString('- bash: ', $prompt);
    }

    public function testGuidelinesFollowFromTheToolsAndNotFromAFixedList(): void
    {
        $full = $this->prompt(ToolSet::CODING);

        $this->assertStringContainsString('Read a file before editing it', $full);
        $this->assertStringContainsString('Use write only for new files', $full);

        $readOnly = $this->prompt(ToolSet::READ_ONLY);

        // A model told to read before editing when it cannot edit learns that the
        // instructions here are approximate.
        $this->assertStringNotContainsString('before editing it', $readOnly);
        $this->assertStringContainsString('READ-ONLY mode', $readOnly);
    }

    public function testBashWithNothingToWriteWithIsSaidToBeReadOnlyBash(): void
    {
        $prompt = $this->prompt(['read', 'bash']);

        $this->assertStringContainsString('read-only work only', $prompt);
        $this->assertStringNotContainsString('READ-ONLY mode', $prompt);
    }

    public function testWithBashAndNoSearchToolsBashIsWhatToSearchWith(): void
    {
        $this->assertStringContainsString('Use bash for file work', $this->prompt(['read', 'bash', 'edit']));
    }

    public function testWithSearchToolsTheyArePreferredOverBash(): void
    {
        $this->assertStringContainsString(
            'Prefer grep, find and ls over bash',
            $this->prompt(['read', 'bash', 'grep', 'find']),
        );
    }

    public function testTheWorkingDirectoryAndTimeComeLast(): void
    {
        $prompt = $this->prompt();
        $lines = explode("\n", rtrim($prompt));

        $this->assertStringStartsWith('Current working directory: ', $lines[count($lines) - 1]);
        $this->assertStringStartsWith('Current date and time: ', $lines[count($lines) - 2]);
    }

    public function testACustomPromptReplacesTheDefaultButKeepsTheFacts(): void
    {
        $prompt = SystemPrompt::build($this->cwd, ToolSet::CODING, custom: 'Speak only in haiku.');

        $this->assertStringStartsWith('Speak only in haiku.', $prompt);
        $this->assertStringNotContainsString('expert coding assistant', $prompt);
        $this->assertStringContainsString('Current working directory:', $prompt);
    }

    public function testAppendedTextGoesAfterWhicheverPromptWasUsed(): void
    {
        $prompt = SystemPrompt::build($this->cwd, ToolSet::CODING, append: 'Always use tabs.');

        $this->assertStringContainsString('expert coding assistant', $prompt);
        $this->assertStringContainsString('Always use tabs.', $prompt);
    }

    public function testAPromptGivenAsAPathIsReadFromThatFile(): void
    {
        $path = $this->file('prompt.md', 'From a file.');

        // `--system-prompt ./prompt.md` means the file; a sentence means the sentence.
        $this->assertSame('From a file.', SystemPrompt::resolve($path));
        $this->assertSame('Just a sentence', SystemPrompt::resolve('Just a sentence'));
        $this->assertNull(SystemPrompt::resolve(null));
    }

    // ---- context files -------------------------------------------------------------

    public function testAContextFileIsIncludedWithItsPath(): void
    {
        $this->file('AGENTS.md', 'Run the tests with make check.');

        $prompt = $this->prompt();

        $this->assertStringContainsString('# Project context', $prompt);
        $this->assertStringContainsString('Run the tests with make check.', $prompt);
        // The path is given because the model is often asked to update these files.
        $this->assertStringContainsString($this->cwd . '/AGENTS.md', $prompt);
    }

    public function testClaudeMdIsAcceptedUnderTheSameRules(): void
    {
        $this->file('CLAUDE.md', 'From CLAUDE.md.');

        $this->assertStringContainsString('From CLAUDE.md.', $this->prompt());
    }

    public function testADirectoryWithBothGetsOnlyAgentsMd(): void
    {
        $this->file('AGENTS.md', 'preferred');
        $this->file('CLAUDE.md', 'ignored');

        $prompt = $this->prompt();

        $this->assertStringContainsString('preferred', $prompt);
        $this->assertStringNotContainsString('ignored', $prompt);
    }

    public function testFilesUpTheTreeApplyOutermostFirst(): void
    {
        $this->file('AGENTS.md', 'ROOT RULES');
        mkdir($this->cwd . '/packages/thing', 0o755, true);
        file_put_contents($this->cwd . '/packages/thing/AGENTS.md', 'PACKAGE RULES');

        $files = ContextFiles::load($this->cwd . '/packages/thing');
        $contents = array_map(static fn (ContextFile $file): string => $file->content, $files);

        // The general rules, then the ones that narrow them.
        $this->assertSame(['ROOT RULES', 'PACKAGE RULES'], $contents);
    }

    public function testThePersonsOwnFileComesBeforeTheProjects(): void
    {
        file_put_contents($this->home . '/AGENTS.md', 'GLOBAL');
        $this->file('AGENTS.md', 'PROJECT');

        $contents = array_map(
            static fn (ContextFile $file): string => $file->content,
            ContextFiles::load($this->cwd),
        );

        // A project can override what the person set, not the other way round.
        $this->assertSame(['GLOBAL', 'PROJECT'], $contents);
    }

    public function testTheSameFileIsNotIncludedTwice(): void
    {
        // Running inside the config directory itself: the global file and the project
        // file are the same file.
        file_put_contents($this->home . '/AGENTS.md', 'ONCE');

        $this->assertCount(1, ContextFiles::load($this->home));
    }

    public function testNoContextFilesMeansNoSection(): void
    {
        $this->assertStringNotContainsString('# Project context', $this->prompt());
    }

    // ---- skills ------------------------------------------------------------------------

    public function testSkillsAreListedForTheModelToReachFor(): void
    {
        $prompt = SystemPrompt::build(
            $this->cwd,
            ToolSet::CODING,
            skills: [new Skill('tidy', 'tidy up a file', '/skills/tidy/SKILL.md', '/skills/tidy', 'user')],
        );

        $this->assertStringContainsString('<available_skills>', $prompt);
        $this->assertStringContainsString('<name>tidy</name>', $prompt);
        $this->assertStringContainsString('/skills/tidy/SKILL.md', $prompt);
    }

    public function testNoSkillsMeansNoSection(): void
    {
        // A hundred skills cost a hundred lines here; none should cost nothing at all.
        $this->assertStringNotContainsString('<available_skills>', $this->prompt());
    }

    public function testTheProjectsOwnInstructionsComeBeforeTheSkills(): void
    {
        $this->file('AGENTS.md', 'Run the tests with make check.');

        $prompt = SystemPrompt::build(
            $this->cwd,
            ToolSet::CODING,
            skills: [new Skill('tidy', 'tidy up a file', '/skills/tidy/SKILL.md', '/skills/tidy', 'user')],
        );

        // A skill is a thing to reach for; the rules about how to work here apply
        // whichever one is reached for, so they are not read past to get to it.
        $this->assertLessThan(
            strpos($prompt, '<available_skills>'),
            strpos($prompt, '# Project context'),
        );
    }

    public function testGivenContextFilesAreUsedInsteadOfLookingForThem(): void
    {
        $this->file('AGENTS.md', 'ON DISK');

        $prompt = SystemPrompt::build(
            $this->cwd,
            ToolSet::CODING,
            contextFiles: [new ContextFile('/made/up.md', 'PASSED IN')],
        );

        $this->assertStringContainsString('PASSED IN', $prompt);
        $this->assertStringNotContainsString('ON DISK', $prompt);
    }

    public function testTheConfigDirectoryCanBeMoved(): void
    {
        $this->assertSame($this->home, Config::home());
    }
}
