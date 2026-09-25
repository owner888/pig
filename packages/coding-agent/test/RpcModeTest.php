<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
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
use Pig\CodingAgent\Rpc\RpcMode;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
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

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-rpc-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->cwd . '-home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->mode->stop();
        putenv('PIG_HOME');
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
     * @param list<string> $answers
     */
    private function start(
        array $answers = [],
        bool $reasoning = false,
        bool $store = false,
        ?string $resume = null,
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
        ?Settings $settings = null,
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
        ));

        $tools = [...ToolSet::create($this->cwd, ['read']), ...($customTools?->agentTools() ?? [])];
        $this->tools = $hooks === null ? $tools : HookedTool::wrap($tools, $hooks);
        $agent->setTools($this->tools);

        $saved = match (true) {
            $resume !== null => SessionManager::open($resume),
            $store => SessionManager::create($this->cwd),
            default => null,
        };

        $this->session = new AgentSession($agent, $this->cwd, $saved, null, $hooks);

        if ($resume !== null && $saved !== null) {
            $this->session->restore($saved->messages());
        }

        $this->mode = new RpcMode(
            $this->session,
            $this->cwd,
            $hooks,
            $customTools,
            $settings ?? Settings::inMemory(),
            in: $this->in,
            out: $this->out,
        );

        $this->mode->start();
    }

    private function provider(Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();

        Async::spawn(static function () use ($stream, $text): void {
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
        $this->assertFalse($state['isBashRunning']);
        $this->assertSame(0, $state['messageCount']);
        $this->assertStringEndsWith('.jsonl', $state['sessionFile']);
    }

    public function testAnUnsavedSessionHasNoSessionFileRatherThanAMadeUpOne(): void
    {
        $this->start();

        $this->assertNull($this->data(['type' => 'get_state'])['sessionFile']);
    }

    public function testGetSessionStatsCountsWhatHappened(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $stats = $this->data(['type' => 'get_session_stats']);
        $this->assertSame(1, $stats['userMessages']);
        $this->assertSame(1, $stats['assistantMessages']);
        $this->assertSame(2, $stats['totalMessages']);
    }

    public function testGetLastAssistantTextIsTheAnswerWithoutTheEvents(): void
    {
        $this->start(answers: ['the sky is blue']);
        $this->send(['type' => 'prompt', 'message' => 'why']);

        $this->assertSame('the sky is blue', $this->data(['type' => 'get_last_assistant_text'])['text']);
    }

    public function testGetMessagesRoundTripsThroughTheSessionCodec(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $messages = $this->data(['type' => 'get_messages'])['messages'];

        // The same shape the session file holds, because it is the same encoder. A host
        // reading these and a `--continue` reading the file see one conversation.
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('hi', $messages[0]['content'][0]['text']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('hello', $messages[1]['content'][0]['text']);
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
        $this->assertArrayNotHasKey('data', $lines[0]);

        $order = array_column($lines, 'type');
        $this->assertContains('agent_start', $order);
        $this->assertContains('message_start', $order);
        $this->assertContains('message_end', $order);
        $this->assertContains('agent_end', $order);
        $this->assertLessThan(
            array_search('agent_end', $order, true),
            array_search('message_start', $order, true),
        );
    }

    public function testEveryUpdateCarriesTheWholeMessageSoFarBesideTheDelta(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $updates = $this->of('message_update');
        $this->assertNotSame([], $updates);

        foreach ($updates as $update) {
            $this->assertSame('assistant', $update['message']['role']);
            $this->assertIsString($update['delta']['type']);
        }
    }

    public function testAnEventTypeIsTheClassNameInSnakeCaseWithoutTheSuffix(): void
    {
        $this->start(answers: ['hello']);
        $this->send(['type' => 'prompt', 'message' => 'hi']);

        $deltas = array_column(array_column($this->of('message_update'), 'delta'), 'type');
        $this->assertContains('text_start', $deltas);
        $this->assertContains('text_delta', $deltas);
        $this->assertContains('text_end', $deltas);
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
        $this->assertTrue($this->response(['type' => 'steer', 'message' => 'a'])['success']);
        $this->assertTrue($this->response(['type' => 'follow_up', 'message' => 'b'])['success']);
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

    public function testAModelThatDoesNotExistIsAnErrorNotASilentNoChange(): void
    {
        $this->start();
        $response = $this->response(['type' => 'set_model', 'modelId' => 'gpt-9']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('gpt-9', $response['error']);
        $this->assertSame('claude-test', $this->data(['type' => 'get_state'])['model']['id']);
    }

    public function testAThinkingLevelAModelCannotReachIsRefused(): void
    {
        $this->start(reasoning: false);
        $response = $this->response(['type' => 'set_thinking_level', 'level' => 'high']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('cannot think', $response['error']);
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

    // ---- bash --------------------------------------------------------------------------

    public function testBashAnswersWithTheExecutionAsTheCodecWritesIt(): void
    {
        $this->start();
        $execution = $this->data(['type' => 'bash', 'command' => 'echo hello']);

        // `role`, not a `type` of its own: the codec gives every entry a role and a bash
        // execution is one of them, which is what lets a host render the session file and
        // this answer with the same code.
        $this->assertSame('bashExecution', $execution['role']);
        $this->assertSame('echo hello', $execution['command']);
        $this->assertSame(0, $execution['exitCode']);
        $this->assertStringContainsString('hello', $execution['output']);
    }

    public function testBashCanBeToldNotToJoinTheConversation(): void
    {
        $this->start();
        $this->send(['type' => 'bash', 'command' => 'echo hello', 'remember' => false]);

        $this->assertSame(0, $this->data(['type' => 'get_state'])['messageCount']);
    }

    public function testAbortBashWithNoBashRunningIsStillASuccess(): void
    {
        $this->start();

        $this->assertTrue($this->response(['type' => 'abort_bash'])['success']);
    }

    // ---- the session -------------------------------------------------------------------

    public function testGetBranchNamesEveryPointThatCouldBeGoneBackTo(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $this->send(['type' => 'prompt', 'message' => 'second']);

        $points = $this->data(['type' => 'get_branch'])['points'];
        $this->assertCount(4, $points);
        $this->assertIsString($points[0]['entryId']);
        $this->assertSame('first', $points[0]['message']['content'][0]['text']);
    }

    public function testGoingBackMovesTheLeafAndSaysSo(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $this->send(['type' => 'prompt', 'message' => 'second']);

        $points = $this->data(['type' => 'get_branch'])['points'];
        $jump = $this->data(['type' => 'go_to', 'entryId' => $points[1]['entryId']]);

        $this->assertTrue($jump['moved']);
        $this->assertFalse($jump['aborted']);
        $this->assertNull($jump['summary']);
        $this->assertSame(2, $this->data(['type' => 'get_state'])['messageCount']);
    }

    public function testGoingBackInASessionThatIsNotSavedIsAnError(): void
    {
        $this->start();
        $response = $this->response(['type' => 'go_to', 'entryId' => 'nope']);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('not being saved', $response['error']);
    }

    public function testANewSessionWritesToANewFileAndForgetsTheOldOne(): void
    {
        $this->start(answers: ['one', 'two'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $second = $this->data(['type' => 'new_session'])['sessionFile'];
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $this->data(['type' => 'get_state'])['messageCount']);

        // Said after a prompt, because a session file is written when there is something to
        // write: counting them before the new one has a message would count one either way
        // and prove nothing. The bug this guards is the one `writeTo()` fixed — with the
        // store fixed at startup the second conversation was appended to the first file, and
        // `--continue` found them spliced into one.
        $this->send(['type' => 'prompt', 'message' => 'second']);
        $this->assertCount(2, SessionManager::listFor($this->cwd));
        $this->assertCount(2, SessionManager::open($first)->messages());
        $this->assertCount(2, SessionManager::open($second)->messages());
    }

    public function testSwitchingSessionsRestoresTheOtherConversation(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);
        $first = $this->data(['type' => 'get_state'])['sessionFile'];

        $this->send(['type' => 'new_session']);
        $back = $this->data(['type' => 'switch_session', 'sessionPath' => $first]);

        $this->assertSame($first, $back['sessionFile']);
        $this->assertSame(2, $back['messageCount']);
        $this->assertSame('first', $this->data(['type' => 'get_messages'])['messages'][0]['content'][0]['text']);
    }

    public function testExportWritesTheHtmlBesideTheSession(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $path = $this->data(['type' => 'export'])['path'];
        $this->assertFileExists($path);
        $this->assertStringContainsString('pig-session-', $path);
        $this->assertStringContainsString('first', (string) file_get_contents($path));
    }

    public function testExportHonoursAnOutputPath(): void
    {
        $this->start(answers: ['one'], store: true);
        $this->send(['type' => 'prompt', 'message' => 'first']);

        $wanted = $this->cwd . '/somewhere.html';
        $this->assertSame($wanted, $this->data(['type' => 'export', 'outputPath' => $wanted])['path']);
        $this->assertFileExists($wanted);
    }

    public function testExportingASessionThatIsNotSavedIsAnError(): void
    {
        $this->start();

        $this->assertFalse($this->response(['type' => 'export'])['success']);
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
        $request = $this->of('hook_ui_request')[0];

        $this->assertSame('confirm', $request['method']);
        $this->assertSame('Let it read?', $request['title']);
        $this->assertIsString($request['id']);
        $this->assertSame([], $asked, 'the handler must still be parked');
        $this->assertFalse($reading->isComplete());

        $this->send(['type' => 'hook_ui_response', 'id' => $request['id'], 'confirmed' => true]);

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

        $id = $this->of('hook_ui_request')[0]['id'];
        $this->send(['type' => 'hook_ui_response', 'id' => $id, 'cancelled' => true]);

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

        $request = $this->of('hook_ui_request')[0];
        $this->assertSame(['a', 'b'], $request['options']);

        $this->send(['type' => 'hook_ui_response', 'id' => $request['id'], 'value' => 'b']);
        $this->assertSame(['b'], $chosen);
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

        $requests = $this->of('hook_ui_request');
        $this->assertCount(2, $requests);

        // Backwards on purpose: a host is not a keyboard, and there is no "one dialog at a
        // time" to make the order matter. The id is what pairs them up.
        $this->send(['type' => 'hook_ui_response', 'id' => $requests[1]['id'], 'value' => 'B']);
        $this->send(['type' => 'hook_ui_response', 'id' => $requests[0]['id'], 'value' => 'A']);

        $this->assertSame(['second' => 'B', 'first' => 'A'], $seen);
    }

    public function testAnAnswerNobodyIsWaitingForIsDropped(): void
    {
        $this->start();
        $this->send(['type' => 'hook_ui_response', 'id' => 'ui-99', 'value' => 'x']);

        // Not a response either: a reply is not a command, so nothing is owed to it.
        $this->assertSame([], $this->lines());
    }

    public function testNotifyIsOneWayAndWaitsForNothing(): void
    {
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx): mixed {
            $ctx->ui->notify('loaded', 'warning');
            $ctx->ui->setStatus('mine', 'busy');

            return null;
        }]);

        $this->start(hooks: $hooks);
        $requests = $this->of('hook_ui_request');

        $this->assertSame('notify', $requests[0]['method']);
        $this->assertSame('warning', $requests[0]['level']);
        $this->assertSame('set_status', $requests[1]['method']);
        $this->assertSame('mine', $requests[1]['statusKey']);
        $this->assertArrayNotHasKey('id', $requests[0], 'nothing to answer, so nothing to answer with');
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
        $this->assertSame([], $this->of('hook_ui_request'));
    }

    public function testAHookThatThrowsIsReportedOnTheWireRatherThanKillingTheSession(): void
    {
        $hooks = $this->hooks(['session_start' => static function (): mixed {
            throw new RuntimeException('bad hook');
        }]);

        $this->start(hooks: $hooks);
        $errors = $this->of('hook_error');

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
        $this->assertStringContainsString('Blocked', $this->of('hook_error')[0]['error'] ?? 'Blocked');
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
