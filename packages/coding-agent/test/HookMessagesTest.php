<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Themes;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\TranscriptContext;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Interactive\HookMessageComponent;
use Pig\Tui\Ansi;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\Compaction;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionEntries;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * What a hook can put in the conversation, and what it can write down beside it.
 *
 * Two things that look alike and are opposites: `sendMessage()` reaches the model and costs
 * context; `appendEntry()` reaches the next run of the same hook and costs none. Most of
 * these tests exist to hold that line.
 */
final class HookMessagesTest extends TestCase
{
    use GlobalThemeFixture;
    use AssertsThrows;

    private string $cwd;

    private AgentSession $session;

    /** @var list<string> */
    private array $answers = [];

    /** @var list<list<mixed>> What each request sent, in order. */
    private array $requests = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-hookmsg-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_CODING_AGENT_DIR=' . $this->cwd . '-home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        putenv('PIG_CODING_AGENT_DIR');
        self::remove($this->cwd);
        self::remove($this->cwd . '-home');
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

    // ---- the message itself ---------------------------------------------------------

    public function testTheModelSeesItAsAUserMessage(): void
    {
        $message = new HookMessage('build', [new TextContent('the build is broken')]);
        $llm = CodingAgent::toLlm([$message]);

        $this->assertCount(1, $llm);
        $this->assertInstanceOf(UserMessage::class, $llm[0]);
        $this->assertSame('the build is broken', $llm[0]->content[0]->text);
        $this->assertSame($message->timestamp, $llm[0]->timestamp);
    }

    public function testImagesSurviveTheTripToTheModel(): void
    {
        $image = new ImageContent(base64_encode('bytes'), 'image/png');
        $message = new HookMessage('screenshot', [new TextContent('this is what it looks like'), $image]);

        // Whole rather than through `toText()`, which is why this one is not folded into
        // the other app messages: it is the only one whose content can be a picture.
        $llm = CodingAgent::toLlm([$message]);
        $this->assertCount(2, $llm[0]->content);
        $this->assertInstanceOf(ImageContent::class, $llm[0]->content[1]);
    }

    public function testAnEmptyMessageIsDroppedRatherThanSent(): void
    {
        // A user message with no content is a request every provider rejects, and a hook
        // that meant to say nothing has said nothing.
        $this->assertSame([], CodingAgent::toLlm([new HookMessage('quiet', [])]));
    }

    public function testDetailsNeverReachTheModel(): void
    {
        $message = new HookMessage(
            'permissions',
            [new TextContent('granted')],
            details: ['level' => 'full', 'secret' => 'hunter2'],
        );

        $llm = CodingAgent::toLlm([$message]);
        $this->assertStringNotContainsString('hunter2', json_encode($llm));
    }

    public function testItRoundTripsThroughTheSessionFilesEntryCodec(): void
    {
        $original = new HookMessage(
            'build',
            [new TextContent('broken'), new ImageContent(base64_encode('x'), 'image/png')],
            display: false,
            details: ['exitCode' => 2],
        );

        // `SessionEntries`, not `SessionCodec`: a hook's message is a line of its own in
        // the file — pi's `custom_message` — and not a message wrapped in one.
        $line = SessionEntries::encode($original, 'a1b2c3d4', null);
        self::assertNotNull($line);
        $this->assertSame('custom_message', $line['type']);

        $back = SessionEntries::decode($line);

        $this->assertInstanceOf(HookMessage::class, $back);
        $this->assertSame('build', $back->customType);
        $this->assertFalse($back->display);
        $this->assertSame(['exitCode' => 2], $back->details);
        $this->assertCount(2, $back->content);
        $this->assertSame($original->timestamp, $back->timestamp);
    }

    public function testACompactionReadsAHooksMessageAsTheModelWasShownIt(): void
    {
        // Upstream's `serializeConversation(convertToLlm(...))`: a hook's message reaches the model
        // as a user message, so that is how the summariser reads it.
        $serialised = Compaction::serializeConversation(CodingAgent::toLlm([new HookMessage('build', [new TextContent('broken')])]));

        $this->assertSame('[User]: broken', $serialised);
    }

    public function testItIsCountedTowardsTheContextItTakesUp(): void
    {
        $this->assertGreaterThan(0, Compaction::estimateTokens(
            new HookMessage('build', [new TextContent(str_repeat('a', 400))]),
        ));
    }

    // ---- sending it ------------------------------------------------------------------

    public function testAHookCanPutSomethingInTheConversation(): void
    {
        $api = $this->api();
        $api->on('session_start', static function () use ($api): mixed {
            $api->sendMessage('build', 'the build is broken');

            return null;
        });

        $hooks = $this->runner($api);
        $this->start($hooks, ['fine']);
        $hooks->emit(new \Pig\CodingAgent\Hooks\Events\SessionStartEvent());

        $messages = $this->session->messages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(HookMessage::class, $messages[0]);
        $this->assertSame('build', $messages[0]->customType);
    }

    public function testItIsWrittenToTheSessionFile(): void
    {
        $store = SessionManager::create($this->cwd);
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered'], $store);

        Async::run(fn () => $this->session->prompt('hi'));
        $api->sendMessage('note', 'remember this');

        // Re-read from disk: what matters is that a resumed session still has it.
        $reopened = SessionManager::open($store->path);
        $last = $reopened->messages()[count($reopened->messages()) - 1];

        $this->assertInstanceOf(HookMessage::class, $last);
        $this->assertSame('remember this', $last->toText());
    }

    public function testTriggeringATurnMakesTheAgentAnswerIt(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['I see, I will fix it']);

        Async::run(function () use ($api): void {
            $api->sendMessage('build', 'the build is broken', triggerTurn: true);
        });
        $this->settle();

        // A hook driving the agent, which is the interesting half of `sendMessage`: a
        // startup hook that says "carry on where you left off" needs the turn too.
        $messages = $this->session->messages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertSame('I see, I will fix it', $messages[1]->content[0]->text);
    }

    public function testAMessageSentWhileTheAgentIsWorkingStillArrives(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['working on it', 'noted']);

        // Which is when a hook sends one: something it is watching happened during a turn. It is
        // steered into the run (upstream's default `deliverAs`), and being queued means the agent
        // has to have it. It used to go into this session's own list of what is waiting and
        // nowhere else: the model never saw it, and the footer counted it as pending for ever.
        $this->duringTurn = static function () use ($api): void {
            $api->sendMessage('build', 'the build just broke');
        };

        Async::run(fn () => $this->session->prompt('hi'));
        $this->settle();

        $messages = $this->session->messages();
        $hookMessages = array_values(array_filter($messages, static fn (mixed $m): bool => $m instanceof HookMessage));

        $this->assertCount(1, $hookMessages);
        $this->assertSame('the build just broke', $hookMessages[0]->toText());
        $this->assertSame([], $this->session->queued(), 'and nothing is left waiting');

        $last = $messages[count($messages) - 1];
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame('noted', $last->content[0]->text, 'the agent answered it');
    }

    public function testWithoutTriggeringTheAgentStaysPut(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['never asked for']);

        Async::run(function () use ($api): void {
            $api->sendMessage('build', 'the build is broken');
        });
        $this->settle();

        $this->assertCount(1, $this->session->messages());
    }

    public function testAnEmptyMessageDoesNotTriggerATurnEither(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, []);

        Async::run(function () use ($api): void {
            $api->sendMessage('quiet', [], triggerTurn: true);
        });
        $this->settle();

        // Nothing to answer. Running the model on it would be a request with an empty
        // message in it, which is the thing `toLlm()` drops.
        $this->assertCount(1, $this->session->messages());
    }

    // ---- deliverAs, and a run that is still starting (upstream #10739) ------------------

    public function testANextTurnMessageWaitsForTheNextPrompt(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['ok']);

        $api->sendMessage('git', 'on branch main', deliverAs: 'nextTurn');
        $this->assertSame([], $this->session->messages(), 'nothing yet');

        Async::run(fn () => $this->session->prompt('what changed'));

        $messages = $this->session->messages();
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(HookMessage::class, $messages[1]);
        $this->assertSame('on branch main', $messages[1]->toText());
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
    }

    public function testAnUnknownDeliverAsIsRefused(): void
    {
        $api = $this->api();
        $this->start($this->runner($api), []);

        $this->assertThrows(InvalidArgumentException::class, static fn () => $api->sendMessage('git', 'x', deliverAs: 'later'));
    }

    public function testAFollowUpIsAnsweredAfterTheTurn(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['working on it', 'noted']);
        $this->duringTurn = static function () use ($api): void {
            $api->sendMessage('build', 'the build just broke', deliverAs: 'followUp');
        };

        Async::run(fn () => $this->session->prompt('hi'));
        $this->settle();

        $texts = array_map(static fn (mixed $m): string => $m instanceof HookMessage ? $m->toText() : ($m->content[0]->text ?? ''), $this->session->messages());
        $this->assertSame(['hi', 'working on it', 'the build just broke', 'noted'], $texts);
    }

    public function testATriggeredRunGoesThroughBeforeAgentStartAsACustomRun(): void
    {
        $api = $this->api();
        $seen = [];
        $api->on('before_agent_start', static function ($event) use (&$seen): mixed {
            $seen[] = [$event->messageType, $event->message?->customType];

            return null;
        });
        $this->start($this->runner($api), ['ok', 'done']);

        Async::run(fn () => $this->session->prompt('hello'));
        Async::run(static fn () => $api->sendMessage('task-done', 'background task finished', triggerTurn: true));
        $this->settle();

        $this->assertSame([['user', null], ['custom', 'task-done']], $seen);
    }

    public function testAMessageTriggeredWhileTheRunStartsJoinsThatRun(): void
    {
        $api = $this->api();
        $release = new \Pig\Async\Deferred();
        $started = false;
        $api->on('before_agent_start', static function ($event) use ($release, &$started): mixed {
            if ($event->messageType === 'custom' && $event->message->customType === 'first') {
                $started = true;
                $release->future->await();
            }

            return null;
        });
        $this->start($this->runner($api), ['handled both']);

        $api->sendMessage('first', 'task one finished', triggerTurn: true);
        $this->settle(10);
        $this->assertTrue($started);
        $this->assertFalse($this->session->isIdle(), 'busy while it starts');

        $api->sendMessage('second', 'task two finished', triggerTurn: true);
        $release->complete();
        $this->settle();

        $this->assertCount(1, $this->requests, 'one run, not a second one that fails');
        $texts = [];
        foreach ($this->requests[0] as $message) {
            if ($message instanceof UserMessage && is_array($message->content)) {
                foreach ($message->content as $part) {
                    if ($part instanceof TextContent) {
                        $texts[] = $part->text;
                    }
                }
            }
        }
        $this->assertSame(['task one finished', 'task two finished'], $texts);
    }

    public function testAMessageSentFromBeforeAgentStartIsInTheFirstRequest(): void
    {
        $api = $this->api();
        $api->on('before_agent_start', static function () use ($api): mixed {
            $api->sendMessage('note', 'context from handler', triggerTurn: false);

            return null;
        });
        $this->start($this->runner($api), ['done']);

        Async::run(fn () => $this->session->prompt('hello'));

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('context from handler', json_encode(array_map(static fn (mixed $m): mixed => $m->content, $this->requests[0])));
    }

    public function testAnAbortWhileTheRunStartsAbortsItOnceItStarts(): void
    {
        $api = $this->api();
        $release = new \Pig\Async\Deferred();
        $started = false;
        $api->on('before_agent_start', static function () use ($release, &$started): mixed {
            $started = true;
            $release->future->await();

            return null;
        });
        $this->start($this->runner($api), ['should not complete']);

        $api->sendMessage('task-done', 'background task finished', triggerTurn: true);
        $this->settle(10);
        $this->assertTrue($started);

        $aborted = $this->session->abort();
        $release->complete();
        $this->settle();
        Async::run(static fn () => $aborted->await());

        $messages = $this->session->messages();
        $last = $messages[count($messages) - 1];
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame(StopReason::Aborted, $last->stopReason);
        $this->assertTrue($this->session->isIdle());
    }

    public function testSendingBeforeThereIsASessionSaysSoRatherThanVanishing(): void
    {
        // A hook file is read at startup, before a mode has wired anything up. Silently
        // dropping the message would be a hook that appears to work.
        $api = $this->api();

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $api->sendMessage('build', 'too early'),
        );
        $this->assertStringContainsString('needs a session', $error->getMessage());
    }

    public function testAnUnusableCustomTypeIsRefused(): void
    {
        $api = $this->api();

        foreach (['', 'has space', "new\nline", '-leading'] as $bad) {
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn () => $api->sendMessage($bad, 'x'),
                'cannot be a custom type',
            );
        }
    }

    // ---- writing it down instead -----------------------------------------------------

    public function testAnEntryIsInTheFileAndNotInTheConversation(): void
    {
        $store = SessionManager::create($this->cwd);
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered'], $store);

        Async::run(fn () => $this->session->prompt('hi'));
        $api->appendEntry('permissions', ['level' => 'full']);

        $reopened = SessionManager::open($store->path);

        // Two: the question and the answer. The note is not one of them.
        $this->assertCount(2, $reopened->messages());
        $this->assertCount(1, $reopened->customEntries('permissions'));
        $this->assertSame(['level' => 'full'], $reopened->customEntries('permissions')[0]->data);
    }

    public function testAnEntryWrittenBeforeTheFirstAnswerIsNotLost(): void
    {
        $store = SessionManager::create($this->cwd);
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered'], $store);

        // Upstream's own example is a `session_start` hook noting permissions, which
        // happens before there is a file at all — so holding it back and forgetting it
        // would lose exactly the case this is for.
        $api->appendEntry('permissions', ['level' => 'full']);
        Async::run(fn () => $this->session->prompt('hi'));

        $reopened = SessionManager::open($store->path);
        $this->assertCount(1, $reopened->customEntries('permissions'));
    }

    public function testEntriesComeBackInTheOrderTheyWereWritten(): void
    {
        $store = SessionManager::create($this->cwd);
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered'], $store);

        Async::run(fn () => $this->session->prompt('hi'));
        $api->appendEntry('step', 'one');
        $api->appendEntry('step', 'two');
        $api->appendEntry('other', 'ignored');

        $steps = SessionManager::open($store->path)->customEntries('step');
        $this->assertSame(['one', 'two'], array_map(static fn ($e) => $e->data, $steps));
    }

    public function testEveryEntryComesBackWhenNoTypeIsAskedFor(): void
    {
        $store = SessionManager::create($this->cwd);
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered'], $store);

        Async::run(fn () => $this->session->prompt('hi'));
        $api->appendEntry('a');
        $api->appendEntry('b');

        $this->assertCount(2, SessionManager::open($store->path)->customEntries());
    }

    public function testAnEntryInAnUnsavedSessionIsSimplyNotWritten(): void
    {
        $api = $this->api();
        $hooks = $this->runner($api);
        $this->start($hooks, ['answered']);

        // No store, so nothing to write to — and nothing to throw about either: the point
        // of a note is that it is there next time, and `--no-session` has no next time.
        $api->appendEntry('permissions', ['level' => 'full']);

        $this->assertSame([], SessionManager::listFor($this->cwd));
    }

    // ---- the export ------------------------------------------------------------------

    public function testAnExportLabelsItRatherThanShowingItAsSomethingYouSaid(): void
    {
        $html = HtmlExport::render(
            [new HookMessage('build', [new TextContent('the build is broken')])],
            $this->cwd,
        );

        $this->assertStringContainsString('build', $html);
        $this->assertStringContainsString('the build is broken', $html);
        $this->assertStringNotContainsString('<h2>You</h2>', $html);
    }

    public function testAHiddenMessageStaysOutOfTheExport(): void
    {
        $html = HtmlExport::render(
            [
                new HookMessage('reminder', [new TextContent('only for the model')], display: false),
                new UserMessage('hello'),
            ],
            $this->cwd,
        );

        // An export is something a person sends to somebody else, and a hook's private
        // note to the model is not part of what they would recognise as their conversation.
        $this->assertStringNotContainsString('only for the model', $html);
        $this->assertStringContainsString('hello', $html);
    }

    // ---- drawing it ------------------------------------------------------------------

    public function testARendererIsCollectedByTheRunner(): void
    {
        $api = $this->api();
        $api->registerMessageRenderer('build', static fn (): mixed => null);

        $this->assertArrayHasKey('build', $this->runner($api)->renderers());
    }

    public function testTheLastRendererForATypeWins(): void
    {
        $api = $this->api();
        $api->registerMessageRenderer('build', static fn (): string => 'first');
        $api->registerMessageRenderer('build', static fn (): string => 'second');

        // Two renderers for one message is a question with no answer.
        $renderers = $api->renderers();
        $this->assertCount(1, $renderers);
        $this->assertSame('second', $renderers['build']());
    }

    public function testALaterHooksRendererWinsATypeClash(): void
    {
        $first = new HookApi('.', 'first.php');
        $first->registerMessageRenderer('build', static fn (): string => 'first');

        $second = new HookApi('.', 'second.php');
        $second->registerMessageRenderer('build', static fn (): string => 'second');

        $runner = new HookRunner([
            new LoadedHook('first.php', 'first.php', $first),
            new LoadedHook('second.php', 'second.php', $second),
        ], $this->cwd);

        $this->assertSame('second', $runner->renderers()['build']());
    }

    public function testAModeThatWiresOneWriterGetsThatOne(): void
    {
        $api = $this->api();
        $runner = $this->runner($api);
        $sent = [];

        // One condition for two independent facts meant a mode offering only `send` got
        // neither — and the hook was then told `sendMessage() needs a session — call it from
        // a handler`, which explains something that did not happen.
        $runner->initialize(
            getModel: static fn () => null,
            send: static function (HookMessage $message) use (&$sent): void {
                $sent[] = $message->customType;
            },
        );

        $api->sendMessage('build', 'the build is broken');

        $this->assertSame(['build'], $sent);
    }

    // ---- the fixtures ----------------------------------------------------------------

    /** Called from inside the first turn, for the cases about sending mid-run. */
    private ?Closure $duringTurn = null;

    private function api(): HookApi
    {
        return new HookApi($this->cwd, 'test.php');
    }

    private function runner(HookApi $api): HookRunner
    {
        return new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
    }

    /** @param list<string> $answers */
    private function start(HookRunner $hooks, array $answers, ?SessionManager $store = null): void
    {
        $this->answers = $answers;

        // `toLlm()` as a coding agent wires it, so a request shows a hook's message as the model gets it.
        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k', convertToLlm: static fn (array $messages): array => CodingAgent::toLlm($messages)));
        $agent->setModel(new Model(
            'claude-test',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            200_000,
            64_000,
        ));
        $agent->setTools([]);

        $this->session = new AgentSession($agent, $this->cwd, $store, null, $hooks);

        // What a mode does. Nothing else here has a UI, and none of these tests needs one.
        $session = $this->session;
        $hooks->initialize(
            getModel: static fn () => $session->model(),
            send: static function (HookMessage $message, ?bool $triggerTurn, ?string $deliverAs) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn, $deliverAs);
            },
            note: static function (string $customType, mixed $data) use ($session): void {
                $session->appendHookEntry($customType, $data);
            },
        );
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        // Once, at the top of the first turn: the only moment `isStreaming()` is true and a test
        // still has control.
        $during = $this->duringTurn;
        $this->duringTurn = null;

        if ($during !== null) {
            $during();
        }

        $this->requests[] = $context->messages;

        if ($options->signal?->aborted() === true) {
            $stream = new AssistantMessageEventStream();
            $aborted = new AssistantMessage([], Api::AnthropicMessages, 'anthropic', 'claude-test', new Usage(), StopReason::Aborted);
            Async::spawn(static function () use ($stream, $aborted): void {
                $stream->push(new \Pig\Ai\ErrorEvent(StopReason::Aborted, $aborted));
                $stream->end();
            });

            return $stream;
        }

        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();
        $message = new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push(new DoneEvent(StopReason::Stop, $message));
            $stream->end();
        });

        return $stream;
    }

    private function settle(int $ticks = 100): void
    {
        for ($tick = 0; $tick < $ticks && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    // ---- how it is drawn -------------------------------------------------------------

    public function testALongHookMessageIsShownWhole(): void
    {
        // Upstream's `CustomMessageComponent` does not fold the default draw.
        $text = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 20)));
        $component = new HookMessageComponent(new HookMessage('build', [new TextContent($text)]));

        $collapsedLines = $component->render(60);
        $collapsed = implode("\n", array_map(Ansi::strip(...), $collapsedLines));

        $this->assertStringContainsString(Themes::theme()->getBgAnsi('customMessageBg'), implode('', $collapsedLines));
        $this->assertStringContainsString('[build]', $collapsed);
        $this->assertStringContainsString('line 20', $collapsed);
        $this->assertStringNotContainsString('more lines', $collapsed);
        $this->assertStringNotContainsString('ctrl+o', $collapsed);
    }

    public function testAShortHookMessageIsNotFoldedAndSaysNothingAboutCtrlO(): void
    {
        $component = new HookMessageComponent(
            new HookMessage('build', [new TextContent("one\ntwo")]),
        );

        $drawn = implode("\n", array_map(Ansi::strip(...), $component->render(60)));

        $this->assertStringContainsString('two', $drawn);
        $this->assertStringNotContainsString('more lines', $drawn);
        $this->assertStringNotContainsString('ctrl+o', $drawn);
    }

    public function testABuildLogAHookSentIsDrawnWhateverBytesAreInIt(): void
    {
        // A hook's message is output it just captured, so it is no more pig's own words than a
        // tool's result is — and it was the one of the three transcript paths with no guard at
        // all: `\x80` threw out of `render()`, and `\x0c` reached the terminal and dropped a row.
        $component = new HookMessageComponent(
            new HookMessage('build', [new TextContent("make said\n\x80stray\x0clatin-1\x07done")]),
        );

        $frame = implode("\n", $component->render(60));

        $this->assertTrue(mb_check_encoding($frame, 'UTF-8'), 'the drawn frame is not UTF-8');
        $this->assertStringNotContainsString("\x0c", $frame);
        $this->assertStringNotContainsString("\x07", $frame);
        $this->assertStringContainsString('make said', Ansi::strip($frame));
    }
}
