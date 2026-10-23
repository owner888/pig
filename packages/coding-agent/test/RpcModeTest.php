<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\TranscriptContext;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSessionEvent;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Rpc\RpcEvents;
use Pig\CodingAgent\Rpc\RpcMode;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Images\TerminalImage;
use Pig\CodingAgent\Tools\ToolSet;
use RuntimeException;

/**
 * The agent with no terminal at all, driven by writing JSON lines at it.
 *
 * A socket pair stands in for standard input, which is the real thing rather than a stand-in:
 * `Loop::onReadable()` selects on it exactly as it would on a pipe from an editor. Output
 * goes to a temp stream, read back from an offset so each assertion sees only what arrived
 * since the last one.
 *
 * Both ends of the pair are held in fields. `[$a] = stream_socket_pair(...)` drops the peer,
 * which is collected, which puts `$a` at EOF — and an EOF on standard input is how a host
 * says goodbye, so the mode would shut down before the first command.
 */
final class RpcModeTest extends TestCase
{
    use \Pig\Test\ProbeModels;

    /** @var resource */
    private $in;

    /** @var resource the end a host writes to */
    private $peer;

    /** @var resource */
    private $out;

    private int $read = 0;

    private AgentSession $session;

    private RpcMode $mode;

    private string $cwd;

    /** @var list<\Pig\Agent\AgentTool> the tools the agent was given, hooks and all */
    private array $tools = [];

    /** @var list<string> one per model call */
    private array $answers = [];

    /** When set, the scripted model waits on it before answering — a turn a test can hold open. */
    private ?\Pig\Async\Deferred $gate = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-rpc-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_CODING_AGENT_DIR=' . $this->cwd . '-home');

        // The scope case needs two models with known ids — see `ProbeModels`.
        self::registerProbeModels();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->mode->stop();
        putenv('PIG_CODING_AGENT_DIR');
        Models::forgetRegistered();
        fclose($this->peer);
        fclose($this->in);
        fclose($this->out);
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

    /**
     * @param list<string>      $answers
     * @param list<FileCommand> $fileCommands
     */
    private function start(
        array $answers = [],
        bool $reasoning = false,
        bool $store = false,
        ?string $resume = null,
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
        ?Settings $settings = null,
        array $fileCommands = [],
        array $modelScope = [],
        bool $images = false,
        ?\Pig\CodingAgent\Auth $auth = null,
    ): void {
        $this->answers = $answers;
        [$this->in, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->out = fopen('php://temp', 'w+') ?: throw new RuntimeException('no temp stream');
        $this->read = 0;

        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
        $agent->setModel(new Model(
            'claude-test',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            200_000,
            64_000,
            $reasoning,
            $images ? ['text', 'image'] : ['text'],
        ));

        $tools = [...ToolSet::create($this->cwd, ['read']), ...($customTools?->agentTools() ?? [])];
        $this->tools = $hooks === null ? $tools : HookedTool::wrap($tools, $hooks);
        $agent->setTools($this->tools);

        $saved = match (true) {
            $resume !== null => SessionManager::open($resume),
            $store => SessionManager::create($this->cwd),
            default => null,
        };

        // With the settings, as `CodingAgent::session()` builds it: the session is what writes
        // the chosen model and thinking level down for next time.
        $this->session = new AgentSession(
            $agent,
            $this->cwd,
            $saved,
            $settings,
            $hooks,
            $fileCommands,
            $modelScope,
        );

        if ($resume !== null && $saved !== null) {
            $this->session->restore($saved->messages());
        }

        $this->mode = new RpcMode(
            $this->session,
            $this->cwd,
            $hooks,
            $customTools,
            $settings ?? Settings::inMemory(),
            auth: $auth,
            in: $this->in,
            out: $this->out,
        );

        $this->mode->start();
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();

        $gate = $this->gate;

        Async::spawn(static function () use ($stream, $text, $gate): void {
            $gate?->future->await();

            // The whole sequence a real provider sends, not just the ends of it: `AgentLoop`
            // turns `StartEvent` and `DoneEvent` into `message_start` and `message_end`, and
            // a `message_update` only exists if something streamed in between.
            $stream->push(new StartEvent(self::message('')));
            $stream->push(new TextStartEvent(0, self::message('')));

            foreach (str_split($text, 4) as $piece) {
                $stream->push(new TextDeltaEvent(0, $piece, self::message($text)));
            }

            $stream->push(new TextEndEvent(0, $text, self::message($text)));
            $stream->push(new DoneEvent(StopReason::Stop, self::message($text)));
            $stream->end();
        });

        return $stream;
    }

    private static function message(string $text): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );
    }

    // ---- driving -----------------------------------------------------------------------

    /** Write one command and let the loop deal with it. */
    private function send(array $command): void
    {
        fwrite($this->peer, json_encode($command) . "\n");
        $this->settle();
    }

    /** Write raw bytes, whole lines or not. */
    private function write(string $bytes): void
    {
        fwrite($this->peer, $bytes);
        $this->settle();
    }

    /**
     * Turn the loop a fixed number of times.
     *
     * A timer before each one, because a lone readable watcher makes `pollTimeout()` null and
     * a `tick()` that blocks in `stream_select()` forever is a test that never ends. `wake()`
     * is not enough: a tick empties the queue before it polls, so by the time `pollTimeout()`
     * is asked the queue is empty again and the answer is still null. A timer is still there
     * when it is asked.
     *
     * The millisecond is not padding. A `bash` command is a real process, and a loop spinning
     * with a zero timeout gets through forty ticks long before `echo` has written anything —
     * so the poll has to be allowed to wait, and the total here is what a subprocess needs to
     * start and finish.
     *
     * `isIdle()` is no use as a stopping condition, and for the same reason the timeout is
     * needed: the stdin watcher is armed for as long as the mode is running, so the loop is
     * never idle.
     */
    private function settle(int $ticks = 40, float $wait = 0.001): void
    {
        for ($tick = 0; $tick < $ticks; $tick++) {
            Loop::get()->delay($wait, static fn () => null);
            Loop::get()->tick();
        }
    }

    /**
     * Everything written since the last time this was called.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        fseek($this->out, $this->read);
        $bytes = stream_get_contents($this->out);
        $this->read += strlen((string) $bytes);

        $objects = [];

        foreach (explode("\n", (string) $bytes) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "not JSON: {$line}");
            $objects[] = $decoded;
        }

        return $objects;
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     * @return list<array<string, mixed>>
     */
    private function of(string $type, ?array $lines = null): array
    {
        return array_values(array_filter(
            $lines ?? $this->lines(),
            static fn (array $line): bool => ($line['type'] ?? null) === $type,
        ));
    }

    /** @return array<string, mixed> */
    private function response(array $command): array
    {
        // Drained first: a `send()` that was not asked for its lines left them sitting in the
        // stream, and one of them is its own response.
        $this->lines();
        $this->send($command);
        $responses = $this->of('response');
        $this->assertCount(1, $responses, 'expected exactly one response');

        return $responses[0];
    }

    /** @return array<string, mixed> */
    private function data(array $command): array
    {
        $response = $this->response($command);
        $this->assertTrue($response['success'], $response['error'] ?? 'no error given');
        $this->assertIsArray($response['data'] ?? null, 'expected data on the response');

        return $response['data'];
    }

    // ---- the events ----------------------------------------------------------------------

    public function testANestedCallsEventsNameTheirParentAndAResultCarriesWhatTheToolSpent(): void
    {
        // Pure encoding; the mode is started only so tearDown() has one to stop.
        $this->start();
        $start = RpcEvents::encode(new \Pig\Agent\ToolExecutionStartEvent('call-1/1', 'read', ['path' => 'a.txt'], 'call-1'));
        $this->assertSame('call-1', $start['parentToolCallId'] ?? null);

        $spent = new \Pig\Agent\AgentToolResult([new TextContent('ok')], null, new Usage(10, 2, 0, 0, 12, new \Pig\Ai\Cost(total: 0.003)));
        $end = RpcEvents::encode(new \Pig\Agent\ToolExecutionEndEvent('call-1/1', 'read', $spent, false, parentToolCallId: 'call-1'));
        $this->assertSame('call-1', $end['parentToolCallId'] ?? null);
        $this->assertSame(0.003, $end['result']['usage']['cost']['total'] ?? null);

        // Upstream spreads `parentToolCallId` in only when set, so a model-issued call has no key.
        $plain = RpcEvents::encode(new \Pig\Agent\ToolExecutionStartEvent('call-2', 'read', []));
        $this->assertArrayNotHasKey('parentToolCallId', $plain);
    }

    // ---- the envelope ------------------------------------------------------------------

    public function testAResponseCarriesTheCommandsOwnId(): void
    {
        $this->start();
        $response = $this->response(['id' => 'abc', 'type' => 'get_state']);

        $this->assertSame('abc', $response['id']);
        $this->assertSame('get_state', $response['command']);
        $this->assertTrue($response['success']);
    }

    public function testAnIdIsOptionalAndItsAbsenceIsNotAnEmptyOne(): void
    {
        $this->start();
        $response = $this->response(['type' => 'get_state']);

        // Not `"id": null`: a host matching replies by id should not see one it never sent.
        $this->assertArrayNotHasKey('id', $response);
    }

    public function testAnIdThatArrivedAsANumberComesBackAsAString(): void
    {
        $this->start();

        $this->assertSame('7', $this->response(['id' => 7, 'type' => 'get_state'])['id']);
    }

    public function testAnUnknownCommandIsAFailedResponseRatherThanASilence(): void
    {
        $this->start();
        $response = $this->response(['id' => '1', 'type' => 'make_coffee']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('make_coffee', $response['error']);
        $this->assertSame('1', $response['id']);
    }

    public function testACommandMissingARequiredFieldSaysWhichOne(): void
    {
        $this->start();
        $response = $this->response(['id' => '1', 'type' => 'prompt']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString("'message' is required", $response['error']);
    }

    public function testALineThatIsNotJsonIsAnsweredRatherThanCrashing(): void
    {
        $this->start();
        $this->write("not json at all\n");

        $response = $this->of('response')[0];
        $this->assertSame('parse', $response['command']);
        $this->assertFalse($response['success']);
        // Upstream's sentence, with V8's words after the colon.
        $this->assertStringStartsWith('Failed to parse command: Unexpected token', $response['error']);
    }

    public function testACarriageReturnBeforeTheLineFeedIsNotPartOfTheCommand(): void
    {
        $this->start();
        $this->write(json_encode(['id' => 'crlf', 'type' => 'get_state']) . "\r\n");

        $this->assertTrue($this->of('response')[0]['success']);
    }

    public function testAJsonScalarIsNotACommandEither(): void
    {
        $this->start();
        $this->write("42\n");

        $this->assertFalse($this->of('response')[0]['success']);
    }

    // ---- the buffer --------------------------------------------------------------------

    public function testThreeCommandsInOneWriteGetThreeResponses(): void
    {
        $this->start();
        $this->write(implode('', array_map(
            static fn (string $id): string => json_encode(['id' => $id, 'type' => 'get_state']) . "\n",
            ['a', 'b', 'c'],
        )));

        $ids = array_column($this->of('response'), 'id');
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function testACommandSplitAcrossTwoWritesIsStillOneCommand(): void
    {
        $this->start();
        $whole = json_encode(['id' => 'x', 'type' => 'get_state']) . "\n";

        $this->write(substr($whole, 0, 12));
        $this->assertSame([], $this->of('response'), 'half a line is not a command yet');

        $this->write(substr($whole, 12));
        $this->assertSame('x', $this->of('response')[0]['id']);
    }

    public function testABlankLineIsNotACommand(): void
    {
        $this->start();
        $this->write("\n\n   \n");

        $this->assertSame([], $this->lines());
    }

    public function testEndOfInputStopsTheLoop(): void
    {
        $this->start();
        fclose($this->peer);
        $this->peer = fopen('php://temp', 'w+') ?: throw new RuntimeException('no temp stream');

        Loop::get()->run();

        // Back from run() at all is the assertion: a loop that had not stopped would still
        // be inside stream_select().
        $this->assertTrue(true);
    }

    // ---- state -------------------------------------------------------------------------

    public function testGetStateDescribesTheModelAndWhatItIsDoing(): void
    {
        $this->start(store: true);
        $state = $this->data(['type' => 'get_state']);

        $this->assertSame('claude-test', $state['model']['id']);
        $this->assertSame('anthropic', $state['model']['provider']);
        $this->assertFalse($state['isStreaming']);
        $this->assertFalse($state['isCompacting']);
        $this->assertSame('one-at-a-time', $state['steeringMode'], 'the defaults a host can read back');
        $this->assertSame('one-at-a-time', $state['followUpMode']);
        $this->assertFalse($state['isBashRunning']);
        $this->assertFalse($state['isStoppedMidRun'], 'what the page offers to continue');
        $this->assertSame(0, $state['messageCount']);
        $this->assertStringEndsWith('.jsonl', $state['sessionFile']);
        // Under a physical model there is nothing routed; the key is there so a host can read it
        // without checking first (the web shell's telemetry line does). The populated shape is
        // `VirtualModelsTest`'s `routedModel()`, encoded as `{provider, id, thinkingLevel}`.
        $this->assertArrayHasKey('routedModel', $state);
        $this->assertNull($state['routedModel']);
    }

    public function testAHostCanSetEachQueuesModeAndNotOnlyReadIt(): void
    {
        // Upstream's `set_steering_mode` and `set_follow_up_mode`, one command per queue.
        $this->start(store: true);

        $this->assertTrue($this->response(['type' => 'set_steering_mode', 'mode' => 'all'])['success']);
        $state = $this->data(['type' => 'get_state']);
        $this->assertSame('all', $state['steeringMode']);
        $this->assertSame('one-at-a-time', $state['followUpMode']);

        $this->assertTrue($this->response(['type' => 'set_follow_up_mode', 'mode' => 'all'])['success']);
        $this->assertSame('all', $this->data(['type' => 'get_state'])['followUpMode']);
    }

    public function testAQueueModeThatIsNotOneOfTheTwoIsNamedRatherThanIgnored(): void
    {
        $this->start();
        $response = $this->response(['type' => 'set_follow_up_mode', 'mode' => 'whenever']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('whenever', $response['error']);
    }

    public function testAnUnsavedSessionHasNoSessionFileButStillAnId(): void
    {
        $this->start();

        // Left out, as upstream's undefined `sessionFile` is — but upstream's in-memory session has
        // an id, and so does pig's.
        $state = $this->data(['type' => 'get_state']);
        $this->assertArrayNotHasKey('sessionFile', $state);
        $this->assertSame($this->session->sessionId(), $state['sessionId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $state['sessionId']);
        $this->assertArrayNotHasKey('sessionName', $state);
    }

    public function testGetStateHasUpstreamsFieldsUnderUpstreamsNames(): void
    {
        $this->start(store: true);
        $state = $this->data(['type' => 'get_state']);

        $this->assertSame($this->session->store()?->id, $state['sessionId']);
        $this->assertSame(0, $state['pendingMessageCount']);
        $this->assertArrayNotHasKey('queuedMessageCount', $state);
        $this->assertTrue($state['autoCompactionEnabled']);
        // The Model object `rpc-commands.md` describes.
        $this->assertSame('http://127.0.0.1:1', $state['model']['baseUrl']);
        $this->assertSame(['text'], $state['model']['input']);
        $this->assertSame(['input', 'output', 'cacheRead', 'cacheWrite'], array_keys($state['model']['cost']));
    }

    public function testGetSessionStatsCountsWhatHappened(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $stats = $this->data(['type' => 'get_session_stats']);
        $this->assertSame(1, $stats['userMessages']);
        $this->assertSame(1, $stats['assistantMessages']);
        // Upstream counts every message entry, and the leading system message is one now.
        $this->assertSame(3, $stats['totalMessages']);
        // Upstream's `SessionStats`: the tokens in one object, and the window's use.
        $this->assertSame(['input', 'output', 'cacheRead', 'cacheWrite', 'total'], array_keys($stats['tokens']));
        $this->assertSame(200_000, $stats['contextUsage']['contextWindow']);
        $this->assertArrayHasKey('sessionId', $stats);
    }

    public function testGetLastAssistantTextIsTheAnswerWithoutTheEvents(): void
    {
        $this->start(answers: ['the sky is blue']);
        $this->send(['type' => 'prompt', 'message' => 'why']);

        $this->assertSame('the sky is blue', $this->data(['type' => 'get_last_assistant_text'])['text']);
    }

    public function testTranslateAsksTheModelAndKeepsNothing(): void
    {
        $this->start(answers: ['天空是蓝色的']);

        $this->assertSame('天空是蓝色的', $this->data(['type' => 'translate', 'text' => 'the sky is blue', 'language' => '简体中文', 'doNotTranslate' => ['简体中文']])['text']);
        // A side request, not a turn: the conversation has nothing in it.
        $this->assertSame(0, $this->data(['type' => 'get_session_stats'])['assistantMessages']);
    }

    public function testTranslateAnswersNullForTextAlreadyInALanguageTheReaderHas(): void
    {
        $this->start(answers: ['SAME']);

        $this->assertNull($this->data(['type' => 'translate', 'text' => 'the sky is blue', 'language' => 'English', 'doNotTranslate' => ['English']])['text']);
    }

    public function testGetMessagesRoundTripsThroughTheSessionCodec(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $messages = $this->data(['type' => 'get_messages'])['messages'];

        // The same shape the session file holds, because it is the same encoder. A host
        // reading these and a `--continue` reading the file see one conversation. Upstream's
        // `session.messages` leads with the system message holding the prompt and the tools.
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('hi', $messages[1]['content'][0]['text']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('hello', $messages[2]['content'][0]['text']);
    }

    public function testGetMessagesWithALimitAnswersTheEndCutAtAUserMessage(): void
    {
        // pig's own: a page asks for the end of a long conversation, then the parts before it.
        $this->start(answers: ['one', 'two', 'three']);
        foreach (['a', 'b', 'c'] as $text) {
            $this->send(['type' => 'prompt', 'message' => $text]);
        }

        $all = $this->data(['type' => 'get_messages'])['messages'];
        $this->assertCount(7, $all, 'system, then three user and assistant pairs');

        // One message asked for: the part starts at the user message before it, so the answer
        // never arrives without its question.
        $tail = $this->data(['type' => 'get_messages', 'limit' => 1]);
        $this->assertSame(7, $tail['total']);
        $this->assertSame(5, $tail['start']);
        $this->assertSame(['user', 'assistant'], array_column($tail['messages'], 'role'));
        $this->assertSame('c', $tail['messages'][0]['content'][0]['text']);

        // The part before it, up to `start`.
        $before = $this->data(['type' => 'get_messages', 'limit' => 2, 'before' => $tail['start']]);
        $this->assertSame(3, $before['start']);
        $this->assertSame('b', $before['messages'][0]['content'][0]['text']);

        // And down to the beginning.
        $first = $this->data(['type' => 'get_messages', 'limit' => 10, 'before' => 3]);
        $this->assertSame(0, $first['start']);
        $this->assertSame(['system', 'user', 'assistant'], array_column($first['messages'], 'role'));
    }

    public function testHistoryImagesComeAsRefsAndOneAtATimeOnRequest(): void
    {
        // pig's own: a page draws a history of screenshots fetching each as it comes into view.
        $this->start(answers: ['seen'], images: true);
        // A real one: an image that cannot be read is dropped before it reaches the history.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $this->send(['type' => 'prompt', 'message' => 'look', 'images' => [['type' => 'image', 'data' => $png, 'mimeType' => 'image/png']]]);

        $page = $this->data(['type' => 'get_messages', 'limit' => 10, 'images' => 'refs']);
        $user = $page['messages'][1];
        $this->assertSame('user', $user['role']);
        $image = array_values(array_filter($user['content'], static fn (array $c): bool => $c['type'] === 'image'))[0];

        $this->assertArrayNotHasKey('data', $image, 'no bytes in the history');
        $this->assertSame('image/png', $image['mimeType']);
        $this->assertMatchesRegularExpression('/^1\.content\.\d+$/', $image['ref'], 'the message index, then the path inside it');

        // Without `images`, as upstream's: the data inline — and `get_image` answers the same.
        $inline = $this->data(['type' => 'get_messages'])['messages'][1]['content'];
        $data = array_values(array_filter(array_column($inline, 'data')))[0];
        $this->assertSame(['mimeType' => 'image/png', 'data' => $data], $this->data(['type' => 'get_image', 'ref' => $image['ref']]));
    }

    // ---- prompting ---------------------------------------------------------------------

    public function testPromptRespondsBeforeTheTurnAndTheEventsFollow(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['id' => 'p1', 'type' => 'prompt', 'message' => 'hi']);
        $lines = $this->lines();

        // The response is an acknowledgement, not a completion: `agent_end` is the
        // completion. A host that waited for the response to mean "done" would think a
        // long turn had finished before it began.
        $this->assertSame('response', $lines[0]['type']);
        $this->assertSame('p1', $lines[0]['id']);
        $this->assertSame(['disposition' => 'started'], $lines[0]['data']);
        $this->assertCount(1, $this->of('response', $lines), 'one response, not one per outcome');

        $order = array_column($lines, 'type');
        $this->assertContains('agent_start', $order);
        $this->assertContains('message_start', $order);
        $this->assertContains('message_end', $order);
        $this->assertContains('agent_end', $order);
        $this->assertLessThan(
            array_search('agent_end', $order, true),
            array_search('message_start', $order, true),
        );
        // `agent_settled` is the end: nothing follows the prompt automatically after it.
        $this->assertSame('agent_settled', end($order));
        $this->assertFalse(end($lines)['aborted']);
    }

    public function testAPromptSentAgainUnderItsRequestIdIsNotAskedTwice(): void
    {
        $this->start(answers: ['hello'], store: true);
        $this->send(['id' => 'p1', 'type' => 'prompt', 'message' => 'hi', 'requestId' => 'r-1']);
        $this->assertSame(['disposition' => 'started'], $this->of('response')[0]['data']);

        // The host lost that answer and sends again: one answer was scripted, so a second turn
        // would run out of them.
        $this->assertSame(['disposition' => 'duplicate'], $this->data(['type' => 'prompt', 'message' => 'hi', 'requestId' => 'r-1']));
        $this->assertCount(1, array_filter($this->session->messages(), static fn ($m): bool => $m instanceof \Pig\Ai\UserMessage), 'asked once');

        $this->assertStringContainsString(
            'non-empty string',
            $this->response(['type' => 'prompt', 'message' => 'hi', 'requestId' => ''])['error'],
        );
    }

    public function testContinueAnswersContinuedAndTheTurnFollows(): void
    {
        $this->start(answers: ['carried on']);
        $this->session->agent->replaceMessages([new \Pig\Ai\UserMessage('the question')]);
        $this->send(['id' => 'c1', 'type' => 'continue']);
        $lines = $this->lines();

        $this->assertSame('response', $lines[0]['type']);
        $this->assertSame(['outcome' => 'continued'], $lines[0]['data']);
        $this->assertSame('agent_settled', end($lines)['type']);
        $messages = $this->session->messages();
        $this->assertSame('carried on', end($messages)->content[0]->text);
    }

    public function testContinueOnAnAnsweredConversationSaysFinishedAndRunsNothing(): void
    {
        $this->start();
        $this->session->agent->replaceMessages([new \Pig\Ai\UserMessage('the question'), self::message('done')]);
        $response = $this->response(['id' => 'c1', 'type' => 'continue']);

        $this->assertTrue($response['success']);
        $this->assertSame(['outcome' => 'finished'], $response['data']);
        $this->assertSame([], $this->of('agent_start'));
    }

    public function testContinueOnAnEmptyConversationIsRefusedWithItsReason(): void
    {
        $this->start();
        $response = $this->response(['id' => 'c1', 'type' => 'continue']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('nothing in this session', $response['error']);
    }

    public function testAPromptReadsTheCredentialsFileAgain(): void
    {
        // The web page's key panel writes `auth.json` from the server's process; a conversation
        // opened before that has to see the key on its next prompt, not after being reopened.
        $file = $this->cwd . '/auth.json';
        $auth = \Pig\CodingAgent\Auth::discover($file);
        $this->start(answers: ['hello'], auth: $auth);
        $this->assertFalse($auth->has('openai'));

        file_put_contents($file, (string) json_encode(['openai' => ['type' => 'api_key', 'key' => 'sk-later']]));
        $this->send(['id' => 'p1', 'type' => 'prompt', 'message' => 'hi']);

        $this->assertTrue($this->of('response')[0]['success']);
        $this->assertTrue($auth->has('openai'));
    }

    public function testAPromptWhileBusyIsRefusedOnceAndAStreamingBehaviorQueuesIt(): void
    {
        $this->gate = new \Pig\Async\Deferred();
        $this->start(answers: ['hello', 'later']);
        $this->send(['id' => 'p1', 'type' => 'prompt', 'message' => 'hi']);
        $this->assertSame('started', $this->of('response')[0]['data']['disposition']);

        // Busy and nothing said about what to do with it: one failure, and only one — pig used
        // to send a success and then the failure for the same id.
        $this->send(['id' => 'p2', 'type' => 'prompt', 'message' => 'again']);
        $responses = $this->of('response');
        $this->assertCount(1, $responses);
        $this->assertFalse($responses[0]['success']);
        $this->assertStringContainsString('streamingBehavior', $responses[0]['error']);

        $this->send(['id' => 'p3', 'type' => 'prompt', 'message' => 'afterwards', 'streamingBehavior' => 'followUp']);
        $lines = $this->lines();
        $this->assertSame(['disposition' => 'queued'], $this->of('response', $lines)[0]['data']);
        $this->assertSame(['steering' => [], 'followUp' => ['afterwards']], array_diff_key($this->of('queue_update', $lines)[0], ['type' => 0]));
        $this->assertSame(1, $this->data(['type' => 'get_state'])['pendingMessageCount']);

        $this->assertSame(['steering' => [], 'followUp' => ['afterwards']], $this->data(['type' => 'clear_queue']));
        $this->assertSame(0, $this->data(['type' => 'get_state'])['pendingMessageCount']);

        $this->gate->complete(null);
        $this->settle();
    }

    public function testAnUpdateCarriesTheDeltaAndTheUsageRatherThanTheWholeMessage(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $updates = $this->of('message_update');
        $this->assertNotSame([], $updates);

        // Upstream's `toJsonEvent()`: `usage` and `assistantMessageEvent`, and no `partial`.
        foreach ($updates as $update) {
            $this->assertSame(['type', 'usage', 'assistantMessageEvent'], array_keys($update));
            $this->assertArrayNotHasKey('partial', $update['assistantMessageEvent']);
        }
    }

    public function testTheStreamingEventsAreUpstreams(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $deltas = array_column($this->of('message_update'), 'assistantMessageEvent');
        $types = array_column($deltas, 'type');
        $this->assertContains('text_start', $types);
        $this->assertContains('text_delta', $types);
        $this->assertContains('text_end', $types);
        $this->assertSame('hello', implode('', array_column(array_filter($deltas, static fn (array $d): bool => $d['type'] === 'text_delta'), 'delta')));
    }

    public function testAToolCallsStartNamesItAndItsEndCarriesIt(): void
    {
        $this->start();
        $partial = new AssistantMessage(
            [new \Pig\Ai\ToolCall('call-1', 'read', [])],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::ToolUse,
        );

        $start = RpcEvents::encode(new \Pig\Agent\MessageUpdateEvent($partial, new \Pig\Ai\ToolCallStartEvent(0, $partial)));
        $this->assertSame(['type' => 'toolcall_start', 'contentIndex' => 0, 'id' => 'call-1', 'toolName' => 'read'], $start['assistantMessageEvent']);

        $end = RpcEvents::encode(new \Pig\Agent\MessageUpdateEvent($partial, new \Pig\Ai\ToolCallEndEvent(0, $partial->content[0], $partial)));
        $this->assertSame('toolcall_end', $end['assistantMessageEvent']['type']);
        $this->assertSame('call-1', $end['assistantMessageEvent']['toolCall']['id']);

        $done = RpcEvents::delta(new DoneEvent(StopReason::ToolUse, $partial));
        $this->assertSame('toolUse', $done['reason']);
        $this->assertSame('assistant', $done['message']['role']);

        // `args`, and an object even when empty.
        $tool = RpcEvents::encode(new \Pig\Agent\ToolExecutionStartEvent('call-1', 'read', []));
        $this->assertSame('{}', json_encode($tool['args']));
    }

    public function testTurnEndCarriesTheMessageAndItsToolResults(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $end = $this->of('turn_end')[0];
        $this->assertSame('hello', $end['message']['content'][0]['text']);
        $this->assertSame([], $end['toolResults']);
    }

    public function testSteerAndFollowUpAreTwoQueuesRatherThanOne(): void
    {
        $this->start(answers: ['hello']);

        // Nothing is running, so both land in the conversation the next prompt starts
        // from — the point being that the protocol has both, because pig has both.
        $this->assertSame(['disposition' => 'queued'], $this->data(['type' => 'steer', 'message' => 'a']));
        $this->assertSame(['disposition' => 'queued'], $this->data(['type' => 'follow_up', 'message' => 'b']));
    }

    public function testSteeringCarriesItsPictures(): void
    {
        $this->start(answers: ['seen']);
        $this->data(['type' => 'steer', 'message' => 'look', 'images' => [['type' => 'image', 'data' => 'aGk=', 'mimeType' => 'image/png']]]);
        $this->send(['type' => 'prompt', 'message' => 'go']);

        $steered = array_values(array_filter(
            $this->session->messages(),
            static fn (mixed $m): bool => $m instanceof UserMessage && ($m->content[0]->text ?? null) === 'look',
        ));
        $this->assertInstanceOf(\Pig\Ai\ImageContent::class, $steered[0]->content[1] ?? null);
    }

    public function testAHostsSlashCommandsReachTheSameDispatchTheTerminalDoes(): void
    {
        $api = new HookApi($this->cwd, 'deploy.php');
        $seen = null;
        $api->registerCommand('deploy', static function (string $arguments) use (&$seen): void {
            $seen = $arguments;
        });

        $this->start(
            answers: ['reviewed'],
            hooks: new HookRunner([new LoadedHook('deploy.php', 'deploy.php', $api)], $this->cwd),
            fileCommands: [new FileCommand('review', 'Review', 'Please review $1 closely.', '(user)')],
        );

        // A hook's command runs, and nothing is asked of the model.
        $this->assertSame(['disposition' => 'handled'], $this->data(['type' => 'prompt', 'message' => '/deploy staging']));
        $this->assertSame('staging', $seen);
        $this->assertSame([], $this->session->messages());

        // It cannot be queued, as upstream's `_throwIfExtensionCommand()` says.
        $refused = $this->response(['type' => 'steer', 'message' => '/deploy later']);
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('cannot be queued', $refused['error']);

        // `get_commands` lists both kinds, under upstream's `source`.
        $commands = array_column($this->data(['type' => 'get_commands'])['commands'], null, 'name');
        $this->assertSame('extension', $commands['deploy']['source']);
        $this->assertSame('deploy.php', $commands['deploy']['sourceInfo']['path']);
        $this->assertSame('prompt', $commands['review']['source']);
        $this->assertSame('user', $commands['review']['sourceInfo']['scope']);

        // A stored prompt is expanded, through `prompt` and through both queueing commands —
        // the three doors text comes in by, which is why the expansion is on all three. The
        // queued two only join the conversation when a run starts, so the prompt is what
        // flushes them.
        $this->assertTrue($this->response(['type' => 'steer', 'message' => '/review a.php'])['success']);
        $this->assertTrue($this->response(['type' => 'follow_up', 'message' => '/review b.php'])['success']);
        $this->assertTrue($this->response(['type' => 'prompt', 'message' => '/review c.php'])['success']);

        $texts = array_map(
            static fn (mixed $message): string => $message->content[0]->text,
            array_values(array_filter(
                $this->session->messages(),
                static fn (mixed $message): bool => $message instanceof UserMessage,
            )),
        );

        sort($texts);
        $this->assertSame([
            'Please review a.php closely.',
            'Please review b.php closely.',
            'Please review c.php closely.',
        ], $texts);
    }

    public function testAbortWithNothingRunningIsStillASuccess(): void
    {
        $this->start();

        $this->assertTrue($this->response(['type' => 'abort'])['success']);
    }

    // ---- the model ---------------------------------------------------------------------

    public function testGetAvailableModelsListsThemWithTheirContextWindows(): void
    {
        $this->start();
        $models = $this->data(['type' => 'get_available_models'])['models'];

        $this->assertGreaterThan(100, count($models));
        $this->assertIsString($models[0]['id']);
        $this->assertIsInt($models[0]['contextWindow']);
        $this->assertIsBool($models[0]['reasoning']);
        $this->assertIsString($models[0]['baseUrl']);
        $this->assertIsArray($models[0]['input']);
        $this->assertIsArray($models[0]['cost']);
    }

    public function testSetModelTakesAWholeIdAndAnswersWithTheModel(): void
    {
        $this->start();
        $model = $this->data(['type' => 'set_model', 'modelId' => 'claude-sonnet-4-5']);

        $this->assertSame('claude-sonnet-4-5', $model['id']);
        $this->assertSame('claude-sonnet-4-5', $this->data(['type' => 'get_state'])['model']['id']);
    }

    public function testSetModelAcceptsAProviderBesideTheId(): void
    {
        $this->start();

        $this->assertSame('anthropic', $this->data([
            'type' => 'set_model',
            'modelId' => 'claude-sonnet-4-5',
            'provider' => 'anthropic',
        ])['provider']);
    }

    /**
     * `zzp-shared` is sold by `openai` and resold by `github-copilot`. The provider used to be
     * built into a `"provider/id"` string and handed to `Models::get()`, which compares bare ids
     * — so it matched nothing, the `?? Models::get($id)` fallback answered the *direct* provider
     * by `RESOLD`, and a host that named the reseller got the other one with `success: true`.
     * The web UI goes through this command now, and its model picker names the provider every
     * time, which is how it was found.
     */
    public function testSetModelHonoursTheProviderWhenTheIdIsResold(): void
    {
        $this->start();

        $this->assertSame('github-copilot', $this->data([
            'type' => 'set_model',
            'modelId' => 'zzp-shared',
            'provider' => 'github-copilot',
        ])['provider'], 'the reseller was named, so the reseller is what answers');

        $this->assertSame('openai', $this->data([
            'type' => 'set_model',
            'modelId' => 'zzp-shared',
            'provider' => 'openai',
        ])['provider']);

        $this->assertSame('openai', $this->data([
            'type' => 'set_model',
            'modelId' => 'zzp-shared',
        ])['provider'], 'bare id still means the direct provider');

        $this->assertSame('github-copilot', $this->data([
            'type' => 'set_model',
            'modelId' => 'github-copilot/zzp-shared',
        ])['provider'], 'the combined spelling is the same request');
    }

    public function testSetModelWithAProviderThatDoesNotSellItIsRefusedRatherThanRedirected(): void
    {
        $this->start();

        $response = $this->response(['type' => 'set_model', 'modelId' => 'zzp-only', 'provider' => 'anthropic']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('anthropic/zzp-only', $response['error']);
    }

    public function testAModelAHostChoosesChangesSessionAndLeavesSettingsUntouched(): void
    {
        $settings = Settings::inMemory();
        $this->start(settings: $settings);

        $this->data(['type' => 'set_model', 'modelId' => 'claude-sonnet-4-5']);

        $this->assertSame('claude-sonnet-4-5', $this->session->model()?->id);
        $this->assertNull($settings->defaultModel());
    }

    public function testAThinkingLevelAHostChoosesChangesSessionAndLeavesSettingsUntouched(): void
    {
        $settings = Settings::inMemory();
        $this->start(reasoning: true, settings: $settings);

        $this->assertTrue($this->response(['type' => 'set_thinking_level', 'level' => 'high'])['success']);

        $this->assertSame(ThinkingLevel::High, $this->session->thinkingLevel());
        $this->assertNull($settings->defaultThinkingLevel());
    }

    public function testCycleModelMovesAlongTheListAndBackAgain(): void
    {
        $this->start();

        $first = $this->data(['type' => 'cycle_model'])['model']['id'] ?? null;
        $second = $this->data(['type' => 'cycle_model'])['model']['id'] ?? null;

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second);

        $back = $this->data(['type' => 'cycle_model', 'direction' => 'backward'])['model']['id'] ?? null;

        $this->assertSame($first, $back);
        $this->assertSame($first, $this->data(['type' => 'get_state'])['model']['id'], 'and the session is on it');
    }

    public function testAHostCyclesInsideTheScopeToo(): void
    {
        [$scope, $warnings] = ModelResolver::scope(
            ['zzp-plain', 'zzp-alpha:high'],
            Models::all(),
        );
        $this->assertSame([], $warnings);

        $this->start(reasoning: true, modelScope: $scope);

        $first = $this->data(['type' => 'cycle_model']);
        $second = $this->data(['type' => 'cycle_model']);

        // Both ends of the wire go through `AgentSession::cycleModel()`, so a host gets the scope
        // and the thinking level that comes with each entry — which is the whole reason the
        // rotation moved off `ModelResolver` and onto the session.
        $this->assertSame('zzp-alpha', $first['model']['id']);
        $this->assertSame('high', $first['thinkingLevel']);
        $this->assertSame('zzp-plain', $second['model']['id']);
        $this->assertSame('off', $second['thinkingLevel']);
        $this->assertTrue($first['isScoped']);
    }

    public function testAModelThatDoesNotExistIsAnErrorNotASilentNoChange(): void
    {
        $this->start();
        $response = $this->response(['type' => 'set_model', 'modelId' => 'gpt-9']);

        $this->assertFalse($response['success']);
        $this->assertSame('Model not found: gpt-9', $response['error']);
        $this->assertSame('claude-test', $this->data(['type' => 'get_state'])['model']['id']);
    }

    public function testAThinkingLevelAModelCannotReachIsClampedRatherThanRefused(): void
    {
        // Upstream's `setThinkingLevel()` clamps: a model that cannot reason lands on off.
        $this->start(reasoning: false);
        $response = $this->response(['type' => 'set_thinking_level', 'level' => 'high']);

        $this->assertTrue($response['success']);
        $this->assertSame('off', $this->data(['type' => 'get_state'])['thinkingLevel']);
    }

    public function testAThinkingLevelAModelCanReachIsTaken(): void
    {
        $this->start(reasoning: true);

        $this->assertTrue($this->response(['type' => 'set_thinking_level', 'level' => 'high'])['success']);
        $this->assertSame('high', $this->data(['type' => 'get_state'])['thinkingLevel']);
    }

    public function testALevelThatIsNotALevelSaysSo(): void
    {
        $this->start(reasoning: true);
        $response = $this->response(['type' => 'set_thinking_level', 'level' => 'enormous']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('enormous', $response['error']);
    }

    public function testCycleThinkingLevelAnswersWithWhereItLanded(): void
    {
        $this->start(reasoning: true);

        $this->assertIsString($this->data(['type' => 'cycle_thinking_level'])['level']);
    }

    public function testTheAvailableThinkingLevelsAreOffAloneForAModelThatCannotReason(): void
    {
        $this->start(reasoning: false);
        $this->assertSame(['off'], $this->data(['type' => 'get_available_thinking_levels'])['levels']);
    }

    public function testAThinkingLevelChangeIsAnnounced(): void
    {
        $this->start(reasoning: true);
        $this->send(['type' => 'set_thinking_level', 'level' => 'high']);

        $this->assertSame([['type' => 'thinking_level_changed', 'level' => 'high']], $this->of('thinking_level_changed'));
    }

    // ---- compaction --------------------------------------------------------------------

    public function testSetAutoCompactionIsRememberedInTheSettings(): void
    {
        $settings = Settings::inMemory();
        $this->start(settings: $settings);

        $this->assertTrue($this->response(['type' => 'set_auto_compaction', 'enabled' => false])['success']);
        $this->assertFalse($settings->compactionEnabled());
        $this->assertFalse($this->data(['type' => 'get_state'])['autoCompactionEnabled']);
    }

    public function testCompactingAConversationTooSmallToCompactIsAnError(): void
    {
        $this->start();
        $response = $this->response(['type' => 'compact']);

        // `compact()` throws rather than returning null here, so the host is told why
        // instead of being handed a cancellation it did not ask for.
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('too small', $response['error']);
    }

    public function testCompactAnswersUpstreamsCompactionResultAndTheEventsSayItHappened(): void
    {
        $hooks = $this->hooks(['session_before_compact' => static fn (\Pig\CodingAgent\Hooks\Events\SessionBeforeCompactEvent $event) => new \Pig\CodingAgent\Hooks\Results\SessionBeforeCompactResult(
            compaction: new \Pig\CodingAgent\Session\CompactionResult('a hook wrote this', $event->preparation->firstKeptEntryId, 1234, details: ['readFiles' => ['a.php'], 'modifiedFiles' => []]),
        )]);
        $this->start(hooks: $hooks);

        $filler = str_repeat('a lot has been said about this already. ', 400);
        $messages = [];
        for ($turn = 0; $turn < 8; $turn++) {
            $messages[] = new UserMessage("question {$turn}: {$filler}");
            $messages[] = self::message("answer {$turn}: {$filler}");
        }
        $this->session->restore($messages);
        $this->lines();

        $this->send(['type' => 'compact']);
        $lines = $this->lines();
        $response = $this->of('response', $lines)[0];
        $this->assertTrue($response['success'], $response['error'] ?? '');
        $result = $response['data'];
        $this->assertSame('a hook wrote this', $result['summary']);
        $this->assertSame(1234, $result['tokensBefore']);
        $this->assertIsString($result['firstKeptEntryId']);
        $this->assertIsInt($result['estimatedTokensAfter']);
        $this->assertSame(['a.php'], $result['details']['readFiles']);

        // `compaction_start` / `compaction_end`, for `manual` too — `auto_compaction_*` was overflow only.
        $this->assertSame([['type' => 'compaction_start', 'reason' => 'manual']], $this->of('compaction_start', $lines));
        $end = $this->of('compaction_end', $lines)[0];
        $this->assertSame('manual', $end['reason']);
        $this->assertSame($result, $end['result']);
        $this->assertFalse($end['aborted']);
        $this->assertArrayNotHasKey('errorMessage', $end);
    }

    public function testCompactionEventsAreUpstreams(): void
    {
        $this->start();
        $this->assertSame(['type' => 'compaction_start', 'reason' => 'threshold'], RpcEvents::encode(new \Pig\CodingAgent\Session\CompactionStartEvent('threshold')));

        $failed = RpcEvents::encode(new \Pig\CodingAgent\Session\CompactionEndEvent('overflow', null, false, false, 'Context overflow recovery failed: no'));
        $this->assertSame(['type' => 'compaction_end', 'reason' => 'overflow', 'aborted' => false, 'willRetry' => false, 'errorMessage' => 'Context overflow recovery failed: no'], $failed);

        $retry = RpcEvents::encode(new \Pig\CodingAgent\Session\RetryStartEvent(1, 3, 2.0, '503'));
        $this->assertSame(['type' => 'auto_retry_start', 'attempt' => 1, 'maxAttempts' => 3, 'delayMs' => 2000, 'errorMessage' => '503'], $retry);
        $this->assertSame(['type' => 'auto_retry_end', 'success' => false, 'attempt' => 3, 'finalError' => '503'], RpcEvents::encode(new \Pig\CodingAgent\Session\RetryEndEvent(false, 3, '503')));
        $this->assertSame(['type' => 'auto_retry_end', 'success' => true, 'attempt' => 1], RpcEvents::encode(new \Pig\CodingAgent\Session\RetryEndEvent(true, 1)));
    }

    // ---- bash --------------------------------------------------------------------------

    public function testBashAnswersWithUpstreamsBashResult(): void
    {
        $this->start();
        $result = $this->data(['type' => 'bash', 'command' => 'echo hello']);

        // `BashResult`: `{output, exitCode, cancelled, truncated, fullOutputPath?}`, and no more.
        $this->assertSame(['output', 'exitCode', 'cancelled', 'truncated'], array_keys($result));
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('hello', $result['output']);
    }

    public function testBashExcludedFromContextIsKeptButNotSentToTheModel(): void
    {
        $this->start();
        $this->send(['type' => 'bash', 'command' => 'echo hello', 'excludeFromContext' => true]);

        // Upstream records it either way: in the session, flagged, and left out of the request.
        $messages = $this->data(['type' => 'get_messages'])['messages'];
        $last = end($messages);
        $this->assertSame('bashExecution', $last['role']);
        $this->assertTrue($last['excludeFromContext']);
        $held = $this->session->messages();
        $this->assertSame([], \Pig\CodingAgent\CodingAgent::toLlm([end($held)]));
    }

    /**
     * A host draws a `!command` as it runs, the way the terminal does. Without these a
     * `flutter run` shows nothing until it is stopped, and nothing is what a hang looks like.
     */
    public function testBashStreamsItsOutputAsEventsCarryingTheCommandsId(): void
    {
        $this->start();
        $this->lines();
        $this->send(['type' => 'bash', 'id' => 'b1', 'command' => 'echo one; echo two']);
        $lines = $this->lines();

        $events = $this->of('bash_execution_update', $lines);
        $this->assertNotEmpty($events, 'expected at least one bash_execution_update event');
        foreach ($events as $event) {
            $this->assertSame('b1', $event['id'], 'the event names the command it belongs to');
        }
        // Deltas, which put together are the output.
        $this->assertSame("one\ntwo\n", implode('', array_column($events, 'delta')));

        $response = $this->of('response', $lines)[0];
        $this->assertSame('b1', $response['id']);
        $this->assertTrue($response['success']);
    }

    public function testAbortBashWithNoBashRunningIsStillASuccess(): void
    {
        $this->start();

        $this->assertTrue($this->response(['type' => 'abort_bash'])['success']);
    }

    // ---- the session -------------------------------------------------------------------

    public function testGetEntriesListsEveryEntryAndSinceIsACursor(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $all = $this->data(['type' => 'get_entries']);
        // The system message, the question and the answer; the header is not an entry.
        $this->assertSame(['message', 'message', 'message'], array_column($all['entries'], 'type'));
        $this->assertSame(end($all['entries'])['id'], $all['leafId']);

        $this->send(['type' => 'prompt', 'message' => 'second']);
        $since = $this->data(['type' => 'get_entries', 'since' => $all['leafId']]);
        $this->assertCount(2, $since['entries']);
        $this->assertSame('second', $since['entries'][0]['message']['content'][0]['text']);

        $lost = $this->response(['type' => 'get_entries', 'since' => 'nope0000']);
        $this->assertFalse($lost['success']);
        $this->assertSame('Entry not found: nope0000', $lost['error']);
    }

    public function testGetTreeIsUpstreamsNodes(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $tree = $this->data(['type' => 'get_tree']);
        $root = $tree['tree'][0];
        $this->assertSame(['entry', 'children'], array_keys($root));
        $this->assertNull($root['entry']['parentId']);
        $this->assertSame($root['entry']['id'], $root['children'][0]['entry']['parentId']);
        $this->assertSame($tree['leafId'], $root['children'][0]['children'][0]['entry']['id']);
    }

    public function testGoingBackMovesTheLeafAndSaysSo(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $this->send(['type' => 'prompt', 'message' => 'second']);

        $entries = $this->data(['type' => 'get_entries'])['entries'];
        // The first answer: behind it the system message and the first question
        // (upstream's `messageCount: session.messages.length` counts the system message).
        $jump = $this->data(['type' => 'navigate_tree', 'targetId' => $entries[2]['id']]);

        $this->assertSame(['cancelled' => false], $jump);
        $this->assertSame(3, $this->data(['type' => 'get_state'])['messageCount']);

        // Back to something somebody said goes back to before it, and hands the words back.
        $words = $this->data(['type' => 'navigate_tree', 'targetId' => $entries[1]['id']]);
        $this->assertSame('first', $words['editorText']);
    }

    public function testGoingBackInASessionThatIsNotSavedIsAnError(): void
    {
        $this->start();
        $response = $this->response(['type' => 'navigate_tree', 'targetId' => 'nope']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('not being saved', $response['error']);
    }

    public function testANewSessionWritesToANewFileAndForgetsTheOldOne(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $this->assertSame(['cancelled' => false], $this->data(['type' => 'new_session', 'parentSession' => $first]));
        $second = $this->data(['type' => 'get_state'])['sessionFile'];
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $this->data(['type' => 'get_state'])['messageCount']);

        // Said after a prompt, because a session file is written when there is something to
        // write: counting them before the new one has a message would count one either way
        // and prove nothing. The bug this guards is the one `writeTo()` fixed — with the
        // store fixed at startup the second conversation was appended to the first file, and
        // `--continue` found them spliced into one.
        $this->send(['type' => 'prompt', 'message' => 'second']);
        $this->assertCount(2, SessionManager::listFor($this->cwd));
        // Each file: its system message, the question and the answer.
        $this->assertCount(3, SessionManager::open($first)->messages());
        $this->assertCount(3, SessionManager::open($second)->messages());
        // `parentSession`, written on the new header for a host tracking lineage.
        $this->assertSame($first, SessionManager::open($second)->parentSession);
    }

    public function testForkingFromAMessageMovesToANewFileAndHandsItsWordsBack(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $this->send(['type' => 'prompt', 'message' => 'second']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $messages = $this->data(['type' => 'get_fork_messages'])['messages'];
        $this->assertSame(['first', 'second'], array_column($messages, 'text'));

        $fork = $this->data(['type' => 'fork', 'entryId' => $messages[1]['entryId']]);

        // Upstream's answer: the message's words for the host's prompt, and not cancelled.
        $this->assertSame(['text' => 'second', 'cancelled' => false], $fork);

        $state = $this->data(['type' => 'get_state']);
        $this->assertNotSame($first, $state['sessionFile']);
        // The system message, the first question and its answer — up to before the second.
        $this->assertSame(3, $state['messageCount']);
        $this->assertSame($first, SessionManager::open($state['sessionFile'])->parentSession);
        $this->assertCount(5, SessionManager::open($first)->messages(), 'the original keeps everything');
    }

    public function testCloningCopiesTheConversationAsItStandsIntoANewFile(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $this->assertSame(['cancelled' => false], $this->data(['type' => 'clone']));

        $state = $this->data(['type' => 'get_state']);
        $this->assertNotSame($first, $state['sessionFile']);
        $this->assertSame(3, $state['messageCount']);
        $this->assertCount(3, SessionManager::open($state['sessionFile'])->messages());
    }

    public function testCloningBeforeAnythingWasSaidIsAnErrorAndForkingNothingIsOne(): void
    {
        $this->start(answers: [], store: true);

        $this->assertFalse($this->response(['type' => 'clone'])['success']);
        $this->assertFalse($this->response(['type' => 'fork', 'entryId' => 'nope0000'])['success']);
    }

    public function testSwitchingSessionsRestoresTheOtherConversation(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $this->send(['type' => 'new_session']);
        $this->assertSame(['cancelled' => false], $this->data(['type' => 'switch_session', 'sessionPath' => $first]));

        $state = $this->data(['type' => 'get_state']);
        $this->assertSame($first, $state['sessionFile']);
        $this->assertSame(3, $state['messageCount']);
        // Behind the system message the conversation starts with.
        $this->assertSame('first', $this->data(['type' => 'get_messages'])['messages'][1]['content'][0]['text']);
    }

    public function testExportWritesTheHtmlBesideTheSession(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $path = $this->data(['type' => 'export_html'])['path'];
        $this->assertFileExists($path);
        $this->assertStringContainsString('pig-session-', $path);
        $this->assertStringContainsString('first', (string) file_get_contents($path));
    }

    public function testExportHonoursAnOutputPath(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $wanted = $this->cwd . '/somewhere.html';
        $this->assertSame($wanted, $this->data(['type' => 'export_html', 'outputPath' => $wanted])['path']);
        $this->assertFileExists($wanted);
    }

    public function testExportingASessionThatIsNotSavedIsAnError(): void
    {
        $this->start();

        $this->assertFalse($this->response(['type' => 'export_html'])['success']);
    }

    // ---- hooks over the wire -----------------------------------------------------------

    public function testAHookThatAsksParksUntilTheAnswerArrives(): void
    {
        $asked = [];
        $hooks = $this->hooks(['tool_call' => function (mixed $event, mixed $ctx) use (&$asked): mixed {
            $asked[] = $ctx->ui->confirm('Let it read?', 'a file');

            return null;
        }]);

        $this->start(hooks: $hooks);
        file_put_contents($this->cwd . '/f.txt', "x\n");

        // The tool is called directly rather than through a turn: what is under test is the
        // parking, and a scripted model answer would only stand between them.
        $reading = Async::spawn(fn () => $this->tools[0]->execute(
            'call-1',
            ['path' => $this->cwd . '/f.txt'],
            null,
        ));

        $this->settle();
        $request = $this->of('extension_ui_request')[0];

        $this->assertSame('confirm', $request['method']);
        $this->assertSame('Let it read?', $request['title']);
        $this->assertIsString($request['id']);
        $this->assertSame([], $asked, 'the handler must still be parked');
        $this->assertFalse($reading->isComplete());

        $this->send(['type' => 'extension_ui_response', 'id' => $request['id'], 'confirmed' => true]);

        $this->assertSame([true], $asked);
        $this->assertTrue($reading->isComplete());
    }

    public function testAnAnswerOfNoComesBackAsFalse(): void
    {
        $answers = [];
        $hooks = $this->hooks(['tool_call' => function (mixed $event, mixed $ctx) use (&$answers): mixed {
            $answers[] = $ctx->ui->confirm('ok?', '');

            return null;
        }]);

        $this->start(hooks: $hooks);
        file_put_contents($this->cwd . '/f.txt', "x\n");

        Async::spawn(fn () => $this->tools[0]->execute(
            'call-1',
            ['path' => $this->cwd . '/f.txt'],
            null,
        ));
        $this->settle();

        $id = $this->of('extension_ui_request')[0]['id'];
        $this->send(['type' => 'extension_ui_response', 'id' => $id, 'cancelled' => true]);

        // Walking away from the question is a no, which is what makes a `tool_call` guard
        // safe against a host that does not implement the dialogs at all.
        $this->assertSame([false], $answers);
    }

    public function testASelectSendsItsOptionsAndTakesTheValueBack(): void
    {
        $chosen = [];
        $hooks = $this->hooks(['tool_call' => function (mixed $event, mixed $ctx) use (&$chosen): mixed {
            $chosen[] = $ctx->ui->select('Which?', ['a', 'b']);

            return null;
        }]);

        $this->start(hooks: $hooks);
        file_put_contents($this->cwd . '/f.txt', "x\n");

        Async::spawn(fn () => $this->tools[0]->execute(
            'call-1',
            ['path' => $this->cwd . '/f.txt'],
            null,
        ));
        $this->settle();

        $request = $this->of('extension_ui_request')[0];
        $this->assertSame(['a', 'b'], $request['options']);

        $this->send(['type' => 'extension_ui_response', 'id' => $request['id'], 'value' => 'b']);
        $this->assertSame(['b'], $chosen);
    }

    /** The same wire as `PrintModeTest::testAHookGetsTheSessionsKeyHereToo`, through this mode. */
    public function testAHookGetsTheSessionsKeyHereToo(): void
    {
        $key = 'not asked';
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx) use (&$key): mixed {
            $key = $ctx->apiKey($ctx->model);

            return null;
        }]);

        $this->start(hooks: $hooks);
        $this->settle();

        $this->assertSame('k', $key);
        $this->assertSame([], $this->of('extension_error'));
    }

    public function testTwoQuestionsAtOnceAreAnsweredByIdInAnyOrder(): void
    {
        $seen = [];
        $hooks = $this->hooks(['session_start' => function (mixed $event, mixed $ctx) use (&$seen): mixed {
            Async::spawn(function () use ($ctx, &$seen): void {
                $seen['first'] = $ctx->ui->input('first');
            });
            Async::spawn(function () use ($ctx, &$seen): void {
                $seen['second'] = $ctx->ui->input('second');
            });

            return null;
        }]);

        $this->start(hooks: $hooks);
        $this->settle();

        $requests = $this->of('extension_ui_request');
        $this->assertCount(2, $requests);

        // Backwards on purpose: a host is not a keyboard, and there is no "one dialog at a
        // time" to make the order matter. The id is what pairs them up.
        $this->send(['type' => 'extension_ui_response', 'id' => $requests[1]['id'], 'value' => 'B']);
        $this->send(['type' => 'extension_ui_response', 'id' => $requests[0]['id'], 'value' => 'A']);

        $this->assertSame(['second' => 'B', 'first' => 'A'], $seen);
    }

    public function testAnAnswerNobodyIsWaitingForIsDropped(): void
    {
        $this->start();
        $this->send(['type' => 'extension_ui_response', 'id' => 'ui-99', 'value' => 'x']);

        // Not a response either: a reply is not a command, so nothing is owed to it.
        $this->assertSame([], $this->lines());
    }

    public function testSystemNotifyIsToldToTheHostAndWaitsForNothing(): void
    {
        // pig's own: the page shows it to someone looking elsewhere; another host lets it go by.
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx): mixed {
            $ctx->ui->systemNotify('Done · pig', 'Task finished (3s)');

            return null;
        }]);

        $this->start(hooks: $hooks);
        $requests = $this->of('extension_ui_request');

        $this->assertSame('systemNotify', $requests[0]['method']);
        $this->assertSame('Done · pig', $requests[0]['title']);
        $this->assertSame('Task finished (3s)', $requests[0]['body']);
    }

    public function testNotifyIsOneWayAndWaitsForNothing(): void
    {
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx): mixed {
            $ctx->ui->notify('loaded', 'warning');
            $ctx->ui->setStatus('mine', 'busy');

            return null;
        }]);

        $this->start(hooks: $hooks);
        $requests = $this->of('extension_ui_request');

        $this->assertSame('notify', $requests[0]['method']);
        $this->assertSame('warning', $requests[0]['notifyType']);
        $this->assertSame('setStatus', $requests[1]['method']);
        $this->assertSame('mine', $requests[1]['statusKey']);
        // Upstream gives the told-not-asked ones an id too.
        $this->assertIsString($requests[0]['id']);
        $this->assertNotSame($requests[0]['id'], $requests[1]['id']);
    }

    public function testAWidgetAndATitleGoOutUnderUpstreamsNamesAndTheTuiOnlyOnesSayNothing(): void
    {
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx): mixed {
            $ctx->ui->setWidget('w', ['one line'], ['placement' => 'below']);
            $ctx->ui->setTitle('pig');
            $ctx->ui->setWorkingMessage('busy');
            $ctx->ui->setWorkingVisible(false);
            $ctx->ui->setHiddenThinkingLabel('hmm');
            $ctx->ui->setToolsExpanded(true);
            $ctx->ui->pasteToEditor('pasted');

            return null;
        }]);

        $this->start(hooks: $hooks);
        $requests = $this->of('extension_ui_request');

        $this->assertSame(['setWidget', 'setTitle', 'set_editor_text'], array_column($requests, 'method'));
        $this->assertSame('w', $requests[0]['widgetKey']);
        $this->assertSame(['one line'], $requests[0]['widgetLines']);
        $this->assertSame('belowEditor', $requests[0]['widgetPlacement']);
        $this->assertSame('pasted', $requests[2]['text']);
    }

    public function testADialogWithATimeoutSaysSoAndAnswersNoWhenItRunsOut(): void
    {
        $answers = [];
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx) use (&$answers): mixed {
            Async::spawn(static function () use ($ctx, &$answers): void {
                $answers[] = $ctx->ui->confirm('quick?', 'or not', 80);
            });

            return null;
        }]);

        $this->start(hooks: $hooks);
        $this->settle(3);
        $this->assertSame(80, $this->of('extension_ui_request')[0]['timeout']);
        $this->assertSame([], $answers, 'still waiting');

        for ($i = 0; $i < 40 && $answers === []; $i++) {
            $this->settle(5, 0.01);
        }
        $this->assertSame([false], $answers);
    }

    public function testCustomDrawsNothingBecauseThereIsNothingToDrawOn(): void
    {
        $drawn = [];
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx) use (&$drawn): mixed {
            $drawn[] = $ctx->ui->custom(static fn (): mixed => throw new RuntimeException('never built'));
            $drawn[] = $ctx->ui->getEditorText();

            return null;
        }]);

        $this->start(hooks: $hooks);

        $this->assertSame([null, ''], $drawn);
        $this->assertSame([], $this->of('extension_ui_request'));
    }

    public function testTheWebShellsThemeIsTheChildsBeforeAnyExtensionRuns(): void
    {
        $seen = [];
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx) use (&$seen): mixed {
            $seen['accent'] = $ctx->ui->theme()->getFgAnsi('accent');
            $seen['themes'] = count($ctx->ui->getAllThemes());
            $seen['switched'] = $ctx->ui->setTheme('dark');

            return null;
        }]);

        putenv('PIG_WEB_THEME=light');
        try {
            $this->start(hooks: $hooks);

            $this->assertSame(Themes::getThemeByName('light')?->getFgAnsi('accent'), $seen['accent'], 'the page\'s theme, from session_start on');
            $this->assertGreaterThan(1, $seen['themes']);
            $this->assertSame(['success' => true], $seen['switched']);
            $told = $this->of('extension_ui_request');
            $this->assertSame([['setTheme', 'dark']], array_map(static fn (array $r): array => [$r['method'], $r['theme']], $told));
        } finally {
            putenv('PIG_WEB_THEME');
            TerminalImage::setCapabilityOverrides([]);
            Themes::initTheme('dark');
        }
    }

    public function testSetThemeIsTheHostSayingWhichThemeItDraws(): void
    {
        $this->start();
        try {
            $response = $this->response(['id' => '1', 'type' => 'set_theme', 'theme' => 'light']);
            $this->assertTrue($response['success']);
            $this->assertArrayNotHasKey('data', $response);
            $this->assertSame(Themes::getThemeByName('light')?->getFgAnsi('accent'), Themes::theme()->getFgAnsi('accent'));

            $unknown = $this->response(['id' => '2', 'type' => 'set_theme', 'theme' => 'no-such-theme']);
            $this->assertFalse($unknown['success']);
            $this->assertStringContainsString("No theme called 'no-such-theme'", $unknown['error']);
            $this->assertSame(Themes::getThemeByName('light')?->getFgAnsi('accent'), Themes::theme()->getFgAnsi('accent'), 'kept');

            $this->assertStringContainsString("'theme' is required", $this->response(['type' => 'set_theme'])['error']);
        } finally {
            TerminalImage::setCapabilityOverrides([]);
            Themes::initTheme('dark');
        }
    }

    public function testAHookThatThrowsIsReportedOnTheWireRatherThanKillingTheSession(): void
    {
        $hooks = $this->hooks(['session_start' => static function (): mixed {
            throw new RuntimeException('bad hook');
        }]);

        $this->start(hooks: $hooks);
        $errors = $this->of('extension_error');

        $this->assertSame('test.php', $errors[0]['extensionPath']);
        $this->assertSame('session_start', $errors[0]['event']);
        $this->assertStringContainsString('bad hook', $errors[0]['error']);
        $this->assertTrue($this->response(['type' => 'get_state'])['success']);
    }

    public function testAHookThatBlocksAToolStillBlocksItWithNoTerminalInSight(): void
    {
        $hooks = $this->hooks(['tool_call' => static fn (): mixed => throw new RuntimeException('no reading')]);
        $this->start(hooks: $hooks);
        file_put_contents($this->cwd . '/f.txt', "x\n");

        $reading = Async::spawn(fn () => $this->tools[0]->execute(
            'call-1',
            ['path' => $this->cwd . '/f.txt'],
            null,
        ));
        $this->settle();

        $this->assertTrue($reading->isComplete());
        $this->assertStringContainsString('Blocked', $this->of('extension_error')[0]['error'] ?? 'Blocked');
    }

    // ---- custom tools ------------------------------------------------------------------

    public function testACustomToolIsToldTheSessionStartedAndCanReachTheWireUi(): void
    {
        $told = [];
        $tool = new CustomTool(
            name: 'noop',
            label: 'Noop',
            description: 'Does nothing.',
            parameters: ['type' => 'object', 'properties' => [], 'required' => []],
            execute: static fn (): mixed => null,
            onSession: static function (CustomToolSessionEvent $event) use (&$told): void {
                $told[] = $event->reason;
            },
        );

        $this->start(customTools: new CustomToolSet([new LoadedCustomTool('t/index.php', 't', $tool)]));

        $this->assertSame(['start'], $told);
    }

    public function testABrokenCustomToolIsReportedOnTheWire(): void
    {
        $tool = new CustomTool(
            name: 'noop',
            label: 'Noop',
            description: 'Does nothing.',
            parameters: ['type' => 'object', 'properties' => [], 'required' => []],
            execute: static fn (): mixed => null,
            onSession: static function (): void {
                throw new RuntimeException('cannot start');
            },
        );

        $this->start(customTools: new CustomToolSet([new LoadedCustomTool('t/index.php', 't', $tool)]));
        $errors = $this->of('tool_error');

        $this->assertSame('t/index.php', $errors[0]['path']);
        $this->assertStringContainsString('cannot start', $errors[0]['error']);
    }

    public function testSessionInfoChangedEventIsEmittedOverRpc(): void
    {
        $this->start(answers: ['pong'], store: true);
        $this->session->setSessionName('自动总结标题测试');

        $events = $this->of('session_info_changed');
        $this->assertCount(1, $events);
        $this->assertSame('自动总结标题测试', $events[0]['name']);
    }

    public function testSetSessionNameNamesItAndAnEmptyNameIsRefused(): void
    {
        $this->start(store: true);

        $this->assertTrue($this->response(['type' => 'set_session_name', 'name' => '  my work  '])['success']);
        $this->assertSame('my work', $this->data(['type' => 'get_state'])['sessionName']);

        $empty = $this->response(['type' => 'set_session_name', 'name' => '   ']);
        $this->assertFalse($empty['success']);
        $this->assertSame('Session name cannot be empty', $empty['error']);
    }

    public function testAHooksEntryIsAnnouncedAsAppended(): void
    {
        $this->start(store: true);
        $this->session->appendHookEntry('mine', ['n' => 1]);

        $appended = $this->of('entry_appended');
        $this->assertCount(1, $appended);
        $this->assertSame('custom', $appended[0]['entry']['type']);
        $this->assertSame('mine', $appended[0]['entry']['customType']);
        $this->assertSame($this->session->store()?->leaf(), $appended[0]['entry']['id']);
    }

    public function testNewSessionStartsUnpersistedAndPersistsAfterFirstTurn(): void
    {
        $this->start(answers: ['first reply'], store: true);

        // Before any turn, isPersisted is false and no file exists on disk
        $state = $this->data(['type' => 'get_state']);
        $this->assertFalse($state['isPersisted']);

        // After the first turn, file is created and isPersisted becomes true
        $this->send(['type' => 'prompt', 'message' => 'hello']);
        $afterState = $this->data(['type' => 'get_state']);
        $this->assertTrue($afterState['isPersisted']);
    }

    // ---- the fixtures ------------------------------------------------------------------

    /** @param array<string, callable> $handlers */
    private function hooks(array $handlers): HookRunner
    {
        $api = new HookApi('.', 'test.php');

        foreach ($handlers as $event => $handler) {
            $api->on($event, $handler);
        }

        return new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
    }
}
