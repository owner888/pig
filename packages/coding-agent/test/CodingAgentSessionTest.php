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
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('PI_HOME');
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

    /** @param array<string, mixed> $settings */
    private function writeSettings(array $settings): void
    {
        file_put_contents($this->home . '/settings.json', (string) json_encode($settings));
    }

    /**
     * @param array<string, string|false> $environment
     * @param array<string, mixed>        $named
     */
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
        $this->writeSettings(['defaultModel' => 'claude-opus-4-1', 'defaultProvider' => 'anthropic']);

        $started = $this->start(['PIG_MODEL' => 'haiku'], ['model' => 'claude-sonnet-4-5']);

        $this->assertSame('claude-sonnet-4-5', $started->model->id);
    }

    public function testTheEnvironmentComesNext(): void
    {
        $this->writeSettings(['defaultModel' => 'claude-opus-4-1', 'defaultProvider' => 'anthropic']);

        $started = $this->start(['PIG_MODEL' => 'claude-sonnet-4-5']);

        $this->assertSame('claude-sonnet-4-5', $started->model->id);
    }

    public function testThenWhatWasChosenLastTime(): void
    {
        $this->writeSettings(['defaultModel' => 'claude-opus-4-1', 'defaultProvider' => 'anthropic']);

        $this->assertSame('claude-opus-4-1', $this->start()->model->id);
    }

    public function testAndFinallyTheBuiltInDefault(): void
    {
        $this->assertSame('claude-sonnet-4-5', $this->start()->model->id);
    }

    public function testAnEmptyEnvironmentVariableIsOffRatherThanAModelCalledNothing(): void
    {
        // `PIG_MODEL= pig …` is how somebody turns it off for one command.
        $this->writeSettings(['defaultModel' => 'claude-opus-4-1', 'defaultProvider' => 'anthropic']);

        $this->assertSame('claude-opus-4-1', $this->start(['PIG_MODEL' => ''])->model->id);
        $this->assertSame('claude-opus-4-1', $this->start(['PIG_MODEL' => false])->model->id);
    }

    public function testAPartOfTheNameIsEnough(): void
    {
        $this->assertSame('claude-opus-4-1', $this->start([], ['model' => 'opus 4.1'])->model->id);
    }

    public function testAModelThatMatchesNothingRefusesToStart(): void
    {
        $error = $this->assertThrows(
            CodingAgentError::class,
            fn () => $this->start([], ['model' => 'gpt-9-ultra']),
        );

        $this->assertStringContainsString("No model matches 'gpt-9-ultra'", $error->getMessage());
        $this->assertStringContainsString('--models', $error->getMessage(), 'and where to look');
    }

    public function testTheResolversOwnWarningIsAWarningAndNotARefusal(): void
    {
        // `sonnet:veryhard` names a model and then a thinking level that is not one. The model was
        // understood, so the run goes on — with a sentence saying what was dropped.
        $started = $this->start([], ['model' => 'sonnet:veryhard']);

        $this->assertSame('claude-sonnet-4-5', $started->model->id);
        $this->assertSame(ThinkingLevel::Off, $started->thinking);
        $this->assertStringContainsString('veryhard', $started->warnings[0]);
    }

    // ---- how hard to think ---------------------------------------------------------------------

    public function testThinkingFromTheFlagBeatsTheSuffixOnTheModel(): void
    {
        $started = $this->start([], ['model' => 'sonnet:low', 'thinking' => 'high']);

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

    public function testAThinkingLevelThatIsNotOneIsOffRatherThanFatal(): void
    {
        $this->assertSame(ThinkingLevel::Off, $this->start([], ['thinking' => 'very hard'])->thinking);
    }

    public function testAModelThatCannotReasonIsClampedRatherThanRefused(): void
    {
        // A request that asks a non-reasoning model to think is one the provider rejects, and
        // nobody typing `--thinking high` meant to be told no.
        $started = $this->start([], ['model' => 'claude-3-5-haiku-latest', 'thinking' => 'high']);

        $this->assertFalse($started->model->reasoning);
        $this->assertSame(ThinkingLevel::Off, $started->thinking);
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
        $started = $this->start([], ['model' => 'opus 4.1:high']);
        self::converse($started, 'hello');

        $recorded = $started->store?->settings() ?? [];

        $this->assertSame('claude-opus-4-1', $recorded['model']?->modelId);
        $this->assertSame('high', $recorded['thinking']?->level);
    }

    public function testAResumedSessionComesBackOnTheLevelItWasHadOn(): void
    {
        $first = $this->start([], ['model' => 'opus 4.1:high']);
        self::converse($first, 'earlier');

        $again = $this->start([], ['continue' => true]);

        $this->assertSame('claude-opus-4-1', $again->session->agent->state->model?->id);
        $this->assertSame(ThinkingLevel::High, $again->session->agent->state->thinkingLevel);
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
        // `~/.pig/tools/<name>/index.php` — a folder, which is the layout the loader looks for.
        mkdir($this->home . '/tools/broken', 0o755, true);
        file_put_contents($this->home . '/tools/broken/index.php', "<?php\n\nreturn 'not a callable';\n");

        $started = $this->start();

        $this->assertNotSame([], $started->warnings);
        $this->assertStringStartsWith('tool ', $started->warnings[0]);
    }

    public function testNoToolsSkipsThatFolderEntirely(): void
    {
        // `~/.pig/tools/<name>/index.php` — a folder, which is the layout the loader looks for.
        mkdir($this->home . '/tools/broken', 0o755, true);
        file_put_contents($this->home . '/tools/broken/index.php', "<?php\n\nreturn 'not a callable';\n");

        $this->assertSame([], $this->start([], ['withTools' => false])->warnings);
    }

    // ---- the agent it built --------------------------------------------------------------------

    public function testTheAgentIsSetUpWithTheModelTheLevelAndThePrompt(): void
    {
        file_put_contents($this->cwd . '/AGENTS.md', 'Use tabs, obviously.');

        $started = $this->start([], ['model' => 'sonnet:high']);
        $agent = $started->session->agent;

        $this->assertSame('claude-sonnet-4-5', $agent->state->model?->id);
        $this->assertSame(ThinkingLevel::High, $agent->state->thinkingLevel);
        // The working directory in the prompt as well as in the tools, which is the whole of what
        // `create()` adds — and a project's own instructions inside it.
        $this->assertStringContainsString($this->cwd, (string) $agent->state->systemPrompt);
        $this->assertStringContainsString('Use tabs, obviously.', (string) $agent->state->systemPrompt);
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

    public function testTheDefaultIsEveryTool(): void
    {
        $names = array_map(
            static fn (object $t): string => $t->definition()->name,
            $this->start()->session->agent->state->tools,
        );

        foreach (ToolSet::ALL as $expected) {
            $this->assertContains($expected, $names, $expected);
        }
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
}
