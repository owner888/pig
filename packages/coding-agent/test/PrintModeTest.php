<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
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
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\PrintMode;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use RuntimeException;

/**
 * Say it, print it, exit.
 *
 * Both streams are temp files, so what a script piping `bin/pig -p` would see is what the
 * assertions read. Which stream a thing lands on is half of what is under test: the answer
 * belongs on standard output and every complaint belongs on standard error, because in
 * `--mode json` a warning among the JSON lines stops whatever is parsing them.
 */
final class PrintModeTest extends TestCase
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private AgentSession $session;

    private string $cwd;

    /** @var list<string> one per model call */
    private array $answers = [];

    /** Set to make the next answer an error rather than a completion. */
    private ?string $failure = null;

    /** One error per entry, consumed before `$failure` — a turn that fails and then does not. */
    private array $failures = [];

    /** Blocks on the content of each answer. */
    private bool $thinking = false;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-print-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->cwd . '-home');
        $this->failure = null;
        $this->failures = [];
        $this->thinking = false;
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        fclose($this->out);
        fclose($this->err);
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
     * @param list<string>       $messages
     * @param list<ImageContent> $images
     * @param list<FileCommand>  $fileCommands
     * @return int the exit code
     */
    private function execute(
        array $messages,
        string $mode = 'text',
        array $images = [],
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
        bool $store = false,
        ?Settings $settings = null,
        array $fileCommands = [],
    ): int {
        $this->out = fopen('php://temp', 'w+') ?: throw new RuntimeException('no temp stream');
        $this->err = fopen('php://temp', 'w+') ?: throw new RuntimeException('no temp stream');

        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
        $agent->setModel(new Model(
            'claude-test',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            200_000,
            64_000,
            false,
        ));
        $agent->setTools([]);

        $this->session = new AgentSession(
            $agent,
            $this->cwd,
            $store ? SessionManager::create($this->cwd) : null,
            $settings,
            $hooks,
            $fileCommands,
        );

        $printing = new PrintMode($this->session, $mode, $hooks, $customTools, $this->out, $this->err);

        return (int) Async::run(static fn (): int => $printing->run($messages, $images));
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();
        $failure = array_shift($this->failures) ?? $this->failure;
        $thinking = $this->thinking;

        Async::spawn(function () use ($stream, $text, $failure, $thinking): void {
            $stream->push(new StartEvent($this->message('')));
            $stream->push(new TextStartEvent(0, $this->message('')));
            $stream->push(new TextDeltaEvent(0, $text, $this->message($text, $thinking)));
            $stream->push(new TextEndEvent(0, $text, $this->message($text, $thinking)));

            if ($failure !== null) {
                $stream->push(new ErrorEvent(
                    StopReason::Error,
                    new AssistantMessage(
                        [],
                        Api::AnthropicMessages,
                        'anthropic',
                        'claude-test',
                        new Usage(),
                        StopReason::Error,
                        $failure,
                    ),
                ));
                $stream->end();

                return;
            }

            $stream->push(new DoneEvent(StopReason::Stop, $this->message($text, $thinking)));
            $stream->end();
        });

        return $stream;
    }

    private function message(string $text, bool $thinking = false): AssistantMessage
    {
        $content = $thinking
            ? [new ThinkingContent('let me see'), new TextContent($text)]
            : [new TextContent($text)];

        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );
    }

    private function printed(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function complained(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $lines = [];

        foreach (explode("\n", $this->printed()) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "not JSON: {$line}");
            $lines[] = $decoded;
        }

        return $lines;
    }

    // ---- text ---------------------------------------------------------------------------

    public function testTheAnswerGoesToStandardOutputAndNothingElseDoes(): void
    {
        $this->answers = ['the sky is blue'];
        $code = $this->execute(['why is the sky blue?']);

        $this->assertSame(0, $code);
        $this->assertSame("the sky is blue\n", $this->printed());
        $this->assertSame('', $this->complained());
    }

    public function testOnlyTheLastAnswerIsPrinted(): void
    {
        $this->answers = ['first answer', 'second answer'];
        $this->execute(['one', 'two']);

        // Every turn happened — the conversation has both — but a script asked one question
        // at a time and wants the answer to the last one, not a transcript.
        $this->assertSame("second answer\n", $this->printed());
        $this->assertCount(4, $this->session->messages());
    }

    public function testThinkingIsNotPartOfTheAnswer(): void
    {
        $this->answers = ['the answer'];
        $this->thinking = true;
        $this->execute(['ask']);

        // The model talking to itself is not what was asked for, and a script would have to
        // strip it.
        $this->assertSame("the answer\n", $this->printed());
        $this->assertStringNotContainsString('let me see', $this->printed());
    }

    public function testAFailedTurnExitsNonZeroAndSaysWhyOnStandardError(): void
    {
        $this->answers = ['ignored'];
        $this->failure = 'the provider said no';
        $code = $this->execute(['ask']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('the provider said no', $this->complained());

        // Not on stdout: a pipe reading the answer must not be handed an error as if it
        // were one.
        $this->assertSame('', $this->printed());
    }

    public function testA503IsWaitedOutRatherThanPrintedAsTheAnswer(): void
    {
        // Auto-retry is on by default, so a 503 here is a pause and not an outcome — and this
        // mode has to be the thing that waits, because when it returns the process exits and
        // the retry goes with it.
        $this->answers = ['ignored', 'here you go'];
        $this->failures = ['Anthropic returned 503: overloaded'];
        $code = $this->execute(['ask'], settings: Settings::inMemory(['retry' => ['baseDelayMs' => 1]]));

        $this->assertSame(0, $code);
        $this->assertSame("here you go\n", $this->printed());
        $this->assertStringNotContainsString('503', $this->complained());
    }

    public function testAnOverflowIsSummarisedRatherThanPrintedAsTheAnswer(): void
    {
        // The retry half of this is the test above; this is the same bug by the other door. A turn
        // that outgrew the window comes back as an error, the prompt's post-run loop summarises
        // and sends it again, and `prompt()` returning before that happens means the error is
        // printed and the process exits — taking the compaction with it.
        $this->answers = ['first', 'second', 'ignored', 'the summary', 'answered after summarising'];
        $this->failures = [null, null, 'prompt is too long: 213462 tokens > 200000 maximum'];
        $code = $this->execute(
            ['one', 'two', 'three'],
            settings: Settings::inMemory(['compaction' => ['keepRecentTokens' => 1]]),
        );

        $this->assertSame(0, $code);
        $this->assertSame("answered after summarising\n", $this->printed());
        $this->assertStringNotContainsString('too long', $this->complained());
    }

    public function testAProviderThatThrowsIsAFailedTurnRatherThanAHang(): void
    {
        // The case that found the `AgentLoop` bug: the provider throws where a dead
        // connection or a missing key would, and before the fix the stream never closed and
        // the caller waited forever for a reason it was never given.
        $this->answers = [];
        $code = $this->execute(['ask']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('out of scripted answers', $this->complained());
        $this->assertSame('', $this->printed());
    }

    public function testTheImagesRideOnTheFirstMessageOnly(): void
    {
        $this->answers = ['ok', 'ok again'];
        $image = new ImageContent(TinyImages::PNG_BASE64, 'image/png');
        $this->execute(['look', 'and again'], images: [$image]);

        $messages = $this->session->messages();
        $this->assertCount(2, $messages[0]->content, 'the text and the image');
        $this->assertCount(1, $messages[2]->content, 'the text only');
    }

    public function testNothingSaidPrintsNothingAndSucceeds(): void
    {
        $this->answers = [];

        $this->assertSame(0, $this->execute([]));
        $this->assertSame('', $this->printed());
    }

    public function testTheSessionIsStillWrittenToDisk(): void
    {
        $this->answers = ['saved'];
        $this->execute(['ask'], store: true);

        // `-p` is not a reason to forget the conversation: `--continue` afterwards should
        // find it, which is why the store is wired the same as in the other two modes.
        $this->assertCount(1, SessionManager::listFor($this->cwd));
    }

    // ---- json ---------------------------------------------------------------------------

    public function testJsonPrintsEveryEventAndNoAnswerOfItsOwn(): void
    {
        $this->answers = ['hello'];
        $code = $this->execute(['hi'], mode: 'json');

        $this->assertSame(0, $code);
        $types = array_column($this->events(), 'type');

        $this->assertContains('agent_start', $types);
        $this->assertContains('message_start', $types);
        $this->assertContains('message_update', $types);
        $this->assertContains('message_end', $types);
        $this->assertContains('agent_end', $types);

        // No bare `hello` line at the end: in json the events *are* the output, and a
        // trailing plain-text answer would be the one line that is not JSON.
        $this->assertStringNotContainsString("\nhello\n", $this->printed());
    }

    public function testTheJsonIsTheSameShapeRpcModeSends(): void
    {
        $this->answers = ['hello'];
        $this->execute(['hi'], mode: 'json');

        foreach ($this->events() as $event) {
            // Not the first `message_end`: the prompt gets a start and an end of its own, so
            // the first one is the user's. Which is worth knowing about the protocol.
            if ($event['type'] === 'message_end' && $event['message']['role'] === 'assistant') {
                // `RpcEvents` for both, so a host that can read one mode can read the other.
                $this->assertSame('hello', $event['message']['content'][0]['text']);

                return;
            }
        }

        $this->fail('no assistant message_end among the events');
    }

    public function testJsonStaysZeroOnAFailedTurnBecauseTheFailureIsAnEventToo(): void
    {
        $this->answers = ['ignored'];
        $this->failure = 'the provider said no';
        $code = $this->execute(['ask'], mode: 'json');

        // The caller is reading events, and the failing turn arrived as one. Exiting 1 as
        // well would be telling them twice, and the second telling has no detail in it.
        $this->assertSame(0, $code);
        $this->assertContains('agent_end', array_column($this->events(), 'type'));
    }

    // ---- hooks and tools, with nobody to ask --------------------------------------------

    public function testAHookIsToldTheSessionStartedAndEnded(): void
    {
        $seen = [];
        $hooks = $this->hooks([
            'session_start' => static function () use (&$seen): mixed {
                $seen[] = 'start';

                return null;
            },
            'session_shutdown' => static function () use (&$seen): mixed {
                $seen[] = 'shutdown';

                return null;
            },
        ]);

        $this->answers = ['ok'];
        $this->execute(['ask'], hooks: $hooks);

        $this->assertSame(['start', 'shutdown'], $seen);
    }

    /**
     * The key a hook asks for is the session's — `$ctx->apiKey()` is what a background
     * completion (a title, a summary) runs on, and here it goes through `PrintMode`'s own
     * `getApiKey: fn (Model $m)`. That closure named `Model` without the import, so the first
     * hook to ask got a `TypeError` instead of a key, reported as the hook's own fault. The
     * terminal had the import and the two modes with no screen did not.
     */
    public function testAHookGetsTheSessionsKeyHereToo(): void
    {
        $key = 'not asked';
        $hooks = $this->hooks([
            'session_start' => static function (mixed $event, mixed $ctx) use (&$key): mixed {
                $key = $ctx->apiKey($ctx->model);

                return null;
            },
        ]);

        $this->answers = ['ok'];
        $this->execute(['ask'], hooks: $hooks);

        $this->assertSame('k', $key);
        $this->assertSame('', $this->complained());
    }

    public function testAHookThatAsksIsAnsweredNoWithoutAnybodyBeingAsked(): void
    {
        $answers = [];
        $hooks = $this->hooks(['session_start' => static function (mixed $event, mixed $ctx) use (&$answers): mixed {
            $answers['confirm'] = $ctx->ui->confirm('really?', '');
            $answers['select'] = $ctx->ui->select('which?', ['a']);
            $answers['hasUi'] = $ctx->hasUi;

            return null;
        }]);

        $this->answers = ['ok'];
        $this->execute(['ask'], hooks: $hooks);

        // Fail-safe: a `tool_call` guard that cannot reach a person blocks the call. And
        // `hasUi` is false, so a hook that would rather behave differently can tell.
        $this->assertSame(['confirm' => false, 'select' => null, 'hasUi' => false], $answers);
    }

    public function testAHookThatThrowsComplainsOnStandardErrorAndTheRunGoesOn(): void
    {
        $hooks = $this->hooks(['session_start' => static fn (): mixed => throw new RuntimeException('bad hook')]);

        $this->answers = ['still answered'];
        $code = $this->execute(['ask'], hooks: $hooks);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('bad hook', $this->complained());
        $this->assertSame("still answered\n", $this->printed());
    }

    public function testABrokenHookDoesNotLandAmongTheJsonLines(): void
    {
        $hooks = $this->hooks(['session_start' => static fn (): mixed => throw new RuntimeException('bad hook')]);

        $this->answers = ['ok'];
        $this->execute(['ask'], mode: 'json', hooks: $hooks);

        // The assertion is `events()` not failing: it decodes every line and a yellow
        // sentence among them would stop it.
        $this->assertNotSame([], $this->events());
        $this->assertStringContainsString('bad hook', $this->complained());
    }

    public function testACustomToolIsToldTheSessionStartedAndABrokenOneComplains(): void
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

                if ($event->reason === 'start') {
                    throw new RuntimeException('cannot start');
                }
            },
        );

        $this->answers = ['ok'];
        $this->execute(['ask'], customTools: new CustomToolSet([new LoadedCustomTool('t/index.php', 't', $tool)]));

        $this->assertSame(['start', 'shutdown'], $told);
        $this->assertStringContainsString('cannot start', $this->complained());
    }

    public function testACustomToolGetsTheRealSessionHereToo(): void
    {
        $seen = null;
        $tool = new CustomTool(
            name: 'noop',
            label: 'Noop',
            description: 'Does nothing.',
            parameters: ['type' => 'object', 'properties' => [], 'required' => []],
            execute: static fn (): mixed => null,
            onSession: static function (CustomToolSessionEvent $event, HookContext $context) use (&$seen): void {
                if ($event->reason === 'start') {
                    $seen = $context;
                }
            },
        );

        $this->answers = ['ok'];
        $this->execute(
            ['ask'],
            hooks: $this->hooks([]),
            customTools: new CustomToolSet([new LoadedCustomTool('t/index.php', 't', $tool)]),
            store: true,
        );

        // This mode wired the UI and not the context, so a tool here was handed
        // `CustomToolSet`'s own stub — no session, no model, `abort()` a no-op, and a working
        // directory of `.`, so a tool resolving a path against it read a different file than
        // the same tool in the terminal. The other two modes wired both.
        $this->assertNotNull($seen);
        $this->assertSame($this->cwd, $seen->cwd);
        $this->assertNotNull($seen->model);
        // The store is the runner's to carry — `CodingAgent::session()` builds it with one —
        // so what this pins is that the tool is handed the runner's context at all rather than
        // a stub, which is where every other field comes from too.
        $this->assertFalse($seen->hasUi, 'and there is nobody to ask in this mode');
    }

    // ---- slash commands ------------------------------------------------------------------

    public function testAHookCommandRunsHereRatherThanReachingTheModelAsText(): void
    {
        $api = new HookApi('.', 'test.php');
        $ran = null;
        $api->registerCommand('deploy', function (string $arguments) use (&$ran): void {
            $ran = $arguments;
        });

        $this->answers = ['the model should never be asked'];
        $code = $this->execute(['/deploy staging'], hooks: new HookRunner(
            [new LoadedHook('test.php', 'test.php', $api)],
            $this->cwd,
        ));

        $this->assertSame('staging', $ran, 'the command ran, with what was typed after it');
        $this->assertSame(0, $code);

        // And nothing was asked of the model: a hook's command is code, not a message. This is
        // what `pig -p "/deploy"` did before the dispatch moved into `prompt()` — the command
        // was sent as the text it is, and the model was left to guess what it meant.
        $this->assertSame([], $this->session->messages());
        $this->assertSame('', $this->printed());
    }

    public function testAFileCommandIsExpandedHereTooRatherThanSentAsItsOwnName(): void
    {
        $this->answers = ['reviewed'];
        $this->execute(['/review src/Foo.php'], fileCommands: [
            new FileCommand('review', 'Review a file', 'Review $1 and say what is wrong.', '(user)'),
        ]);

        $said = $this->session->messages()[0];
        $this->assertInstanceOf(UserMessage::class, $said);
        $this->assertSame('Review src/Foo.php and say what is wrong.', $said->content[0]->text);
    }

    public function testATextThatOnlyLooksLikeACommandIsSentAsTheTextItIs(): void
    {
        $this->answers = ['ok'];
        $this->execute(['/review src/Foo.php'], fileCommands: [
            new FileCommand('deploy', 'Deploy', 'Deploy it.', '(user)'),
        ]);

        // No command answers to `review` here, so the line is a message. The expansion is exact
        // for the same reason the terminal's dispatch is: deciding that a near miss was meant
        // as a command means guessing.
        $said = $this->session->messages()[0];
        $this->assertInstanceOf(UserMessage::class, $said);
        $this->assertSame('/review src/Foo.php', $said->content[0]->text);
    }

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
