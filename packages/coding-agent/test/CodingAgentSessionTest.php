<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\TextContent;
use Pig\Ai\StopReason;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\CodingAgentError;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\StartedSession;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Test\AssertsThrows;
use Pig\Test\ProbeModels;
use Pig\Test\WithoutProviderKeys;

/**
 * `CodingAgent::session()` — the startup, minus the terminal.
 *
 * Every case here was, until this class existed, two hundred lines in the middle of `bin/pig`: a
 * script that ends in `exit()` cannot be called twice, so which of `--model`, `PIG_MODEL`, the
 * settings and the built-in default wins was only ever checked by running pig and looking. That is
 * also why nothing in `session()` writes to a stream — warnings come back on the result and
 * anything fatal throws.
 */
final class CodingAgentSessionTest extends TestCase
{
    use AssertsThrows;
    use ProbeModels;
    use WithoutProviderKeys;

    private string $root;

    private string $home;

    private string $cwd;

    private string|false $realHome = false;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->root = sys_get_temp_dir() . '/pig-startup-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home, 0o755, true);
        mkdir($this->cwd . '/.pig', 0o755, true);
        putenv('PIG_HOME=' . $this->home);
        // Pointed somewhere empty: `SessionManager` reads pi's directory too, and on a real machine
        // that is a real directory with real conversations in it.
        putenv('PI_HOME=' . $this->root . '/pi');
        // And `HOME`, because `Skills::load()` reads `~/.claude/skills` and `~/.codex/skills` as
        // well as pig's own. Without this the container these tests were written on contributed a
        // real skill of its own to every assertion about which skills loaded — a test that passes
        // or fails on what the person running it happens to have installed.
        $this->realHome = getenv('HOME');
        putenv('HOME=' . $this->root . '/user');

        // For the same reason: coming back to the model a conversation was on needs a key for it,
        // and `Auth` reads the environment last. Whether the machine running these tests happens
        // to have a provider key set is not something an assertion should turn on.
        $this->forgetProviderKeys();

        // Which model each precedence case gets is a fact about the order, not about what
        // Anthropic sells this week — see `ProbeModels`.
        self::registerProbeModels();
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('PI_HOME');
        putenv($this->realHome === false ? 'HOME' : 'HOME=' . $this->realHome);

        $this->restoreProviderKeys();
        Models::forgetRegistered();
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
    private function writeSettings(array $settings): void
    {
        file_put_contents($this->home . '/settings.json', (string) json_encode($settings));
    }

    /**
     * @param array<string, string|false> $environment
     * @param array<string, mixed>        $named
     */
    public function testAnUntrustedProjectWithResourcesSaysSoAndLoadsNoneOfThem(): void
    {
        mkdir($this->cwd . '/.pig/commands', 0o755, true);
        file_put_contents($this->cwd . '/.pig/commands/deploy.md', "ship it\n");

        $trusted = $this->start();
        $this->assertSame(['deploy'], array_map(static fn ($c) => $c->name, $trusted->fileCommands));
        $this->assertSame([], $trusted->warnings);

        $untrusted = $this->start([], ['projectTrusted' => false]);
        $this->assertSame([], $untrusted->fileCommands);
        $this->assertContains(\Pig\CodingAgent\ProjectTrust::warning(), $untrusted->warnings);
    }

    public function testAnExtensionsOwnDirectoriesJoinTheSessionAfterSessionStart(): void
    {
        mkdir($this->home . '/extensions/mine/skills/tidy', 0o755, true);
        mkdir($this->home . '/extensions/mine/prompts', 0o755, true);
        mkdir($this->home . '/extensions/mine/themes', 0o755, true);
        file_put_contents($this->home . '/extensions/mine/skills/tidy/SKILL.md', "---\nname: tidy\ndescription: Tidies things up\n---\nDo it.\n");
        file_put_contents($this->home . '/extensions/mine/prompts/greet.md', "say hello to \$1\n");
        file_put_contents($this->home . '/extensions/mine/index.php', <<<'PHP'
            <?php
            use Pig\CodingAgent\Hooks\Results\ResourcesDiscoverResult;
            return function ($pi): void {
                $pi->on('resources_discover', fn ($event) => new ResourcesDiscoverResult(
                    skillPaths: ['skills'],
                    promptPaths: ['prompts'],
                    themePaths: ['themes'],
                ));
            };
            PHP);

        $started = $this->start();

        // Nothing of it before the event: upstream fires it after `session_start`.
        $this->assertSame([], $started->fileCommands);
        $this->assertSame([], $started->skills);

        $added = $started->session->discoverResources('startup');

        $this->assertNotNull($added);
        $this->assertSame(['tidy'], array_map(static fn ($s) => $s->name, $added->skills));
        $this->assertSame('extension', $added->skills[0]->source);
        $this->assertSame(['greet'], array_map(static fn ($c) => $c->name, $added->commands));
        $this->assertStringContainsString('tidy', $started->session->loadout()?->systemPrompt() ?? '', 'the skill reaches the prompt');
        $this->assertContains($this->home . '/extensions/mine/themes', \Pig\CodingAgent\Theme\Themes::getCustomThemesDirs());

        // Asked again on a reload, the lists are replaced rather than doubled.
        $again = $started->session->discoverResources('reload');
        $this->assertCount(1, $again?->commands ?? []);
        \Pig\CodingAgent\Theme\Themes::setExtensionThemeDirs([]);
    }

    public function testWithNobodyListeningForResourcesThereIsNothingToTake(): void
    {
        $started = $this->start();

        $this->assertNull($started->session->discoverResources('startup'));
    }

    public function testAnUntrustedProjectWithNothingToTrustIsNotWarnedAbout(): void
    {
        $started = $this->start([], ['projectTrusted' => false]);

        // Nothing under `.pig/` worth gating, so a warning would be about nothing.
        $this->assertSame([], $started->warnings);
    }

    public function testAContextFileThatCannotBeReadIsAmongTheStartupWarnings(): void
    {
        $path = $this->cwd . '/AGENTS.md';
        file_put_contents($path, 'be careful');
        chmod($path, 0o000);

        if (is_readable($path)) {
            // root reads whatever it likes, so this runs where the suite actually runs.
            $this->markTestSkipped('cannot make a file unreadable as this user');
        }

        $started = $this->start();

        // `ContextFiles` collects the complaint; this is the other end of that wire, and the
        // mutation check is what said it was missing — taking the loop out of `session()`
        // broke no test at all.
        $this->assertNotSame([], $started->warnings);
        $this->assertStringContainsString('AGENTS.md could not be read', implode(' | ', $started->warnings));
    }

    private function start(array $environment = [], array $named = [], ?Auth $auth = null): StartedSession
    {
        $settings = Settings::load($this->cwd, $this->home);

        // Spread last, which PHP requires: named arguments cannot be followed by unpacking.
        $arguments = [
            $this->cwd,
            $settings,
            $auth ?? Auth::inMemory($settings),
            'environment' => $environment,
            ...$named,
        ];

        return Async::run(static fn (): StartedSession => CodingAgent::session(...$arguments));
    }

    // ---- which model ---------------------------------------------------------------------------

    public function testWhatWasTypedWins(): void
    {
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'anthropic']);

        $started = $this->start(['PIG_MODEL' => 'plain'], ['model' => 'zzp-beta-20250101']);

        $this->assertSame('zzp-beta-20250101', $started->model->id);
    }

    public function testTheEnvironmentComesNext(): void
    {
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'anthropic']);

        $started = $this->start(['PIG_MODEL' => 'zzp-beta-20250101']);

        $this->assertSame('zzp-beta-20250101', $started->model->id);
    }

    public function testThenWhatWasChosenLastTime(): void
    {
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'anthropic']);

        $this->assertSame('zzp-alpha', $this->start()->model->id);
    }

    public function testTheRememberedProviderComesBackWithTheRememberedModel(): void
    {
        // **The bug this is here for**: `defaultModel` is a *bare* id and a bare id means the
        // direct provider, so a session last used on Antigravity's `gemini-3.8-flash` reopened on
        // Google's public model of the same name — same id, different model. `defaultProvider`
        // was written from the start and read by nothing.
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'github-copilot']);

        $started = $this->start(['GITHUB_TOKEN' => 'gho_x']);

        $this->assertSame('zzp-alpha', $started->model->id);
        $this->assertSame('github-copilot', $started->model->provider);
    }

    public function testWithNoRememberedProviderABareIdStillMeansTheDirectOne(): void
    {
        // Settings files written before the provider was read back have no `defaultProvider`.
        $this->writeSettings(['defaultModel' => 'zzp-alpha']);

        $this->assertSame('anthropic', $this->start(['GITHUB_TOKEN' => 'gho_x'])->model->provider);
    }

    public function testARememberedProviderThatHasGoneFallsBackRatherThanRefusingToStart(): void
    {
        // `google-antigravity` is exactly this case: it was renamed, and any settings file written
        // before that still names it — and `antigravity` itself is the case now, for a settings
        // file written while the extension was installed. Failing here would be a tool that will
        // not start because of a line it wrote itself.
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'antigravity']);

        $started = $this->start();

        $this->assertSame('zzp-alpha', $started->model->id);
        $this->assertSame('anthropic', $started->model->provider);
    }

    public function testWhatWasTypedIsNotSecondGuessedWithTheRememberedProvider(): void
    {
        // A bare id somebody typed means what it says. Applying the stored provider to it would
        // be a worse bug than the one above: `--model gemini-3.8-flash` would quietly become
        // Antigravity's because of something the last session saved.
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'anthropic']);

        $this->assertSame('anthropic', $this->start([], ['model' => 'zzp-alpha'])->model->provider);
        $this->assertSame('anthropic', $this->start(['PIG_MODEL' => 'zzp-alpha'])->model->provider);
    }

    public function testPiModelAndPiProviderAreSupportedAsEnvironmentFallbacks(): void
    {
        $started = $this->start(['PI_MODEL' => 'zzp-alpha', 'PI_PROVIDER' => 'github-copilot', 'PI_REASONING_LEVEL' => 'medium', 'GITHUB_TOKEN' => 'gho_x']);
        $this->assertSame('github-copilot', $started->model->provider);
        $this->assertSame('zzp-alpha', $started->model->id);
        $this->assertSame(ThinkingLevel::Medium, $started->thinking);
    }

    public function testStartupThinkingLevelIsClampedForModelsThatRequireReasoning(): void
    {
        // A model with `off => null` in its thinkingLevelMap cannot be turned off (every
        // Antigravity row is one). Startup must clamp `off` to a level it has rather than leaving
        // it `off`, or the first request is refused.
        $started = $this->start(['GITHUB_TOKEN' => 'gho_x'], ['model' => 'github-copilot/zzp-thinker']);
        $this->assertNotSame(ThinkingLevel::Off, $started->thinking);
    }

    public function testAndFinallyTheBuiltInDefault(): void
    {
        // `CodingAgent::DEFAULT_MODEL`, which both READMEs have named all along. It was
        // `antigravity/gemini-3.8-flash` for a while — a provider that only exists once an
        // extension has loaded, which is that extension's default to make and not the core's.
        $default = $this->start();
        $this->assertSame(CodingAgent::DEFAULT_MODEL, $default->model->id);
        $this->assertSame('anthropic', $default->model->provider);
        $this->assertSame(ThinkingLevel::Off, $default->thinking);
    }

    public function testAnEmptyEnvironmentVariableIsOffRatherThanAModelCalledNothing(): void
    {
        // `PIG_MODEL= pig …` is how somebody turns it off for one command.
        $this->writeSettings(['defaultModel' => 'zzp-alpha', 'defaultProvider' => 'anthropic']);

        $this->assertSame('zzp-alpha', $this->start(['PIG_MODEL' => ''])->model->id);
        $this->assertSame('zzp-alpha', $this->start(['PIG_MODEL' => false])->model->id);
    }

    public function testAPartOfTheNameIsEnough(): void
    {
        $this->assertSame('zzp-alpha', $this->start([], ['model' => 'Probe Alpha'])->model->id);
    }

    public function testAModelThatMatchesNothingRefusesToStart(): void
    {
        $error = $this->assertThrows(
            CodingAgentError::class,
            fn () => $this->start([], ['model' => 'gpt-9-ultra']),
        );

        $this->assertStringContainsString("No model matches 'gpt-9-ultra'", $error->getMessage());
        $this->assertStringContainsString('--list-models', $error->getMessage(), 'and where to look');
    }

    public function testTheResolversOwnWarningIsAWarningAndNotARefusal(): void
    {
        // `sonnet:veryhard` names a model and then a thinking level that is not one. The model was
        // understood, so the run goes on — with a sentence saying what was dropped.
        $started = $this->start([], ['model' => 'zzp-alpha:veryhard']);

        $this->assertSame('zzp-alpha', $started->model->id);
        $this->assertSame(ThinkingLevel::Off, $started->thinking);
        $this->assertStringContainsString('veryhard', $started->warnings[0]);
    }

    // ---- how hard to think ---------------------------------------------------------------------

    public function testThinkingFromTheFlagBeatsTheSuffixOnTheModel(): void
    {
        $started = $this->start([], ['model' => 'zzp-alpha:low', 'thinking' => 'high']);

        $this->assertSame(ThinkingLevel::High, $started->thinking);
    }

    public function testASuffixOnTheModelBeatsTheSetting(): void
    {
        $this->writeSettings(['defaultThinkingLevel' => 'minimal']);

        $this->assertSame(ThinkingLevel::High, $this->start([], ['model' => 'sonnet:high'])->thinking);
    }

    public function testTheSettingIsUsedWhenNeitherSaysAnything(): void
    {
        $this->writeSettings(['defaultThinkingLevel' => 'medium']);

        $this->assertSame(ThinkingLevel::Medium, $this->start()->thinking);
    }

    public function testNothingAnywhereIsOff(): void
    {
        $this->assertSame(ThinkingLevel::Off, $this->start()->thinking);
    }

    public function testAThinkingLevelThatIsNotOneClampsToSupportedLevel(): void
    {
        // A level that is not one reads as `off`, and a model that refuses `off` clamps that up
        // to the nearest level it has.
        $this->assertSame(ThinkingLevel::Low, $this->start(['GITHUB_TOKEN' => 'gho_x'], ['model' => 'github-copilot/zzp-thinker', 'thinking' => 'very hard'])->thinking);
        $this->assertSame(ThinkingLevel::Off, $this->start([], ['thinking' => 'very hard'])->thinking);
    }

    public function testAModelThatCannotReasonIsClampedRatherThanRefused(): void
    {
        // A request that asks a non-reasoning model to think is one the provider rejects, and
        // nobody typing `--thinking high` meant to be told no.
        $started = $this->start([], ['model' => 'zzp-plain', 'thinking' => 'high']);

        $this->assertFalse($started->model->reasoning);
        $this->assertSame(ThinkingLevel::Off, $started->thinking);
    }

    // ---- the scope `--models` sets --------------------------------------------------------------

    public function testTheFirstOfTheScopeIsWhatTheSessionOpensOn(): void
    {
        putenv('ANTHROPIC_API_KEY=sk-test');

        try {
            $started = $this->start([], ['models' => ['zzp-plain', 'zzp-alpha']]);

            // Upstream's rule: below `--model`, above the environment and the settings. So
            // `pig --models haiku,opus` opens on haiku, and ctrl+p reaches opus and nothing else.
            $this->assertSame('zzp-plain', $started->model->id);
            $this->assertSame('anthropic', $started->model->provider);
            $this->assertSame(['zzp-plain', 'zzp-alpha'], array_map(
                static fn (object $choice): string => $choice->model->id,
                $started->session->modelScope(),
            ));
        } finally {
            putenv('ANTHROPIC_API_KEY');
        }
    }

    public function testAModelNamedOutrightStillBeatsTheScope(): void
    {
        putenv('ANTHROPIC_API_KEY=sk-test');

        try {
            $started = $this->start([], ['models' => ['zzp-plain'], 'model' => 'zzp-alpha']);

            // The scope is still the scope — it is what ctrl+p walks — but what the session opens
            // on is the one model somebody named.
            $this->assertSame('zzp-alpha', $started->model->id);
            $this->assertCount(1, $started->session->modelScope());
        } finally {
            putenv('ANTHROPIC_API_KEY');
        }
    }

    public function testAPatternThatMatchesNothingIsAWarningAndTheSessionStartsAnyway(): void
    {
        putenv('ANTHROPIC_API_KEY=sk-test');

        try {
            $started = $this->start([], ['models' => ['no-such-model-anywhere']]);

            $this->assertSame(['No model matches "no-such-model-anywhere".'], $started->warnings);

            // And with nothing in the scope the ordinary order decides, rather than a session that
            // refuses to start over one typo in a flag that is a preference.
            $this->assertSame(CodingAgent::DEFAULT_MODEL, $started->model->id);
            $this->assertSame([], $started->session->modelScope());
        } finally {
            putenv('ANTHROPIC_API_KEY');
        }
    }

    // ---- the key for this run ------------------------------------------------------------------

    public function testARuntimeKeyGoesToTheModelsOwnProvider(): void
    {
        $settings = Settings::load($this->cwd, $this->home);
        $auth = Auth::inMemory($settings);

        $this->start([], ['model' => 'claude-sonnet-4-5', 'apiKey' => 'sk-typed'], $auth);

        $this->assertSame('sk-typed', Async::run(static fn (): ?string => $auth->apiKey('anthropic')));
    }

    public function testAnEmptyRuntimeKeyIsRefusedRatherThanStored(): void
    {
        // It would beat the environment and then fail as a missing key, which is the least
        // informative way this could go wrong.
        $error = $this->assertThrows(CodingAgentError::class, fn () => $this->start([], ['apiKey' => '']));

        $this->assertStringContainsString('--api-key needs a key', $error->getMessage());
    }

    // ---- which session file --------------------------------------------------------------------

    public function testANewSessionGetsAFileOnceThereIsAConversationInIt(): void
    {
        $started = $this->start();

        $this->assertNotNull($started->store);
        $this->assertFalse($started->resumed);

        // Nothing on disk yet, deliberately: everything before the first answer is held in memory
        // so somebody who opened pig and changed their mind leaves no file behind.
        $this->assertFileDoesNotExist((string) $started->store?->path);

        self::converse($started, 'hello');

        $this->assertFileExists((string) $started->store?->path);
    }

    public function testANewSessionRecordsTheModelAndTheLevelItStartedOn(): void
    {
        // Upstream's two lines, and they were missing: the model survived by luck because
        // `SessionManager::settings()` falls back to the last assistant message, but the thinking
        // level has no such fallback — so `--thinking high` came back as `off` after `--continue`.
        $started = $this->start([], ['model' => 'zzp-alpha:high']);
        self::converse($started, 'hello');

        $recorded = $started->store?->settings() ?? [];

        $this->assertSame('zzp-alpha', $recorded['model']?->modelId);
        $this->assertSame('high', $recorded['thinking']?->level);
    }

    public function testAResumedSessionComesBackOnTheLevelItWasHadOn(): void
    {
        $first = $this->start([], ['model' => 'zzp-alpha:high']);
        self::converse($first, 'earlier');

        // A key, because coming back to the model a conversation was on now needs one: without it
        // the file would be describing a model whose every turn fails, and `restoreSettings()`
        // falls back instead. The test below is that fallback.
        $again = $this->start([], ['continue' => true, 'model' => 'anthropic/zzp-alpha', 'apiKey' => 'not-called-here']);

        $this->assertSame('zzp-alpha', $again->session->agent->state->model?->id);
        $this->assertSame(ThinkingLevel::High, $again->session->agent->state->thinkingLevel);
    }

    public function testAConversationWhoseModelHasNoKeyComesBackOnOneThatWorks(): void
    {
        // A key for the run that had the conversation, and none for the run that reopens it: an
        // afternoon on somebody's borrowed `--api-key`, picked up the next morning.
        $first = $this->start([], ['model' => 'anthropic/zzp-alpha', 'apiKey' => 'not-called-here']);
        self::converse($first, 'earlier');

        $again = $this->start([], ['continue' => true]);

        // Upstream's `restoreModelFromSession()` checks the key as well as the model and falls
        // back on either. Restoring it would mean a conversation that reopens onto a model whose
        // every turn fails — from inside the turn, where it looks like the provider's fault.
        $this->assertSame(CodingAgent::DEFAULT_MODEL, $again->session->agent->state->model?->id);
        $this->assertSame('anthropic', $again->session->agent->state->model?->provider);
        $this->assertCount(2, $again->session->messages(), 'and the conversation itself still came back');
    }

    /** Enough of a conversation to be worth keeping: a question and an answer. */
    private static function converse(StartedSession $started, string $said): void
    {
        $started->store?->append(new UserMessage([new TextContent($said)]));
        $started->store?->append(new AssistantMessage(
            [new TextContent('noted')],
            Api::AnthropicMessages,
            'anthropic',
            $started->model->id,
            new Usage(),
            StopReason::Stop,
        ));
    }

    public function testNoSaveMeansNoFileAtAll(): void
    {
        $started = $this->start([], ['save' => false]);

        $this->assertNull($started->store);
        $this->assertSame([], glob($this->home . '/sessions/*/*.jsonl') ?: []);
    }

    public function testContinueWithNothingToContinueRefuses(): void
    {
        $error = $this->assertThrows(CodingAgentError::class, fn () => $this->start([], ['continue' => true]));

        $this->assertStringContainsString('No earlier session', $error->getMessage());
        $this->assertStringContainsString($this->cwd, $error->getMessage(), 'and where it looked');
    }

    public function testContinuePicksUpTheLatestAndItsMessages(): void
    {
        $first = $this->start();
        self::converse($first, 'the first thing said');

        $again = $this->start([], ['continue' => true]);

        $this->assertTrue($again->resumed);
        $this->assertSame($first->store?->path, $again->store?->path);

        $texts = array_map(
            static fn (object $m): string => is_array($m->content) ? (string) ($m->content[0]->text ?? '') : '',
            $again->session->messages(),
        );

        $this->assertContains('the first thing said', $texts, 'the conversation came back');
    }

    public function testAResumedPathIsOpenedRatherThanCreated(): void
    {
        $first = $this->start();
        self::converse($first, 'earlier');
        $path = (string) $first->store?->path;

        $again = $this->start([], ['resume' => $path]);

        $this->assertTrue($again->resumed);
        $this->assertSame($path, $again->store?->path);
    }

    public function testAResumeIdIsResolvedToTheSessionFile(): void
    {
        $first = $this->start();
        self::converse($first, 'earlier');
        $id = (string) $first->store?->id;

        $again = $this->start([], ['resume' => $id]);

        $this->assertTrue($again->resumed);
        $this->assertSame($first->store?->path, $again->store?->path);
    }

    public function testASessionFileThatWillNotOpenSaysWhy(): void
    {
        $error = $this->assertThrows(
            CodingAgentError::class,
            fn () => $this->start([], ['resume' => $this->root . '/not/a/file.jsonl']),
        );

        // The sentence the session manager came with, not a stack trace: the why is the useful half.
        $this->assertStringContainsString('not/a/file.jsonl', $error->getMessage());
    }

    public function testNoSaveIgnoresAResumePathRatherThanFailingOnIt(): void
    {
        // `--no-save` means this run writes nothing of the person's down, which includes not
        // appending to a file they asked to pick up.
        $started = $this->start([], ['save' => false, 'resume' => $this->root . '/not/a/file.jsonl']);

        $this->assertNull($started->store);
        $this->assertFalse($started->resumed);
    }

    // ---- what got loaded -----------------------------------------------------------------------

    public function testAProjectsOwnInstructionsAndSkillsAndCommandsComeBack(): void
    {
        file_put_contents($this->cwd . '/AGENTS.md', 'Use tabs, obviously.');
        mkdir($this->cwd . '/.pig/skills/greet', 0o755, true);
        file_put_contents(
            $this->cwd . '/.pig/skills/greet/SKILL.md',
            "---\nname: greet\ndescription: Say hello to somebody\n---\n\nSay hello.\n",
        );
        mkdir($this->cwd . '/.pig/commands', 0o755, true);
        file_put_contents($this->cwd . '/.pig/commands/ship.md', "Ship it.\n");

        $started = $this->start();

        $this->assertSame(['Use tabs, obviously.'], array_map(
            static fn (object $f): string => trim($f->content),
            $started->contextFiles,
        ));
        $this->assertSame(['greet'], array_map(static fn (object $s): string => $s->name, $started->skills));
        $this->assertSame(['ship'], array_map(static fn (object $c): string => $c->name, $started->fileCommands));
    }

    public function testASkillThatIsMalformedIsAWarningAndTheSessionStillStarts(): void
    {
        mkdir($this->cwd . '/.pig/skills/broken', 0o755, true);
        file_put_contents($this->cwd . '/.pig/skills/broken/SKILL.md', "no frontmatter here\n");

        $started = $this->start();

        $this->assertSame([], $started->skills);
        $this->assertNotSame([], $started->warnings);
        $this->assertStringStartsWith('skill ', $started->warnings[0], 'and it says which kind of thing broke');
    }

    public function testSkillsTurnedOffInTheSettingsLoadsNone(): void
    {
        mkdir($this->cwd . '/.pig/skills/greet', 0o755, true);
        file_put_contents(
            $this->cwd . '/.pig/skills/greet/SKILL.md',
            "---\nname: greet\ndescription: Say hello to somebody\n---\n\nSay hello.\n",
        );
        $this->writeSettings(['skills' => ['enabled' => false]]);

        $this->assertSame([], $this->start()->skills);
    }

    /**
     * One of upstream's five per-root switches, read from a settings file end to end.
     *
     * `Skills::load()` can switch any root off; which ones a settings file can *reach* is decided
     * here, and this is the half that pins it — the loader's own tests prove the mechanism and say
     * nothing about which keys are wired to it. Claude's user root is the case worth spending a
     * test on: a large `~/.claude/skills` in front of the model is the reason somebody wants this.
     */
    public function testAClaudeRootTurnedOffInTheSettingsIsNotRead(): void
    {
        mkdir($this->root . '/user/.claude/skills/theirs', 0o755, true);
        file_put_contents(
            $this->root . '/user/.claude/skills/theirs/SKILL.md',
            "---\nname: theirs\ndescription: A skill another tool owns\n---\n\nDo it.\n",
        );
        mkdir($this->cwd . '/.pig/skills/ours', 0o755, true);
        file_put_contents(
            $this->cwd . '/.pig/skills/ours/SKILL.md',
            "---\nname: ours\ndescription: A skill of our own\n---\n\nDo it.\n",
        );

        $names = static fn (object $started): array
            => array_map(static fn (object $s): string => $s->name, $started->skills);

        $both = $names($this->start());
        sort($both);
        $this->assertSame(['ours', 'theirs'], $both, 'both are there to begin with');

        $this->writeSettings(['skills' => ['enableClaudeUser' => false]]);

        // Theirs is gone and pig's own is untouched, which is the whole point of a per-root switch
        // rather than `skills.enabled`.
        $this->assertSame(['ours'], $names($this->start()));
    }

    /** And pig's own root has no such key, so nothing in a settings file can turn it off. */
    public function testThereIsNoSettingThatTurnsOffPigsOwnSkills(): void
    {
        mkdir($this->home . '/skills/ours', 0o755, true);
        file_put_contents(
            $this->home . '/skills/ours/SKILL.md',
            "---\nname: ours\ndescription: A skill of our own\n---\n\nDo it.\n",
        );

        // Every one of upstream's five off at once. pig's own root is not one of them — upstream's
        // `enablePiUser` names `~/.pi/agent/skills`, which is a different directory.
        $this->writeSettings(['skills' => [
            'enableCodexUser' => false,
            'enableClaudeUser' => false,
            'enableClaudeProject' => false,
            'enablePiUser' => false,
            'enablePiProject' => false,
        ]]);

        $this->assertSame(
            ['ours'],
            array_map(static fn (object $s): string => $s->name, $this->start()->skills),
        );
    }

    public function testAHookThatDoesNotLoadIsAWarningAndNotAFailedStartup(): void
    {
        mkdir($this->home . '/hooks', 0o755, true);
        file_put_contents($this->home . '/hooks/broken.php', "<?php\n\nreturn 'not a callable';\n");

        $started = $this->start();

        $this->assertNotSame([], $started->warnings);
        $this->assertStringStartsWith('hook ', $started->warnings[0]);
    }

    public function testNoHooksSkipsTheFolderEntirely(): void
    {
        mkdir($this->home . '/hooks', 0o755, true);
        file_put_contents($this->home . '/hooks/broken.php', "<?php\n\nreturn 'not a callable';\n");

        $this->assertSame([], $this->start([], ['withHooks' => false])->warnings);
    }

    public function testACustomToolThatDoesNotLoadIsAWarningToo(): void
    {
        // `~/.pig/agent/tools/<name>/index.php` — a folder, which is the layout the loader looks for.
        mkdir($this->home . '/tools/broken', 0o755, true);
        file_put_contents($this->home . '/tools/broken/index.php', "<?php\n\nreturn 'not a callable';\n");

        $started = $this->start();

        $this->assertNotSame([], $started->warnings);
        $this->assertStringStartsWith('tool ', $started->warnings[0]);
    }

    public function testNoToolsSkipsThatFolderEntirely(): void
    {
        // `~/.pig/agent/tools/<name>/index.php` — a folder, which is the layout the loader looks for.
        mkdir($this->home . '/tools/broken', 0o755, true);
        file_put_contents($this->home . '/tools/broken/index.php', "<?php\n\nreturn 'not a callable';\n");

        $this->assertSame([], $this->start([], ['withTools' => false])->warnings);
    }

    public function testNoSkillsLoadsNoneOfThem(): void
    {
        mkdir($this->home . '/skills/review', 0o755, true);
        file_put_contents(
            $this->home . '/skills/review/SKILL.md',
            "---\nname: review\ndescription: Review a file closely\n---\n\nRead it.\n",
        );

        $this->assertNotSame([], $this->start()->skills, 'the skill is there to begin with');

        // Its two siblings have had `--no-hooks` and `--no-tools` from the start, and skills had a
        // settings switch and no flag — which reads as "this one cannot be turned off for one run".
        $this->assertSame([], $this->start([], ['withSkills' => false])->skills);
    }

    // ---- the agent it built --------------------------------------------------------------------

    public function testTheAgentIsSetUpWithTheModelTheLevelAndThePrompt(): void
    {
        file_put_contents($this->cwd . '/AGENTS.md', 'Use tabs, obviously.');

        $started = $this->start([], ['model' => 'zzp-alpha:high']);
        $agent = $started->session->agent;

        $this->assertSame('zzp-alpha', $agent->state->model?->id);
        $this->assertSame(ThinkingLevel::High, $agent->state->thinkingLevel);
        // The working directory in the prompt as well as in the tools, which is the whole of what
        // `create()` adds — and a project's own instructions inside it.
        // The session's prompt (upstream's `session.systemPrompt`): the agent's transcript is empty
        // until the first prompt writes it in as a system message.
        $this->assertStringContainsString($this->cwd, $started->session->systemPrompt());
        $this->assertStringContainsString('Use tabs, obviously.', $started->session->systemPrompt());
    }

    public function testReadOnlyLeavesOutTheToolsThatWrite(): void
    {
        $names = array_map(
            static fn (object $t): string => $t->definition()->name,
            $this->start([], ['readOnly' => true])->session->agent->state->tools,
        );

        foreach (ToolSet::READ_ONLY as $expected) {
            $this->assertContains($expected, $names, $expected);
        }

        $this->assertNotContains('edit', $names);
        $this->assertNotContains('write', $names);
        $this->assertNotContains('bash', $names);
    }

    public function testTheDefaultIsUpstreamsFourAndNotEveryToolPigHas(): void
    {
        $names = $this->toolNames($this->start());

        // `grep`, `find` and `ls` are ported and reachable by name; they are off by default
        // because upstream's default is four and because a model with seven tools spends more of
        // every turn choosing between them. See the rule at the top of CLAUDE.md.
        $this->assertSame(ToolSet::CODING, $names);
    }

    public function testToolsNamesTheSetOutright(): void
    {
        $names = $this->toolNames($this->start([], ['tools' => ['read', 'grep', 'find', 'ls']]));

        $this->assertSame(['read', 'grep', 'find', 'ls'], $names);
    }

    public function testReadOnlyStillSwapsTheWholeSetRatherThanNarrowingIt(): void
    {
        $this->assertSame(ToolSet::READ_ONLY, $this->toolNames($this->start([], ['readOnly' => true])));
    }

    /** @return list<string> */
    private function toolNames(StartedSession $started): array
    {
        return array_map(
            static fn (object $t): string => $t->definition()->name,
            $started->session->agent->state->tools,
        );
    }

    public function testNothingIsWrittenToAStream(): void
    {
        // The property the whole extraction rests on: a startup that printed could not be tested,
        // and a startup that is tested must not print.
        mkdir($this->home . '/hooks', 0o755, true);
        file_put_contents($this->home . '/hooks/broken.php', "<?php\n\nreturn 'not a callable';\n");
        mkdir($this->cwd . '/.pig/skills/broken', 0o755, true);
        file_put_contents($this->cwd . '/.pig/skills/broken/SKILL.md', "no frontmatter here\n");

        ob_start();
        $started = $this->start();
        $printed = (string) ob_get_clean();

        $this->assertSame('', $printed);
        $this->assertCount(2, $started->warnings, 'both of them came back as values instead');
    }

    public function testAModelDeclaredInModelsJsonCanBeAskedForByName(): void
    {
        // The reason `Auth` and the custom models arrive built rather than being discovered here:
        // `--models` has to be able to print one of these without a session file being created.
        Models::register([new Model(
            'local/zzz-coder',
            'Zzz Coder',
            Api::OpenAiCompletions,
            'local',
            'http://127.0.0.1:8080/v1',
            32_000,
            4_096,
        )]);

        try {
            $this->assertSame('local/zzz-coder', $this->start([], ['model' => 'zzz-coder'])->model->id);
        } finally {
            Models::forgetRegistered();
        }
    }

    public function testAnExtensionCanRegisterBothCommandsAndToolsIntoTheSession(): void
    {
        $extDir = $this->cwd . '/.pig/extensions/bundled';
        mkdir($extDir, 0755, true);
        file_put_contents($extDir . '/index.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
return function (ExtensionApi $pi): void {
    $pi->registerCommand('bundled_cmd', fn () => null, 'Bundled Command');
    $pi->registerTool(new CustomTool(
        name: 'bundled_tool',
        label: 'Bundled Tool',
        description: 'Tool from extension',
        parameters: ['type' => 'object', 'properties' => []],
        execute: fn () => new AgentToolResult([new TextContent('ok')]),
    ));
};
PHP);

        $started = $this->start();

        $this->assertContains('bundled_tool', $started->customTools->names());
        [$commands] = $started->hooks->commands();
        $this->assertArrayHasKey('bundled_cmd', $commands);

        // Disabling withExtensions
        $disabled = $this->start([], ['withExtensions' => false]);
        $this->assertNotContains('bundled_tool', $disabled->customTools->names());
        [$disabledCommands] = $disabled->hooks->commands();
        $this->assertArrayNotHasKey('bundled_cmd', $disabledCommands);
    }

    // ---- `--tools` with patterns, `--exclude-tools`, `--no-mcp` ---------------------------------

    /** An extension with two tools under one prefix and one MCP-shaped tool, for the pattern cases. */
    private function writeAnExtensionWithThreeTools(): void
    {
        $extDir = $this->cwd . '/.pig/extensions/zoo';
        mkdir($extDir, 0755, true);
        file_put_contents($extDir . '/index.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
return function (ExtensionApi $pi): void {
    foreach (['zoo_feed', 'zoo_clean', 'mcp__gh__issues'] as $name) {
        $pi->registerTool(new CustomTool(
            name: $name, label: $name, description: $name,
            parameters: ['type' => 'object', 'properties' => []],
            execute: fn () => new AgentToolResult([new TextContent('ok')]),
        ));
    }
};
PHP);
    }

    public function testToolsTakesAStarPatternOverBuiltInsAndCustomToolsAlike(): void
    {
        $this->writeAnExtensionWithThreeTools();

        // pi's `--tools read,codemode,'mcp__radius__*'` shape: an exact built-in and a pattern.
        $names = $this->toolNames($this->start([], ['tools' => ['read', 'zoo_*']]));

        // `mcp__gh__issues` survives: an MCP tool is kept unless an entry starts with `mcp__`,
        // because `--tools read` means "that built-in" and not "and no MCP servers either".
        $this->assertSame(['read', 'zoo_feed', 'zoo_clean', 'mcp__gh__issues'], $names);
    }

    public function testAnMcpEntryInToolsIsWhatNarrowsTheMcpTools(): void
    {
        $this->writeAnExtensionWithThreeTools();

        $names = $this->toolNames($this->start([], ['tools' => ['read', 'mcp__other__*']]));

        // Now an entry asked about MCP tools, so the ones it does not match go.
        $this->assertSame(['read'], $names);
    }

    public function testExcludeToolsTakesAwayAfterToolsAndReachesMcpToolsToo(): void
    {
        $this->writeAnExtensionWithThreeTools();

        $names = $this->toolNames($this->start([], ['excludeTools' => ['bash', 'zoo_clean', 'mcp__*']]));

        // The default four less `bash`, the extension's tools less the two named — a denylist
        // means what it names, MCP exception or not.
        $this->assertSame(['read', 'edit', 'write', 'zoo_feed'], $names);
    }

    public function testAToolsEntryThatNamesNothingIsRefusedByName(): void
    {
        $this->writeAnExtensionWithThreeTools();

        $problem = $this->assertThrows(
            CodingAgentError::class,
            fn (): StartedSession => $this->start([], ['tools' => ['read', 'raed']]),
        );

        // Said with the list, which now has the custom tools in it too: that is why the check
        // moved out of `bin/pig`, which cannot know them.
        $this->assertStringContainsString("No tool called 'raed'", $problem->getMessage());
        $this->assertStringContainsString('zoo_feed', $problem->getMessage());
    }

    public function testAPatternThatMatchesNothingIsATypoToo(): void
    {
        $problem = $this->assertThrows(
            CodingAgentError::class,
            fn (): StartedSession => $this->start([], ['tools' => ['nope_*']]),
        );

        $this->assertStringContainsString("No tool called 'nope_*'", $problem->getMessage());
    }

    public function testADisabledExtensionIsNotLoadedAtAll(): void
    {
        $this->writeAnExtensionWithThreeTools();

        // `--no-mcp` is this with `pig-mcp`; the mechanism is by name, so the fixture's name does.
        $started = $this->start([], ['disabledExtensions' => ['zoo']]);

        $this->assertNotContains('zoo_feed', $started->customTools->names());
        $this->assertSame([], array_filter($started->extensions, static fn ($ext): bool => $ext->name === 'zoo'));
    }
}
