<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
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
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\Events\ContextEvent;
use Pig\CodingAgent\Hooks\Events\ContextWithSystemEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
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
        // The tool list and the rules that mention bash changed; the role did not. (`cwd` carries the
        // time to the second, so it changes too when the clock has moved on since the last build.)
        $this->assertSame(['tools', 'rules'], array_values(array_diff(array_keys($update->sections ?? []), ['cwd'])));
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
    private function session(array $answers, array $settings = [], ?HookRunner $hooks = null): array
    {
        $this->answers = $answers;
        // As `CodingAgent::create()` wires the hooks: the `context` event is the agent's transform.
        $agent = new Agent(new AgentOptions(
            transformContext: $hooks === null ? null : static fn (array $messages): array => $hooks->emitContext($messages),
            streamFn: $this->provider(...),
            apiKey: 'test-key',
        ));
        $agent->setModel(new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));
        $loadout = new ToolLoadout($agent, $this->cwd, ['read', 'bash'], new CustomToolSet(), $hooks ?? new HookRunner(), [], []);
        $loadout->apply();
        $store = SessionManager::create($this->cwd);

        return [new AgentSession($agent, $this->cwd, $store, Settings::inMemory($settings), $hooks, loadout: $loadout), $store];
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
