<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Rpc\RpcError;
use Pig\Test\AssertsThrows;

/**
 * Driving a real `bin/pig --mode rpc` over a pipe.
 *
 * **This is the only test in the suite that starts the binary.** `RpcModeTest` builds an `RpcMode` in
 * process and hands it two streams, which exercises the dispatch and nothing else: not the process
 * starting, not a line crossing a pipe, not the framing when a read boundary falls mid-line, and not
 * the field names — the first draft of `RpcClient` sent `path` where the wire wants `sessionPath`,
 * and only something like this could have noticed.
 *
 * The model is a stand-in served on `127.0.0.1`, declared through a `models.json` in a temporary
 * home, so a turn can run end to end without a provider or a key that works.
 */
final class RpcClientTest extends TestCase
{
    use AssertsThrows;

    private string $root;

    private string $home;

    private string $cwd;

    private ?RpcClient $client = null;

    /** @var resource|null */
    private mixed $provider = null;

    private int $port = 0;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->root = sys_get_temp_dir() . '/pig-rpc-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home . '/.pig', 0o755, true);
        mkdir($this->cwd, 0o755, true);
        $this->client = null;
        $this->provider = null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->client?->stop(2.0);

        if (is_resource($this->provider)) {
            // A process, not a stream: the stand-in serves one turn and exits, but a test that never
            // prompted leaves it waiting on `accept()`.
            proc_terminate($this->provider);
            proc_close($this->provider);
        }

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

    /**
     * A stand-in Anthropic endpoint that answers one turn with $text, in its own process.
     *
     * A separate process rather than a loop watcher, because the agent under test is a separate
     * process too: it has to be able to connect while this one is parked waiting for a response.
     */
    private function serveOneTurn(string $text): void
    {
        $listening = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($listening === false) {
            self::markTestSkipped("Cannot open a loopback server: {$errstr}");
        }

        $name = (string) stream_socket_get_name($listening, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($listening);

        $script = $this->root . '/provider.php';
        file_put_contents($script, <<<PHP
        <?php
        \$server = stream_socket_server('tcp://127.0.0.1:{$this->port}', \$errno, \$errstr);

        if (\$server === false) {
            exit(1);
        }

        \$connection = stream_socket_accept(\$server, 20);

        if (\$connection === false) {
            exit(1);
        }

        \$request = '';

        while ((\$line = fgets(\$connection)) !== false) {
            \$request .= \$line;

            if (trim(\$line) === '') {
                break;
            }
        }

        if (preg_match('/content-length: (\\d+)/i', \$request, \$match) === 1) {
            fread(\$connection, (int) \$match[1]);
        }

        \$body = "event: message_start\\ndata: " . json_encode(['type' => 'message_start', 'message' => [
            'id' => 'm1',
            'model' => 'stand-in',
            'usage' => ['input_tokens' => 3, 'output_tokens' => 0],
        ]]) . "\\n\\n"
            . "event: content_block_start\\ndata: " . json_encode([
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]) . "\\n\\n"
            . "event: content_block_delta\\ndata: " . json_encode([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => {$this->quoted($text)}],
            ]) . "\\n\\n"
            . "event: content_block_stop\\ndata: " . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\\n\\n"
            . "event: message_delta\\ndata: " . json_encode([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 4],
            ]) . "\\n\\n";

        fwrite(\$connection, "HTTP/1.1 200 OK\\r\\nContent-Type: text/event-stream\\r\\nContent-Length: "
            . strlen(\$body) . "\\r\\n\\r\\n" . \$body);
        fclose(\$connection);
        PHP);

        $this->provider = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        file_put_contents($this->home . '/.pig/models.json', (string) json_encode([
            'providers' => [
                'stand-in' => [
                    'baseUrl' => 'http://127.0.0.1:' . $this->port,
                    'apiKey' => 'KEY_FOR_THE_STAND_IN',
                    'api' => 'anthropic-messages',
                    'models' => [[
                        'id' => 'stand-in',
                        'name' => 'Stand-in',
                        'reasoning' => false,
                        'input' => ['text'],
                        'contextWindow' => 100_000,
                        'maxTokens' => 1_000,
                    ]],
                ],
            ],
        ]));
    }

    private function quoted(string $text): string
    {
        return (string) json_encode($text);
    }

    public function testAHookCanRefuseANewSessionToo(): void
    {
        // The same three checks as `switch_session`, on its sibling — which is the whole value of
        // having found them once.
        mkdir($this->home . '/.pig/hooks', 0o755, true);
        file_put_contents($this->home . '/.pig/hooks/no-leaving.php', <<<'PHP'
        <?php

        use Pig\CodingAgent\Hooks\HookApi;
        use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;

        return function (HookApi $pi): void {
            $pi->on('session_before_switch', static fn (object $event): SessionBeforeSwitchResult
                => new SessionBeforeSwitchResult(cancel: $event->reason === 'new'));
        };
        PHP);

        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in'], withHooks: true);

        [$answer, $messages] = Async::run(static function () use ($client): array {
            $client->start();
            $client->promptAndWait('say something');
            $answer = $client->newSession();
            $messages = $client->messages();
            $client->stop();

            return [$answer, $messages];
        });

        $this->assertTrue($answer['cancelled'] ?? null, 'the hook said no');
        $this->assertNotSame([], $messages, 'and the conversation is still here');
    }

    public function testANewSessionEmptiesTheQueueOfTheOneBeingThrownAway(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $state = Async::run(static function () use ($client): array {
            $client->start();
            $client->followUp('meant for the conversation being discarded');
            $client->newSession();
            $state = $client->state();
            $client->stop();

            return $state;
        });

        $this->assertSame(0, $state['queuedMessageCount'] ?? null);
        $this->assertSame(0, $state['messageCount'] ?? null);
    }

    /** @param list<string> $arguments */
    private function client(array $arguments = [], bool $withHooks = false): RpcClient
    {
        $binary = RpcClient::defaultBinary();

        if (!is_file($binary)) {
            self::markTestSkipped("No bin/pig at {$binary}");
        }

        if (!is_file(dirname($binary) . '/../vendor/autoload.php')) {
            // Without an autoloader the binary cannot start, which is a `composer install` away and
            // not a failure of anything here.
            self::markTestSkipped('bin/pig needs vendor/autoload.php to run');
        }

        return $this->client = new RpcClient(
            cwd: $this->cwd,
            // Hooks off by default: every case but one is about the protocol, and a hook folder on
            // the machine running the tests is not this file's business.
            arguments: [...($withHooks ? [] : ['--no-hooks']), '--no-tools', ...$arguments],
            environment: [
                'PIG_HOME' => $this->home . '/.pig',
                'PI_HOME' => $this->root . '/pi',
                'HOME' => $this->home,
                'KEY_FOR_THE_STAND_IN' => 'not-checked-by-the-stand-in',
                // The container this was written in has a proxy in its environment, and a tunnel to
                // reach 127.0.0.1 cannot work. `Proxy::bypasses()` says the same, but saying it
                // twice costs nothing and a proxy that refuses is a confusing way to fail.
                'https_proxy' => '',
                'HTTPS_PROXY' => '',
            ],
            timeout: 20.0,
        );
    }

    // ---- starting and stopping -----------------------------------------------------------------

    public function testItStartsAnswersAndStops(): void
    {
        $this->serveOneTurn('through the pipe');
        $client = $this->client(['--model', 'stand-in']);

        $state = Async::run(static function () use ($client): array {
            $client->start();
            $state = $client->state();
            $client->stop();

            return $state;
        });

        $this->assertSame('stand-in', $state['model']['id'] ?? null);
        $this->assertFalse($client->isRunning(), 'and closing its input was enough to end it');
    }

    public function testAWholeTurnCrossesThePipe(): void
    {
        $this->serveOneTurn('through the pipe');
        $client = $this->client(['--model', 'stand-in']);

        [$events, $text] = Async::run(static function () use ($client): array {
            $client->start();
            $events = $client->promptAndWait('say something');
            $text = $client->lastAssistantText();
            $client->stop();

            return [$events, $text];
        });

        $this->assertSame('through the pipe', $text);

        $types = array_map(static fn (array $e): string => (string) ($e['type'] ?? ''), $events);

        $this->assertContains('agent_start', $types);
        $this->assertContains('message_update', $types, 'the deltas arrived as they happened');
        $this->assertContains('agent_end', $types);
    }

    public function testTheConversationIsReadableAfterwards(): void
    {
        $this->serveOneTurn('an answer');
        $client = $this->client(['--model', 'stand-in']);

        $messages = Async::run(static function () use ($client): array {
            $client->start();
            $client->promptAndWait('a question');
            $messages = $client->messages();
            $client->stop();

            return $messages;
        });

        $roles = array_map(static fn (array $m): string => (string) ($m['role'] ?? ''), $messages);

        $this->assertSame(['user', 'assistant'], $roles);
        $this->assertSame('a question', $messages[0]['content'][0]['text'] ?? null);
    }

    // ---- the commands that need no model -------------------------------------------------------

    public function testTheShellRunsInTheAgentsOwnProcess(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $result = Async::run(static function () use ($client): array {
            $client->start();
            $result = $client->bash('printf ready');
            $client->stop();

            return $result;
        });

        $this->assertSame('ready', $result['output'] ?? null);
        $this->assertSame(0, $result['exitCode'] ?? null);
    }

    public function testTheModelCanBeChangedAndTheStateSaysSo(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        [$set, $state] = Async::run(static function () use ($client): array {
            $client->start();
            $set = $client->setModel('anthropic', 'claude-3-5-haiku-latest');
            $state = $client->state();
            $client->stop();

            return [$set, $state];
        });

        $this->assertSame('claude-3-5-haiku-latest', $set['id'] ?? null);
        $this->assertSame('claude-3-5-haiku-latest', $state['model']['id'] ?? null);
    }

    public function testTheModelListIncludesTheOneFromModelsJson(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $models = Async::run(static function () use ($client): array {
            $client->start();
            $models = $client->availableModels();
            $client->stop();

            return $models;
        });

        $ids = array_map(static fn (array $m): string => (string) ($m['id'] ?? ''), $models);

        $this->assertContains('stand-in', $ids, 'a model declared in a file is on the wire too');
        $this->assertContains('claude-sonnet-4-5', $ids);
    }

    public function testAThinkingLevelThatIsNotOneIsRefusedByName(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $error = $this->assertThrows(RpcError::class, static fn () => Async::run(static function () use ($client): void {
            $client->start();

            try {
                $client->setThinkingLevel('extremely');
            } finally {
                $client->stop();
            }
        }));

        // The command's own name on the error, which is the half upstream's plain `Error` loses.
        $this->assertSame('set_thinking_level', $error->command);
        $this->assertStringContainsString('No such thinking level', $error->getMessage());
    }

    public function testACommandThatIsNotOneIsRefusedRatherThanIgnored(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $error = $this->assertThrows(RpcError::class, static fn () => Async::run(static function () use ($client): void {
            $client->start();

            try {
                // Reached through the one door a host has for a command this client has no method
                // for, which is also how a newer agent's command would be tried.
                (new \ReflectionMethod($client, 'send'))->invoke($client, ['type' => 'frobnicate']);
            } finally {
                $client->stop();
            }
        }));

        $this->assertStringContainsString('Unknown command: frobnicate', $error->getMessage());
    }

    // ---- when it goes wrong --------------------------------------------------------------------

    public function testSendingBeforeStartingIsRefusedRatherThanSilent(): void
    {
        $client = $this->client();

        $error = $this->assertThrows(RpcError::class, static fn () => Async::run(static fn () => $client->state()));

        $this->assertStringContainsString('not started', $error->getMessage());
    }

    public function testStartingTwiceIsRefused(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        $error = $this->assertThrows(RpcError::class, static fn () => Async::run(static function () use ($client): void {
            $client->start();

            try {
                $client->start();
            } finally {
                $client->stop();
            }
        }));

        $this->assertStringContainsString('already started', $error->getMessage());
    }

    public function testAgentThatCannotStartSaysWhatItWroteToStandardError(): void
    {
        $client = $this->client(['--model', 'no-such-model-anywhere']);

        $error = $this->assertThrows(RpcError::class, static fn () => Async::run(static function () use ($client): void {
            $client->start();

            try {
                $client->state();
            } finally {
                $client->stop();
            }
        }));

        // Upstream sleeps 100ms and checks the exit code, which is a race with a number on it. This
        // has no timing in it at all: the child died, so the wait for a response ends — and the
        // reason is the line the child printed on its way out.
        $this->assertStringContainsString("No model matches 'no-such-model-anywhere'", $error->getMessage());
    }

    public function testStoppingTwiceIsHarmless(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);

        Async::run(static function () use ($client): void {
            $client->start();
            $client->stop();
            $client->stop();
        });

        $this->assertFalse($client->isRunning());
    }

    public function testEveryEventIsOfferedToEveryListenerUntilItUnsubscribes(): void
    {
        $this->serveOneTurn('two listeners');
        $client = $this->client(['--model', 'stand-in']);

        [$first, $second] = Async::run(static function () use ($client): array {
            $client->start();

            $first = 0;
            $second = 0;
            $client->onEvent(static function () use (&$first): void {
                $first++;
            });
            $off = $client->onEvent(static function () use (&$second): void {
                $second++;
            });
            $off();

            $client->promptAndWait('say something');
            $client->stop();

            return [$first, $second];
        });

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'and an unsubscribed one hears nothing');
    }

    // ---- switching conversations ---------------------------------------------------------------

    public function testAHookCanRefuseASessionSwitchOverRpcToo(): void
    {
        // It could not before: the terminal asked the hooks through `mayLeave()` and the RPC path
        // did not, so a hook that refuses to leave a conversation worked in one mode and was
        // silently ignored in the other. `HookRunner::emitBeforeSwitch()` existed the whole time.
        mkdir($this->home . '/.pig/hooks', 0o755, true);
        file_put_contents($this->home . '/.pig/hooks/no-leaving.php', <<<'PHP'
        <?php

        use Pig\CodingAgent\Hooks\HookApi;
        use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;

        return function (HookApi $pi): void {
            $pi->on('session_before_switch', static fn (): SessionBeforeSwitchResult
                => new SessionBeforeSwitchResult(cancel: true));
        };
        PHP);

        $this->serveOneTurn('unused');
        // Hooks on, which the other cases in this file turn off.
        $client = $this->client(['--model', 'stand-in'], withHooks: true);
        $elsewhere = $this->root . '/elsewhere.jsonl';
        file_put_contents($elsewhere, json_encode(['type' => 'session', 'version' => 1, 'id' => 'x', 'cwd' => $this->cwd]) . "\n");

        [$answer, $state] = Async::run(static function () use ($client, $elsewhere): array {
            $client->start();
            $answer = $client->switchSession($elsewhere);
            $state = $client->state();
            $client->stop();

            return [$answer, $state];
        });

        $this->assertTrue($answer['cancelled'] ?? null, 'the hook said no');
        $this->assertStringNotContainsString('elsewhere.jsonl', (string) ($state['sessionFile'] ?? ''));
    }

    public function testASwitchThatIsAllowedReportsItWasNotCancelled(): void
    {
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);
        $elsewhere = $this->root . '/elsewhere.jsonl';
        file_put_contents($elsewhere, json_encode(['type' => 'session', 'version' => 1, 'id' => 'x', 'cwd' => $this->cwd]) . "\n");

        $answer = Async::run(static function () use ($client, $elsewhere): array {
            $client->start();
            $answer = $client->switchSession($elsewhere);
            $client->stop();

            return $answer;
        });

        $this->assertFalse($answer['cancelled'] ?? null);
        $this->assertSame(0, $answer['messageCount'] ?? null);
    }

    public function testSwitchingEmptiesTheQueueOfTheConversationBeingLeft(): void
    {
        // What was queued was typed into the conversation being left. Sending it into the next one
        // is the same crossing as appending to the wrong file.
        $this->serveOneTurn('unused');
        $client = $this->client(['--model', 'stand-in']);
        $elsewhere = $this->root . '/elsewhere.jsonl';
        file_put_contents($elsewhere, json_encode(['type' => 'session', 'version' => 1, 'id' => 'x', 'cwd' => $this->cwd]) . "\n");

        $state = Async::run(static function () use ($client, $elsewhere): array {
            $client->start();
            $client->followUp('meant for the old conversation');
            $client->switchSession($elsewhere);
            $state = $client->state();
            $client->stop();

            return $state;
        });

        $this->assertSame(0, $state['queuedMessageCount'] ?? null);
    }

}
