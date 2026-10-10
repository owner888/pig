<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\Agent\QueueMode;
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

    public function testTheProxyIsReadAndWritten(): void
    {
        $this->writeGlobal(['proxy' => ['url' => '  socks5://127.0.0.1:7891  ']]);

        // Trimmed, because this one gets pasted out of a Clash or v2ray window.
        $this->assertSame('socks5://127.0.0.1:7891', $this->load()->proxyUrl());

        $settings = $this->load();
        $settings->setProxyUrl('http://127.0.0.1:7890');

        $this->assertSame('http://127.0.0.1:7890', $this->load()->proxyUrl());

        $settings->setProxyUrl(null);

        $this->assertNull($this->load()->proxyUrl(), 'and turning it off is not an empty string');
    }

    public function testAnEmptyProxyUrlIsNoProxyRatherThanAnErrorLater(): void
    {
        $this->writeGlobal(['proxy' => ['url' => '   ']]);

        // `Proxy::parse('')` throws, and it should; a half-deleted line in a settings file is not
        // a reason to refuse to start.
        $this->assertNull($this->load()->proxyUrl());
    }

    public function testTheBypassListKeepsOnlyStrings(): void
    {
        $this->writeGlobal(['proxy' => ['bypass' => [' example.com ', '', 42, null, 'api.test']]]);

        $this->assertSame(['example.com', 'api.test'], $this->load()->proxyBypass());
    }

    public function testABypassThatIsNotAListIsEmptyRatherThanFatal(): void
    {
        $this->writeGlobal(['proxy' => ['bypass' => 'example.com']]);

        $this->assertSame([], $this->load()->proxyBypass());
    }

    public function testTheRetryKeysAreUpstreamsSpellings(): void
    {
        // A `settings.json` written for pi has to work here: upstream's `getRetrySettings()` keys,
        // all four, in milliseconds as the file writes them.
        $this->writeGlobal(['retry' => ['enabled' => false, 'maxRetries' => 7, 'baseDelayMs' => 500, 'maxAgentDelayMs' => 9000]]);

        $this->assertSame(
            ['enabled' => false, 'maxRetries' => 7, 'baseDelayMs' => 500.0, 'maxAgentDelayMs' => 9000.0],
            $this->load()->retrySettings(),
        );
    }

    public function testAMissingRetryKeyIsUpstreamsDefault(): void
    {
        // `SETTINGS_DEFAULTS.retry`: on, three retries, 2 s doubling, capped at a minute.
        $this->assertSame(
            ['enabled' => true, 'maxRetries' => 3, 'baseDelayMs' => 2000.0, 'maxAgentDelayMs' => 60000.0],
            $this->load()->retrySettings(),
        );
    }

    public function testNoRetriesIsANumberUpstreamTakesAtItsWord(): void
    {
        // `settings.retry?.maxRetries ?? 3`: 0 is a setting, not a missing one — it means none.
        // pig used to read anything under 1 as "not set" and retry three times anyway.
        $this->writeGlobal(['retry' => ['maxRetries' => 0, 'baseDelayMs' => 0]]);

        $settings = $this->load()->retrySettings();

        $this->assertSame(0, $settings['maxRetries']);
        $this->assertSame(0.0, $settings['baseDelayMs']);
    }

    public function testTheProviderRetryKeysAreUpstreamsToo(): void
    {
        // `getProviderRetrySettings()`: what a provider does with one request, under
        // `retry.provider` — and the legacy `retry.maxDelayMs` counted as its `maxRetryDelayMs`.
        $this->assertSame(['timeoutMs' => null, 'maxRetries' => null, 'maxRetryDelayMs' => 60_000], $this->load()->providerRetrySettings());

        $this->writeGlobal(['retry' => ['provider' => ['timeoutMs' => 1000, 'maxRetries' => 2, 'maxRetryDelayMs' => 5000]]]);
        $this->assertSame(['timeoutMs' => 1000, 'maxRetries' => 2, 'maxRetryDelayMs' => 5000], $this->load()->providerRetrySettings());

        $this->writeGlobal(['retry' => ['maxDelayMs' => 7000]]);
        $this->assertSame(7000, $this->load()->providerRetrySettings()['maxRetryDelayMs']);

        $this->writeGlobal(['retry' => ['maxDelayMs' => 7000, 'provider' => ['maxRetryDelayMs' => 0]]]);
        $this->assertSame(0, $this->load()->providerRetrySettings()['maxRetryDelayMs'], 'the new key wins, and 0 is no cap');
    }

    public function testTheHttpIdleTimeoutIsUpstreamsSetting(): void
    {
        $this->assertSame(300_000, $this->load()->httpIdleTimeoutMs());

        $this->writeGlobal(['httpIdleTimeoutMs' => 'disabled']);
        $this->assertSame(0, $this->load()->httpIdleTimeoutMs());

        $this->writeGlobal(['httpIdleTimeoutMs' => ' 60000 ']);
        $this->assertSame(60_000, $this->load()->httpIdleTimeoutMs());

        $this->writeGlobal(['httpIdleTimeoutMs' => -1]);
        $settings = $this->load();
        try {
            $settings->httpIdleTimeoutMs();
            $this->fail('a negative timeout was taken');
        } catch (\RuntimeException $error) {
            $this->assertSame('Invalid httpIdleTimeoutMs setting: -1', $error->getMessage());
        }
    }

    public function testTheUpdateCheckIsOnUnlessTheFileTurnsItOff(): void
    {
        // `retry.enabled` and `compaction.enabled`'s rule, on the third of the three: **only an
        // explicit `false` turns it off.** A missing key, a `null`, and a file that is not there
        // at all all mean on, because the default is on and a key nobody wrote is not an opinion.
        $this->assertTrue($this->load()->updateCheckEnabled(), 'no file at all');

        $this->writeGlobal(['update' => ['check' => false]]);

        $this->assertFalse($this->load()->updateCheckEnabled());
    }

    public function testAnUpdateKeyThatSaysSomethingElseDoesNotTurnItOff(): void
    {
        // The other half, and the reason the comparison is `!== false` rather than a truthiness
        // test: an empty `update` block is somebody who set a sibling key, and reading that as
        // "off" is a feature turning itself off because a neighbouring setting was written.
        $this->writeGlobal(['update' => []]);

        $this->assertTrue($this->load()->updateCheckEnabled());

        $this->writeGlobal(['update' => ['check' => true]]);

        $this->assertTrue($this->load()->updateCheckEnabled());
    }

    public function testAProjectCanTurnTheUpdateCheckOffForEverybodyInIt(): void
    {
        // The project file wins, which is the point of it being separate: a repository can say
        // "not here" without touching anyone's preferences, and the person's own file is what
        // they keep.
        $this->writeGlobal(['update' => ['check' => true]]);
        $this->writeProject(['update' => ['check' => false]]);

        $this->assertFalse($this->load()->updateCheckEnabled());
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

    // ---- the numbers, and what is not one --------------------------------------------------

    /**
     * The two accessors that take a fallback read `is_int($value) && $value > 0`, and the cases
     * below are the ways a hand-written file misses that. (The retry numbers are upstream's
     * `getRetrySettings()` now, which takes any number as it is — see the retry tests above.) A **quoted number** is the
     * one to expect — the same mistake `models.json`'s `cost` block has its own entry for — and
     * without the type check it comes back as a string out of a method declared `int`, which under
     * `strict_types` is a TypeError from somewhere that has nothing to do with the file.
     *
     * Zero is the other: somebody turning a limit off by setting it to nothing gets a reserve of
     * no tokens, which is compaction that never fires, or a kept-recent of nothing, which is a
     * compaction that summarises the message you just sent.
     *
     * @return iterable<string, array{0: string, 1: mixed, 2: bool}>
     */
    public static function numbersAndNonNumbers(): iterable
    {
        yield 'a whole count is the answer' => ['compaction.reserveTokens', 9_000, true];
        yield 'zero is not a limit' => ['compaction.reserveTokens', 0, false];
        yield 'nor is a negative one' => ['compaction.keepRecentTokens', -1, false];
    }

    #[DataProvider('numbersAndNonNumbers')]
    public function testANumberThatIsNotOneFallsBackRatherThanBeingUsed(string $key, mixed $value, bool $taken): void
    {
        [$head, $leaf] = explode('.', $key);
        $this->writeGlobal([$head => [$leaf => $value]]);

        $settings = $this->load();
        $answer = match ($key) {
            'compaction.reserveTokens' => $settings->compactionReserveTokens(1_111),
            'compaction.keepRecentTokens' => $settings->compactionKeepRecentTokens(1_111),
        };

        $this->assertSame($taken ? $value : 1_111, $answer);
    }

    /**
     * And the same for the three that answer with a string or with nothing.
     *
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function stringsAndNonStrings(): iterable
    {
        yield 'a theme' => ['theme', 'light'];
        yield 'a shell' => ['shellPath', '/opt/homebrew/bin/bash'];
        yield 'an external editor' => ['externalEditor', 'vim'];
        yield 'a model' => ['defaultModel', 'claude-sonnet-4-5'];
        yield 'a version somebody has seen' => ['lastChangelogVersion', '0.1.1'];
    }

    #[DataProvider('stringsAndNonStrings')]
    public function testAStringThatIsThereAndBlankIsNotSet(string $key, string $value): void
    {
        $read = fn (Settings $s): ?string => match ($key) {
            'theme' => $s->theme(),
            'shellPath' => $s->shellPath(),
            'externalEditor' => $s->externalEditor(),
            'defaultModel' => $s->defaultModel(),
            'lastChangelogVersion' => $s->lastChangelogVersion(),
        };

        $this->writeGlobal([$key => $value]);
        $this->assertSame($value, $read($this->load()));

        // `shellPath` is the one with teeth: an empty string there is refused by name rather than
        // falling back to `/bin/bash`, which is the bug the setting exists to work around.
        $this->writeGlobal([$key => '']);
        $this->assertNull($read($this->load()), 'present and blank');

        $this->writeGlobal([$key => 42]);
        $this->assertNull($read($this->load()), 'present and not a string');
    }

    // ---- what a setter writes is what the getter reads ---------------------------------------

    public function testEverySwitchGoesOutAndComesBackAsItself(): void
    {
        // Each pair is a setting something actually reads, and each half can fail on its own: a
        // setter that stopped writing leaves a screen that agrees with itself and a file that
        // does not, and a getter that stopped reading is a preference that silently does nothing.
        // `/settings` changes all of these and the file is the only thing that carries them to
        // the next run.
        $settings = $this->load();

        $settings->setHideThinking(true);
        $settings->setShowImages(false);
        $settings->setRetryEnabled(false);
        $settings->setCompactionEnabled(false);
        $settings->setQueueMode(QueueMode::All);
        $settings->setLastChangelogVersion('0.2.0');
        $settings->setExternalEditor('vim');

        foreach ([$settings, $this->load()] as $where) {
            $this->assertTrue($where->hideThinking());
            $this->assertFalse($where->showImages());
            $this->assertFalse($where->retryEnabled());
            $this->assertFalse($where->compactionEnabled());
            $this->assertSame(QueueMode::All, $where->queueMode());
            $this->assertSame('0.2.0', $where->lastChangelogVersion());
            $this->assertSame('vim', $where->externalEditor());
        }
    }

    public function testTheDefaultsAreWhatAnUntouchedFileMeans(): void
    {
        $settings = $this->load();

        // Three of these are on unless turned off and two are off unless turned on, and which is
        // which is a decision per setting rather than one rule — so a getter that lost its `get()`
        // would answer the default for ever and look right in exactly half the cases.
        $this->assertFalse($settings->hideThinking(), 'thinking is shown');
        $this->assertTrue($settings->showImages(), 'pictures are drawn where they can be');
        $this->assertTrue($settings->retryEnabled());
        $this->assertTrue($settings->compactionEnabled());
        $this->assertSame(QueueMode::OneAtATime, $settings->queueMode(), 'three thoughts, one at a time');
        $this->assertNull($settings->lastChangelogVersion(), 'never means a first run');
        $this->assertSame([], $settings->hooks());
        $this->assertSame([], $settings->customTools());
        $this->assertSame([], $settings->extensions());
    }

    public function testAQueueModeTheFileInventedIsTheOrdinaryOne(): void
    {
        $this->writeGlobal(['queueMode' => 'whenever']);

        // Not `all`: getting three separate thoughts at once is the surprising half of the choice,
        // so an unreadable value falls back to the unsurprising one.
        $this->assertSame(QueueMode::OneAtATime, $this->load()->queueMode());
    }

    public function testFilesNamedInTheSettingsComeBackAsPathsAndNothingElseComesBackAtAll(): void
    {
        $this->writeGlobal([
            'hooks' => ['~/hooks/guard.php', 12],
            'customTools' => ['~/tools/wc/index.php'],
            'extensions' => ['~/extensions/antigravity'],
        ]);

        $settings = $this->load();

        // Every entry becomes a string rather than being dropped, because the loader reports an
        // unreadable path by name and a silently shortened list is a hook somebody wrote and
        // never heard about again.
        $this->assertSame(['~/hooks/guard.php', '12'], $settings->hooks());
        $this->assertSame(['~/tools/wc/index.php'], $settings->customTools());
        $this->assertSame(['~/extensions/antigravity'], $settings->extensions());

        // A value that is not a list at all has no paths in it to name.
        $this->writeGlobal(['hooks' => 'not a list', 'customTools' => 'not a list', 'extensions' => 'not a list']);

        $settings = $this->load();

        $this->assertSame([], $settings->hooks());
        $this->assertSame([], $settings->customTools());
        $this->assertSame([], $settings->extensions());
    }

    public function testDisabledExtensionsAreReadFromSettings(): void
    {
        $this->writeGlobal([
            'disabledExtensions' => ['pig-android-use', 'pig-computer'],
        ]);

        $settings = $this->load();
        $this->assertSame(['pig-android-use', 'pig-computer'], $settings->disabledExtensions());

        $this->writeGlobal(['disabledExtensions' => 'not an array']);
        $settings = $this->load();
        $this->assertSame([], $settings->disabledExtensions());
    }

    // ---- not writing ---------------------------------------------------------------------

    public function testInMemorySettingsAreNeverWrittenAnywhere(): void
    {
        $settings = Settings::inMemory(['theme' => 'light']);
        $settings->setTheme('dark');

        $this->assertSame('dark', $settings->theme());
        $this->assertFileDoesNotExist($this->home . '/settings.json');
    }

    public function testTheFullscreenSettingsHaveUpstreamsDefaultsAndBounds(): void
    {
        $settings = Settings::inMemory();

        $this->assertSame('transcript', $settings->fullscreenExitOutput());
        $this->assertSame('auto', $settings->fullscreenScrollbar());
        $this->assertTrue($settings->fullscreenCopyOnSelect());
        $this->assertSame('auto', $settings->fullscreenWheelScrollLines());

        $settings->setFullscreenExitOutput('resume-hint');
        $settings->setFullscreenScrollbar('hidden');
        $settings->setFullscreenCopyOnSelect(false);
        $settings->setFullscreenWheelScrollLines(500);

        $this->assertSame('resume-hint', $settings->fullscreenExitOutput());
        $this->assertSame('hidden', $settings->fullscreenScrollbar());
        $this->assertFalse($settings->fullscreenCopyOnSelect());
        $this->assertSame(100, $settings->fullscreenWheelScrollLines());
        $this->assertSame(3, Settings::inMemory(['fullscreenWheelScrollLines' => 3.7])->fullscreenWheelScrollLines());
        $this->assertSame('auto', Settings::inMemory(['fullscreenScrollbar' => 'sometimes'])->fullscreenScrollbar());
    }

    public function testCacheWarmingIsAGlobalSettingThatDefaultsToStreaming(): void
    {
        $home = sys_get_temp_dir() . '/pig-cw-' . bin2hex(random_bytes(4));
        $cwd = $home . '/project';
        mkdir($cwd . '/.pig', 0o700, true);
        file_put_contents($home . '/settings.json', '{"cacheWarming": "idle"}');
        file_put_contents($cwd . '/.pig/settings.json', '{"cacheWarming": "off"}');

        // Upstream reads it from the global settings only: a repository does not get to spend
        // somebody's money keeping a cache warm, nor to stop them doing it.
        $this->assertSame('idle', Settings::load($cwd, $home)->cacheWarmingMode());
        $this->assertSame('streaming', Settings::inMemory()->cacheWarmingMode());
        $this->assertSame('streaming', Settings::inMemory(['cacheWarming' => 'always'])->cacheWarmingMode());

        $settings = Settings::inMemory();
        $settings->setCacheWarmingMode('off');
        $this->assertSame('off', $settings->cacheWarmingMode());
    }
}
