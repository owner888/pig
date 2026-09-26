<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
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
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Interactive\HookMessageComponent;
use Pig\CodingAgent\Theme\Palette;
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
    use AssertsThrows;

    private string $cwd;

    private AgentSession $session;

    /** @var list<string> */
    private array $answers = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-hookmsg-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->cwd . '-home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
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

    public function testACompactionNamesTheHookRatherThanPretendingSomeoneSaidIt(): void
    {
        $serialised = Compaction::serialize([new HookMessage('build', [new TextContent('broken')])]);

        // "the user said the build is broken" would send the model looking for a
        // conversation that did not happen.
        $this->assertStringContainsString('[Hook build]: broken', $serialised);
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

        // Which is when a hook sends one: something it is watching happened during a turn. The
        // message cannot go in mid-turn — a user message between a tool call and its result is a
        // request every provider rejects — so it is queued, and being queued means the agent has
        // to have it. It used to go into this session's own list of what is waiting and nowhere
        // else: the model never saw it, and the footer counted it as pending for ever.
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
        $this->assertSame('noted', $last->content[0]->text, 'the agent answered it, as a follow-up does');
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
        // of a note is that it is there next time, and `--no-save` has no next time.
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

        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
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
            send: static function (HookMessage $message, bool $triggerTurn) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn);
            },
            note: static function (string $customType, mixed $data) use ($session): void {
                $session->appendHookEntry($customType, $data);
            },
        );
    }

    private function provider(Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        // Once, at the top of the first turn: the only moment `isStreaming()` is true and a test
        // still has control.
        $during = $this->duringTurn;
        $this->duringTurn = null;

        if ($during !== null) {
            $during();
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

    public function testALongHookMessageIsFoldedUntilItIsExpanded(): void
    {
        // What a hook sends is a build failure or a lint report, so this is the common case,
        // not the edge one — and ctrl+o folded every tool call around it while this one stayed
        // open for ever, because the expand mechanism was wired to three classes and not four.
        $text = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 20)));
        $component = new HookMessageComponent(new HookMessage('build', [new TextContent($text)]), Palette::named('dark'));

        $collapsed = implode("\n", array_map(Ansi::strip(...), $component->render(60)));

        $this->assertStringContainsString('line 5', $collapsed);
        $this->assertStringNotContainsString('line 6', $collapsed);
        $this->assertStringContainsString('15 more lines', $collapsed);
        $this->assertStringContainsString('ctrl+o', $collapsed);

        $component->setExpanded(true);
        $expanded = implode("\n", array_map(Ansi::strip(...), $component->render(60)));

        $this->assertStringContainsString('line 20', $expanded);
        $this->assertStringNotContainsString('more lines', $expanded);
        $this->assertStringNotContainsString('ctrl+o', $expanded);
    }

    public function testAShortHookMessageIsNotFoldedAndSaysNothingAboutCtrlO(): void
    {
        $component = new HookMessageComponent(
            new HookMessage('build', [new TextContent("one\ntwo")]),
            Palette::named('dark'),
        );

        $drawn = implode("\n", array_map(Ansi::strip(...), $component->render(60)));

        $this->assertStringContainsString('two', $drawn);
        $this->assertStringNotContainsString('more lines', $drawn);
        $this->assertStringNotContainsString('ctrl+o', $drawn);
    }
}
