<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\CodingAgent\Settings;

/** What someone chose last time, and what the project insists on. */
final class SettingsTest extends TestCase
{
    private string $root;

    private string $home;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-settings-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home/.pig';
        $this->cwd = $this->root . '/project';
        mkdir($this->cwd . '/.pig', 0o755, true);
        mkdir($this->home, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
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

    /** @param array<string, mixed> $settings */
    private function writeGlobal(array $settings): void
    {
        file_put_contents($this->home . '/settings.json', (string) json_encode($settings));
    }

    /** @param array<string, mixed> $settings */
    private function writeProject(array $settings): void
    {
        file_put_contents($this->cwd . '/.pig/settings.json', (string) json_encode($settings));
    }

    private function load(): Settings
    {
        return Settings::load($this->cwd, $this->home);
    }

    // ---- reading ------------------------------------------------------------------------

    public function testNoFilesAnywhereIsNotAProblem(): void
    {
        $settings = $this->load();

        $this->assertSame([], $settings->problems());
        $this->assertNull($settings->theme());
        $this->assertNull($settings->defaultModel());
    }

    public function testWhatWasSavedIsWhatComesBack(): void
    {
        $this->writeGlobal(['theme' => 'light', 'defaultModel' => 'claude-haiku-4-5']);

        $settings = $this->load();

        $this->assertSame('light', $settings->theme());
        $this->assertSame('claude-haiku-4-5', $settings->defaultModel());
    }

    public function testNestedSettingsAreReachedByPath(): void
    {
        $this->writeGlobal(['compaction' => ['keepRecentTokens' => 5000]]);

        $this->assertSame(5000, $this->load()->compactionKeepRecentTokens(20_000));
    }

    public function testAMissingSettingFallsBackToWhatTheCallerBrought(): void
    {
        // The default lives next to the thing that uses it, not here, where nothing
        // would explain it.
        $this->assertSame(20_000, $this->load()->compactionKeepRecentTokens(20_000));
        $this->assertTrue($this->load()->compactionEnabled());
        $this->assertTrue($this->load()->showImages());
    }

    public function testAFileThatIsNotJsonIsNamedRatherThanIgnored(): void
    {
        file_put_contents($this->home . '/settings.json', '{ oops');

        $settings = $this->load();

        // A typo here is otherwise a setting that quietly does nothing for ever.
        $this->assertCount(1, $settings->problems());
        $this->assertStringContainsString('not valid JSON', $settings->problems()[0]);
        $this->assertNull($settings->theme());
    }

    // ---- the project's word ----------------------------------------------------------------

    public function testTheProjectWinsOverThePerson(): void
    {
        $this->writeGlobal(['theme' => 'light']);
        $this->writeProject(['theme' => 'dark']);

        $this->assertSame('dark', $this->load()->theme());
    }

    public function testOnlyTheKeysTheProjectStatesAreOverridden(): void
    {
        $this->writeGlobal(['compaction' => ['enabled' => false, 'keepRecentTokens' => 5000]]);
        $this->writeProject(['compaction' => ['keepRecentTokens' => 9000]]);

        $settings = $this->load();

        // Merged one level deep, so a project can say one thing about compaction without
        // saying everything about it.
        $this->assertSame(9000, $settings->compactionKeepRecentTokens(20_000));
        $this->assertFalse($settings->compactionEnabled());
    }

    public function testAProjectListReplacesRatherThanAppends(): void
    {
        $this->writeGlobal(['skills' => ['ignoredSkills' => ['mine-*']]]);
        $this->writeProject(['skills' => ['ignoredSkills' => ['theirs-*']]]);

        // A list is an answer, not a contribution: merging two would give a project no
        // way to *stop* ignoring something.
        $this->assertSame(['theirs-*'], $this->load()->skillList('ignoredSkills'));
    }

    // ---- writing -------------------------------------------------------------------------

    public function testASettingSurvivesToTheNextRun(): void
    {
        $this->load()->setTheme('light');

        $this->assertSame('light', $this->load()->theme());
    }

    public function testOnlyThePersonsFileIsWritten(): void
    {
        $this->writeProject(['theme' => 'dark']);

        $settings = $this->load();
        $settings->setTheme('light');

        // The project file is read-only, and its answer still applies afterwards.
        $this->assertSame('dark', $settings->theme());
        $this->assertSame(['theme' => 'dark'], json_decode((string) file_get_contents($this->cwd . '/.pig/settings.json'), true));
        $this->assertSame('light', json_decode((string) file_get_contents($this->home . '/settings.json'), true)['theme']);
    }

    public function testANestedSettingIsWrittenNested(): void
    {
        $this->load()->set('compaction.keepRecentTokens', 1234);

        $saved = json_decode((string) file_get_contents($this->home . '/settings.json'), true);

        $this->assertSame(1234, $saved['compaction']['keepRecentTokens']);
    }

    public function testTheFileIsWrittenSoAPersonCanEditIt(): void
    {
        $this->load()->setTheme('light');

        // Settings are meant to be opened in an editor, which one long line is not.
        $this->assertStringContainsString("\n", (string) file_get_contents($this->home . '/settings.json'));
    }

    public function testSettingTheModelRemembersItsProviderToo(): void
    {
        $this->load()->setDefaultModel('grok-4', 'xai');

        $saved = json_decode((string) file_get_contents($this->home . '/settings.json'), true);

        $this->assertSame('grok-4', $saved['defaultModel']);
        $this->assertSame('xai', $saved['defaultProvider']);
    }

    public function testThinkingLevelsGoOutAndComeBackAsThemselves(): void
    {
        $this->load()->setDefaultThinkingLevel(ThinkingLevel::High);

        $this->assertSame(ThinkingLevel::High, $this->load()->defaultThinkingLevel());
    }

    public function testAnUnknownThinkingLevelIsNullRatherThanAGuess(): void
    {
        $this->writeGlobal(['defaultThinkingLevel' => 'enormous']);

        $this->assertNull($this->load()->defaultThinkingLevel());
    }

    // ---- not writing ---------------------------------------------------------------------

    public function testInMemorySettingsAreNeverWrittenAnywhere(): void
    {
        $settings = Settings::inMemory(['theme' => 'light']);
        $settings->setTheme('dark');

        $this->assertSame('dark', $settings->theme());
        $this->assertFileDoesNotExist($this->home . '/settings.json');
    }
}
