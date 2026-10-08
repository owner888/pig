<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolReference;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Transcript;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Hooks\Events\BeforeAgentStartEvent;
use Pig\CodingAgent\Hooks\Events\ContextEvent;
use Pig\CodingAgent\Hooks\Events\ContextWithSystemEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Hooks\Results\ContextEventResult;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\SummarizationRetryEvent;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\ToolLoadout;
use RuntimeException;

/**
 * The prompt and the tools as the session's transcript: upstream's agent session writes them in as
 * system messages — the whole prompt as sections in front of the first prompt, then only what
 * changed — and the session file, compaction and the `context` hooks all keep them.
 */
final class SystemMessageTranscriptTest extends TestCase
{
    private string $tempHome = '';

    private string $cwd = '';

    /** @var list<TranscriptContext> every request the provider was handed */
    private array $requests = [];

    /** @var list<string|array{error: string}> what the provider answers, in order */
    private array $answers = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->tempHome = sys_get_temp_dir() . '/pig-transcript-home-' . bin2hex(random_bytes(4));
        $this->cwd = sys_get_temp_dir() . '/pig-transcript-cwd-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o777, true);
        putenv("PIG_HOME={$this->tempHome}");
        $this->requests = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        self::removeDir($this->tempHome);
        self::removeDir($this->cwd);
    }

    public function testTheFirstPromptCarriesTheWholePromptAndTheToolsAsTheFirstEntry(): void
    {
        [$session, $store] = $this->session(['the answer']);

        Async::run(static fn () => $session->prompt('hi'));

        $messages = $session->messages();
        $this->assertInstanceOf(SystemMessage::class, $messages[0]);
        $this->assertSame('', $messages[0]->content);
        $this->assertSame(['preamble', 'tools', 'rules', 'cwd'], array_keys($messages[0]->sections ?? []));
        $this->assertSame(['read', 'bash'], array_map(static fn (Tool $t): string => $t->name, $messages[0]->toolsAdded ?? []));
        $this->assertInstanceOf(UserMessage::class, $messages[1]);

        // What the provider was handed renders to exactly the prompt pig has always built.
        $this->assertSame($session->systemPrompt(), Transcript::getCurrentSystemPrompt($this->requests[0]->messages));
        $this->assertStringStartsWith('You are an expert coding assistant.', $session->systemPrompt());

        // And the file has it first: a resumed session replays the same prompt and tools.
        $saved = SessionManager::open($store->path)->messages();
        $this->assertInstanceOf(SystemMessage::class, $saved[0]);
        $this->assertSame($messages[0]->sections, $saved[0]->sections);
        $this->assertSame(['read', 'bash'], array_map(static fn (Tool $t): string => $t->name, $saved[0]->toolsAdded ?? []));
    }

    public function testAnUnchangedPromptAddsNothingAndAChangedToolSetSendsOnlyWhatChanged(): void
    {
        [$session] = $this->session(['one', 'two', 'three']);

        Async::run(static fn () => $session->prompt('first'));
        Async::run(static fn () => $session->prompt('second'));

        $this->assertCount(1, array_filter($session->messages(), static fn ($m): bool => $m instanceof SystemMessage), 'nothing changed, nothing said');

        $session->setActiveTools(['read']);
        Async::run(static fn () => $session->prompt('third'));

        $system = array_values(array_filter($session->messages(), static fn ($m): bool => $m instanceof SystemMessage));
        $this->assertCount(2, $system);
        $update = $system[1];
        // The tool list and the rules that mention bash changed; the role and the directory did not.
        $this->assertSame(['tools', 'rules'], array_keys($update->sections ?? []));
        $this->assertSame(['bash'], array_map(static fn (ToolReference $t): string => $t->name, $update->toolsRemoved ?? []));
        $this->assertNull($update->toolsAdded);
        $this->assertSame(['read'], array_map(static fn (Tool $t): string => $t->name, Transcript::getCurrentTools($this->requests[2]->messages)));
        $this->assertStringNotContainsString('- bash:', Transcript::getCurrentSystemPrompt($this->requests[2]->messages));
    }

    public function testCompactionCarriesThePromptStateInFrontOfTheSummary(): void
    {
        [$session, $store] = $this->session(['one', 'the summary']);

        Async::run(static fn () => $session->prompt('first'));

        foreach (range(1, 4) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $summary = Async::run(static fn () => $session->compact());

        $this->assertInstanceOf(CompactionSummary::class, $summary);
        $this->assertNotNull($summary->systemMessage);
        $this->assertSame($summary->timestamp, $summary->systemMessage->timestamp);

        $messages = $session->messages();
        $this->assertSame($summary->systemMessage, $messages[0]);
        $this->assertSame($summary, $messages[1]);
        $this->assertSame([], array_filter(array_slice($messages, 2), static fn ($m): bool => $m instanceof SystemMessage), 'the kept part keeps no system message of its own');

        // The summariser was never shown the prompt as conversation.
        $this->assertStringNotContainsString('You are an expert coding assistant', (string) json_encode(array_map(static fn ($m) => $m instanceof UserMessage ? $m->content[0]->text : '', $this->requests[1]->messages)));

        // Read back from the file: the same state, in the same place.
        $saved = SessionManager::open($store->path)->messages();
        $this->assertInstanceOf(SystemMessage::class, $saved[0]);
        $this->assertSame($summary->systemMessage->sections, $saved[0]->sections);
        $this->assertInstanceOf(CompactionSummary::class, $saved[1]);
    }

    public function testASummaryRetriesATransientFailureWithTheSessionsRetrySettings(): void
    {
        [$session] = $this->session(['one', ['error' => 'terminated'], 'the summary'], ['retry' => ['enabled' => true, 'maxRetries' => 2, 'baseDelayMs' => 1]]);
        $events = [];
        $session->subscribe(static function (AgentEvent $event) use (&$events): void {
            if ($event instanceof SummarizationRetryEvent) {
                $events[] = $event->phase;
            }
        });

        Async::run(static fn () => $session->prompt('first'));

        foreach (range(1, 4) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $summary = Async::run(static fn () => $session->compact());

        $this->assertSame('the summary', $summary?->summary);
        $this->assertSame([SummarizationRetryEvent::SCHEDULED, SummarizationRetryEvent::ATTEMPT_START, SummarizationRetryEvent::FINISHED], $events);
        // One-off requests: no cache writes, and a routing id of their own.
        $this->assertSame('none', $this->options[1]->cacheRetention);
        $this->assertNotSame($session->agent->sessionId, $this->options[1]->sessionId);
    }

    public function testACompactionAsksAtTheSessionsThinkingLevelOnlyForAModelThatReasons(): void
    {
        // Upstream's `createSummarizationOptions()`: `reasoning` is the thinking level when the
        // model reasons and the level is not `off`, and absent otherwise; the budget is
        // `min(floor(0.8 * reserveTokens), model.maxTokens)`.
        foreach ([[true, ThinkingLevel::Medium, ReasoningEffort::Medium], [true, ThinkingLevel::Off, null], [false, ThinkingLevel::Medium, null]] as [$reasons, $level, $expected]) {
            $this->options = [];
            [$session] = $this->session(['one', 'the summary'], reasoning: $reasons, maxTokens: 64_000);
            $session->agent->state->thinkingLevel = $level;

            Async::run(static fn () => $session->prompt('first'));

            foreach (range(1, 4) as $ignored) {
                $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
            }

            Async::run(static fn () => $session->compact());

            $this->assertSame($expected, $this->options[1]->reasoning, ($reasons ? 'reasoning' : 'non-reasoning') . " model at {$level->value}");
            $this->assertSame((int) floor(0.8 * 16_384), $this->options[1]->maxTokens);
        }

        // A model whose own cap is lower wins.
        $this->options = [];
        [$session] = $this->session(['one', 'the summary'], maxTokens: 1_000);
        Async::run(static fn () => $session->prompt('first'));

        foreach (range(1, 4) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        Async::run(static fn () => $session->compact());
        $this->assertSame(1_000, $this->options[1]->maxTokens);
    }

    public function testABranchSummaryAsksForNoReasoningAndAtMostFourThousandTokens(): void
    {
        // Upstream's `generateBranchSummary()`: `{ apiKey, headers, env, signal, maxTokens }` with
        // `maxTokens = Math.min(4096, model.maxTokens)` — no reasoning, whatever the session's level.
        foreach ([[64_000, 4_096], [1_024, 1_024]] as [$modelMax, $expected]) {
            $this->options = [];
            [$session, $store] = $this->session(['one', 'two', 'the summary'], reasoning: true, maxTokens: $modelMax);
            $session->agent->state->thinkingLevel = ThinkingLevel::High;

            Async::run(static fn () => $session->prompt('first'));
            Async::run(static fn () => $session->prompt('second'));

            $jump = Async::run(static fn () => $session->goTo($store->branch()[1]['id'], summarise: true));

            $this->assertTrue($jump->moved);
            $this->assertNull($this->options[2]->reasoning);
            $this->assertSame($expected, $this->options[2]->maxTokens);
            $this->assertSame('none', $this->options[2]->cacheRetention);
        }
    }

    public function testAForcedPromptIsSentAsTheLeadingPromptForTheRunAndNeverRecorded(): void
    {
        // Upstream's `system-prompt-updates.test.ts`, "a forced prompt is sent as the leading prompt
        // for the run and never recorded".
        $turn = 0;
        $api = new HookApi();
        $api->on('before_agent_start', static function (BeforeAgentStartEvent $event) use (&$turn): ?BeforeAgentStartEventResult {
            if (++$turn === 3) {
                $event->systemPromptOptions->sections['plan_mode'] = 'Plan only.';
            }

            return $turn === 2 || $turn === 3 ? new BeforeAgentStartEventResult(systemPrompt: 'Exact prompt.') : null;
        });
        $hooks = new HookRunner([new LoadedHook('test-hook', 'test-hook', $api)]);
        [$session] = $this->session(['one', 'two', 'three', 'four'], hooks: $hooks);

        foreach (['one', 'two', 'three', 'four'] as $text) {
            Async::run(static fn () => $session->prompt($text));
        }

        $systemMessages = array_map(
            static fn (TranscriptContext $request): array => array_values(array_filter($request->messages, static fn ($m): bool => $m instanceof SystemMessage)),
            $this->requests,
        );
        // "Forced turns collapse to one leading message; the unforced fourth turn passes the
        // recorded head and both plan_mode patches through."
        $this->assertSame([1, 1, 1, 3], array_map('count', $systemMessages));

        $forced = $systemMessages[1][0];
        $this->assertSame('Exact prompt.', $forced->content);
        $this->assertNull($forced->sections);
        $this->assertNull($forced->toolsRemoved);
        $this->assertEquals($systemMessages[0][0]->toolsAdded, $forced->toolsAdded);
        $this->assertSame($systemMessages[0][0]->timestamp, $forced->timestamp);
        $this->assertEquals($forced, $systemMessages[2][0]);
        $this->assertSame('Exact prompt.', Transcript::getCurrentSystemPrompt($this->requests[2]->messages));
        $this->assertSame(
            [SystemMessage::class, UserMessage::class, AssistantMessage::class, UserMessage::class, AssistantMessage::class, UserMessage::class],
            array_map(static fn ($m): string => $m::class, $this->requests[2]->messages),
        );

        // "The transcript only records the structured sections, never the forced text."
        $recorded = array_values(array_map(
            static fn (SystemMessage $m): ?array => $m->sections,
            array_filter($session->messages(), static fn ($m): bool => $m instanceof SystemMessage),
        ));
        $this->assertSame([
            $systemMessages[0][0]->sections,
            ['plan_mode' => "<plan_mode>\nPlan only.\n</plan_mode>"],
            ['plan_mode' => null],
        ], $recorded);
        $this->assertSame($session->systemPrompt(), Transcript::getCurrentSystemPrompt($session->messages()));
    }

    public function testAHandlersSectionStaysForTheRunAndTheEventRendersThePromptWithIt(): void
    {
        $seen = null;
        $api = new HookApi();
        $api->on('before_agent_start', static function (BeforeAgentStartEvent $event) use (&$seen): null {
            $event->systemPromptOptions->sections['notes'] = 'Remember the notes.';
            $seen = $event->systemPrompt();

            return null;
        });
        $hooks = new HookRunner([new LoadedHook('test-hook', 'test-hook', $api)]);
        [$session] = $this->session(['one'], hooks: $hooks);

        Async::run(static fn () => $session->prompt('first'));

        $this->assertStringEndsWith("<notes>\nRemember the notes.\n</notes>", (string) $seen);
        $this->assertSame($seen, Transcript::getCurrentSystemPrompt($this->requests[0]->messages));
    }

    public function testAResumedSessionRestoresTheLoadoutItsTranscriptDeclared(): void
    {
        // Upstream's `_restoreToolsFromTranscript()`, run when a session opens.
        [$session, $store] = $this->session(['one']);
        $session->setActiveTools(['read']);
        Async::run(static fn () => $session->prompt('first'));

        [$resumed] = $this->session([], resume: SessionManager::open($store->path));

        $this->assertSame(['read'], $resumed->activeTools());
    }

    public function testGoingBackRestoresTheLoadoutDeclaredAtThatPoint(): void
    {
        // And after tree navigation: the loadout is the one the branch being joined declared.
        [$session, $store] = $this->session(['one', 'two']);
        Async::run(static fn () => $session->prompt('first'));
        $session->setActiveTools(['read']);
        Async::run(static fn () => $session->prompt('second'));
        $this->assertSame(['read'], $session->activeTools());

        $firstAnswer = null;

        foreach ($store->branch() as $entry) {
            if ($entry['message'] instanceof AssistantMessage) {
                $firstAnswer = $entry['id'];

                break;
            }
        }

        $this->assertNotNull($firstAnswer);
        Async::run(static fn () => $session->goTo($firstAnswer));

        $this->assertSame(['read', 'bash'], $session->activeTools());
    }

    public function testARestoredToolThatRegistersLaterIsActivatedAndTheNextRunDropsTheRest(): void
    {
        // "Tools of the restored or reloaded loadout that are not registered yet, such as tools of
        // MCP servers that are still connecting. They are activated when they are registered, and
        // dropped when `setActiveToolsByName()` deactivates a tool or the next agent run starts."
        $late = static fn (): CustomTool => new CustomTool('late', 'late', 'A tool that registers late.', ['type' => 'object'], static fn () => null);
        [$first, $firstExtension] = self::extensionTools();
        $firstExtension->registerTool($late());
        [$session, $store] = $this->session(['one'], customTools: $first);
        Async::run(static fn () => $session->prompt('first'));
        $this->assertSame(['read', 'bash', 'late'], $session->activeTools());

        // Resumed before the tool is there: it waits, and arrives active.
        [$tools, $extension] = self::extensionTools();
        [$resumed] = $this->session(['two'], customTools: $tools, resume: SessionManager::open($store->path));
        $this->assertSame(['read', 'bash'], $resumed->activeTools());
        $this->assertTrue($resumed->isToolPending('late'));

        $extension->registerTool($late());
        $this->assertSame(['read', 'bash', 'late'], $resumed->activeTools());
        $this->assertFalse($resumed->isToolPending('late'));

        // The next run drops what has not registered by then.
        [$again] = $this->session(['three'], customTools: self::extensionTools()[0], resume: SessionManager::open($store->path));
        $this->assertTrue($again->isToolPending('late'));
        Async::run(static fn () => $again->prompt('go'));
        $this->assertFalse($again->isToolPending('late'));
    }

    public function testALoadoutSetBeforeARestoredToolRegistersDropsItOnlyWhenItDeactivatesSomething(): void
    {
        $late = new CustomTool('late', 'late', 'A tool that registers late.', ['type' => 'object'], static fn () => null);
        [$first, $firstExtension] = self::extensionTools();
        $firstExtension->registerTool($late);
        [$session, $store] = $this->session(['one'], customTools: $first);
        Async::run(static fn () => $session->prompt('first'));

        // "Like plan mode restoring its tools": a loadout that deactivates a tool drops them.
        [$dropped] = $this->session([], customTools: self::extensionTools()[0], resume: SessionManager::open($store->path));
        $dropped->setActiveTools(['read']);
        $this->assertFalse($dropped->isToolPending('late'));

        // "or an extension adding one to the current loadout": one that only adds keeps them.
        [$kept] = $this->session([], customTools: self::extensionTools()[0], resume: SessionManager::open($store->path));
        $kept->setActiveTools([...$kept->activeTools(), 'read']);
        $this->assertTrue($kept->isToolPending('late'));
    }

    /**
     * A custom tool set fed by one extension, so a test can register a tool after the session exists.
     *
     * @return array{0: CustomToolSet, 1: ExtensionApi}
     */
    private static function extensionTools(): array
    {
        $api = new ExtensionApi(sys_get_temp_dir(), 'late.php', 'late');
        $set = new CustomToolSet([]);
        $set->adopt(new LoadedExtension('late.php', 'late.php', 'late', $api));

        return [$set, $api];
    }

    public function testAgentEndSaysWhetherTheSessionWillRetry(): void
    {
        [$session] = $this->session([['error' => '503 Service Unavailable'], 'recovered'], ['retry' => ['enabled' => true, 'maxRetries' => 1, 'baseDelayMs' => 1]]);
        $ends = [];
        $session->subscribe(static function (AgentEvent $event) use (&$ends): void {
            if ($event instanceof AgentEndEvent) {
                $ends[] = $event->willRetry;
            }
        });

        Async::run(static fn () => $session->prompt('hi'));

        $this->assertSame([true, false], $ends);
    }

    public function testAContextHookSeesTheConversationAndThePromptIsPutBack(): void
    {
        $seen = [];
        $withSystem = [];
        $api = new HookApi();
        $api->on('context', static function (ContextEvent $event) use (&$seen): ContextEventResult {
            $seen[] = array_map(static fn ($m): string => $m::class, $event->messages);

            // Keep the last message only: a windowing hook.
            return new ContextEventResult(array_slice($event->messages, -1));
        });
        $api->on('context_with_system', static function (ContextWithSystemEvent $event) use (&$withSystem): ?ContextEventResult {
            $withSystem[] = array_map(static fn ($m): string => $m::class, $event->messages);

            return null;
        });
        $hooks = new HookRunner([new LoadedHook('test-hook', 'test-hook', $api)]);

        [$session] = $this->session(['one', 'two'], hooks: $hooks);

        Async::run(static fn () => $session->prompt('first'));
        Async::run(static fn () => $session->prompt('second'));

        // The handler never saw a system message…
        $this->assertNotContains(SystemMessage::class, array_merge(...$seen));
        // …and the request still led with the prompt, replayed, in front of what it kept.
        $this->assertSame([SystemMessage::class, UserMessage::class], $withSystem[1]);
        $this->assertSame($session->systemPrompt(), Transcript::getCurrentSystemPrompt($this->requests[1]->messages));
    }

    public function testTheSectionsRenderToThePromptBuildHasAlwaysReturned(): void
    {
        $sections = SystemPrompt::sections($this->cwd, ['read', 'bash'], null, 'an addendum', [], []);

        $this->assertSame(['preamble', 'tools', 'rules', 'addendum', 'cwd'], array_keys($sections));
        $this->assertSame(implode("\n\n", $sections), SystemPrompt::build($this->cwd, ['read', 'bash'], null, 'an addendum', [], []));
        $this->assertSame(['tools' => 'b', 'gone' => null], SystemPrompt::diffSections(['tools' => 'a', 'rules' => 'r', 'gone' => 'g'], ['tools' => 'b', 'rules' => 'r']));
        $this->assertNull(SystemPrompt::diffSections(['a' => 'x'], ['a' => 'x']));
    }

    /** @var list<SimpleStreamOptions> */
    private array $options = [];

    /**
     * A session with the coding agent's loadout — `read` and `bash`, the prompt built from them —
     * written to a file, on a scripted provider.
     *
     * @param list<string|array{error: string}> $answers
     * @param array<string, mixed>               $settings
     * @return array{0: AgentSession, 1: SessionManager}
     */
    private function session(
        array $answers,
        array $settings = [],
        ?HookRunner $hooks = null,
        bool $reasoning = false,
        int $maxTokens = 64_000,
        ?CustomToolSet $customTools = null,
        ?SessionManager $resume = null,
    ): array {
        $this->answers = $answers;
        // As `CodingAgent::create()` wires the hooks: the `context` event is the agent's transform.
        $agent = new Agent(new AgentOptions(
            transformContext: $hooks === null ? null : static fn (array $messages): array => $hooks->emitContext($messages),
            streamFn: $this->provider(...),
            apiKey: 'test-key',
        ));
        $agent->setModel(new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, $maxTokens, $reasoning));
        $customTools ??= new CustomToolSet();
        $loadout = new ToolLoadout($agent, $this->cwd, ['read', 'bash'], $customTools, $hooks ?? new HookRunner(), [], []);
        // As `CodingAgent::session()` wires it: a tool that arrives later goes through `refresh()`.
        $customTools->onChange(static fn () => $loadout->refresh());
        $loadout->apply();
        $store = $resume ?? SessionManager::create($this->cwd);
        $session = new AgentSession($agent, $this->cwd, $store, Settings::inMemory($settings), $hooks, loadout: $loadout);

        // And as it resumes one.
        if ($resume !== null) {
            $session->restore($resume->messages());
        }

        return [$session, $store];
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $this->requests[] = $context;
        $this->options[] = $options;
        $answer = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $failed = is_array($answer);
        $message = new AssistantMessage(
            [new TextContent($failed ? '' : $answer)],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(10, 5, 0, 0, 15),
            $failed ? StopReason::Error : StopReason::Stop,
            $failed ? $answer['error'] : null,
        );
        $stream = new AssistantMessageEventStream();

        Async::spawn(static function () use ($stream, $message, $failed): void {
            $stream->push(new StartEvent($message));
            $stream->push($failed ? new ErrorEvent(StopReason::Error, $message) : new DoneEvent(StopReason::Stop, $message));
            $stream->end();
        });

        return $stream;
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }

            rmdir($path);
        } elseif (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}
