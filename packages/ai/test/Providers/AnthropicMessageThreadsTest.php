<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicMessagesCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\StrictToolLimits;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Upstream's `anthropic-message-threads.test.ts` (#11545), case for case.
 *
 * Upstream's fake endpoint answers each request as it comes; `CannedServer` answers its nth
 * connection with its nth reply, so each test lists the replies its requests get, in order. The
 * fake's own `thread_fingerprint_mismatch` check is not needed for that: every case here decides
 * on the client's side whether to continue, and the replies only say what the server would.
 *
 * The test server is on 127.0.0.1, where threads are off by default, so the model turns them on
 * with `supportsMessageThreads`, as a gateway that forwards `thread` would.
 */
final class AnthropicMessageThreadsTest extends TestCase
{
    private const string THREADS_BETA = 'message-threads-2026-08-12';

    private const string GRAMMAR_ERROR = 'The compiled grammar is too large, which would cause performance issues. Simplify your tool schemas or reduce the number of strict tools.';

    private static int $sessionCounter = 0;

    private CannedServer $server;

    private string $url = 'http://127.0.0.1:1';

    private string $sessionId;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
        $this->sessionId = 'thread-test-' . ++self::$sessionCounter;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        Anthropic::cleanupSessionResources();
        StrictToolLimits::cleanup();
    }

    public function testCreatesAThreadThenContinuesItWithOnlyTheNewMessages(): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2'));
        $result = null;

        Async::run(function () use (&$result): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $result = $this->say($messages);
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        [$create, $next] = $this->requests();
        $this->assertSame(['type' => 'create'], $create['body']['thread']);
        $this->assertArrayHasKey('system', $create['body']);
        $this->assertArrayHasKey('tools', $create['body']);
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_1'], $next['body']['thread']);
        $this->assertArrayNotHasKey('system', $next['body']);
        $this->assertArrayNotHasKey('tools', $next['body']);
        // The cache marker was on the first message in request 1 and is on the new one now.
        $this->assertSame([['type' => 'text', 'text' => 'first', 'cache_control' => ['type' => 'ephemeral']]], $create['body']['messages'][0]['content']);
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'second', 'cache_control' => ['type' => 'ephemeral']]]]],
            $next['body']['messages'],
        );
        $this->assertContains(self::THREADS_BETA, $create['betas']);
        $this->assertSame($create['betas'], $next['betas']);
    }

    public function testContinuesAfterAReplyThatEndsInToolCallsWithoutPlaceholderResultsInThePrefix(): void
    {
        $this->serve(
            self::reply('msg_tool', toolCalls: [['id' => 'toolu_1', 'name' => 'lookup', 'input' => ['value' => 'x']]]),
            self::reply('msg_2'),
        );
        $first = null;

        Async::run(function () use (&$first): void {
            $messages = [self::user('look it up')];
            $first = $this->say($messages);
            $messages[] = self::toolResult('toolu_1', 'lookup');
            $this->say($messages);
        });

        $this->assertSame(StopReason::ToolUse, $first?->stopReason);
        $next = $this->requests()[1];
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_tool'], $next['body']['thread']);
        $this->assertCount(1, $next['body']['messages']);
        $this->assertSame('user', $next['body']['messages'][0]['role']);
        $this->assertSame('tool_result', $next['body']['messages'][0]['content'][0]['type']);
        $this->assertSame('toolu_1', $next['body']['messages'][0]['content'][0]['tool_use_id']);
    }

    public function testContinuesWhenTheEffortLevelChangesBetweenTurns(): void
    {
        $this->serve(self::reply('msg_a', thinking: 'thought'), self::reply('msg_2'));
        $model = $this->model(reasoning: true, compat: new AnthropicMessagesCompat(
            forceAdaptiveThinking: true,
            supportsMidConvoEffort: true,
            supportsMessageThreads: true,
        ));

        Async::run(function () use ($model): void {
            $messages = [self::user('first')];
            $this->say($messages, $model, $this->options(thinkingEnabled: true, effort: 'high'));
            $messages[] = self::user('second');
            $this->say($messages, $model, $this->options(thinkingEnabled: true, effort: 'low'));
        });

        [$first, $next] = $this->requests();
        $this->assertSame(['user', 'high'], self::effortsOrRoles($first['body']['messages']));
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_a'], $next['body']['thread']);
        $this->assertSame(['user', 'low'], self::effortsOrRoles($next['body']['messages']));
    }

    public function testContinuesWhenASystemMessageWithANativeToolChangeFollowsTheReply(): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2'));
        $model = $this->model(compat: new AnthropicMessagesCompat(
            supportsMidConvoSystemMessages: true,
            supportsMidConvoToolChanges: true,
            supportsMessageThreads: true,
        ));

        Async::run(function () use ($model): void {
            $messages = [self::user('first')];
            $this->say($messages, $model);
            $messages[] = new SystemMessage('Update', toolsAdded: [self::tool('other')], timestamp: 2);
            $messages[] = self::user('second');
            $this->say($messages, $model);
        });

        $next = $this->requests()[1];
        $this->assertSame('continue', $next['body']['thread']['type']);
        $this->assertSame(['user', 'system'], array_column($next['body']['messages'], 'role'));
        $this->assertStringContainsString('tool_addition', (string) json_encode($next['body']['messages'][1]['content']));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function changes(): iterable
    {
        yield 'system prompt' => [[], ['systemPrompt' => 'Different prompt.']];
        yield 'tools' => [[], ['tools' => ['lookup', 'other']]];
        yield 'model' => [[], ['modelId' => 'claude-other']];
        yield 'tool choice' => [[], ['options' => ['toolChoice' => 'any']]];
        yield 'cache retention' => [[], ['options' => ['cacheRetention' => 'long']]];
        yield 'thinking level' => [
            ['reasoning' => true, 'options' => ['thinkingEnabled' => true, 'thinkingBudgetTokens' => 1024]],
            ['reasoning' => true, 'options' => ['thinkingEnabled' => true, 'thinkingBudgetTokens' => 2048]],
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    #[DataProvider('changes')]
    public function testCreatesANewThreadWhenAFixedSettingChanges(array $before, array $after): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2', model: $after['modelId'] ?? 'claude-test'));

        Async::run(function () use ($before, $after): void {
            $messages = [self::user('first')];
            $this->sayWith($messages, $before);
            $messages[] = self::user('second');
            $this->sayWith($messages, $after);
        });

        $next = $this->requests()[1];
        $this->assertSame(['type' => 'create'], $next['body']['thread']);
        $this->assertCount(3, $next['body']['messages']);
    }

    public function testCreatesANewThreadWhenTheRequestHasNoMessagesBeyondTheStoredPrefix(): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2'));

        Async::run(function (): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $this->turn($messages);
        });

        $this->assertSame(['type' => 'create'], $this->requests()[1]['body']['thread']);
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function newThreadErrors(): iterable
    {
        yield 'thread_not_found' => ['thread_not_found', 404];
        yield 'thread_already_continued' => ['thread_already_continued', 400];
        yield 'thread_continue_in_flight' => ['thread_continue_in_flight', 400];
        yield 'thread_fingerprint_mismatch' => ['thread_fingerprint_mismatch', 400];
    }

    #[DataProvider('newThreadErrors')]
    public function testStartsANewThreadWithTheFullRequestAfterARejectedContinue(string $errorCode, int $status): void
    {
        $this->serve(
            self::reply('msg_1'),
            self::errorResponse($status, 'thread rejected', $errorCode),
            self::reply('msg_3'),
            self::reply('msg_4'),
        );
        $result = null;
        $payloads = 0;

        Async::run(function () use (&$result, &$payloads): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $result = $this->say($messages, options: $this->options(onPayload: static function () use (&$payloads): null {
                $payloads++;

                return null;
            }));
            $this->assertCount(3, $this->requests());
            $messages[] = self::user('third');
            $this->say($messages);
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        $this->assertSame(1, $payloads);
        [, $rejected, $resend, $later] = $this->requests();
        $this->assertSame('continue', $rejected['body']['thread']['type']);
        $this->assertSame(['type' => 'create'], $resend['body']['thread']);
        $this->assertCount(3, $resend['body']['messages']);
        $this->assertArrayHasKey('system', $resend['body']);
        $this->assertSame($rejected['betas'], $resend['betas']);
        $this->assertCount(1, $result->diagnostics ?? []);
        $this->assertSame('anthropic_thread_fallback', $result->diagnostics[0]->type);
        $this->assertSame(['reason' => $errorCode], $result->diagnostics[0]->details);
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_3'], $later['body']['thread']);
    }

    /** @return iterable<string, array{0: string|null, 1: string}> */
    public static function threadsUnsupported(): iterable
    {
        yield 'thread_unsupported_request' => ['thread_unsupported_request', 'rejected'];
        yield 'a 400 without error code about the thread field' => [null, 'thread: Extra inputs are not permitted'];
    }

    #[DataProvider('threadsUnsupported')]
    public function testTurnsThreadsOffForTheSessionAfterTheEndpointRejectsThem(?string $errorCode, string $message): void
    {
        $this->serve(self::reply('msg_1'), self::errorResponse(400, $message, $errorCode), self::reply('msg_3'), self::reply('msg_4'));
        $result = null;

        Async::run(function () use (&$result): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $result = $this->say($messages);
            $messages[] = self::user('third');
            $this->say($messages);
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        $this->assertSame('anthropic_thread_fallback', $result->diagnostics[0]->type ?? null);
        $this->assertSame(['reason' => $errorCode ?? '400'], $result->diagnostics[0]->details);
        $requests = $this->requests();
        $this->assertArrayNotHasKey('thread', $requests[2]['body']);
        $this->assertArrayNotHasKey('thread', $requests[3]['body']);
        // "The resend was accepted with the threads beta, so the beta list stays the same."
        $this->assertSame($requests[0]['betas'], $requests[3]['betas']);
    }

    public function testShowsTheErrorWhenTheResendFailsTooAndKeepsThreadsOn(): void
    {
        $this->serve(
            self::reply('msg_1'),
            self::errorResponse(400, 'thread: Extra inputs are not permitted'),
            self::errorResponse(400, 'Real problem'),
            self::reply('msg_4'),
        );
        $failed = null;

        Async::run(function () use (&$failed): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $failed = $this->turn($messages);
            $this->assertCount(3, $this->requests());
            $this->turn($messages);
        });

        $this->assertSame(StopReason::Error, $failed?->stopReason);
        $this->assertStringContainsString('Real problem', (string) $failed->errorMessage);
        $this->assertSame(['type' => 'create'], $this->requests()[3]['body']['thread']);
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function otherErrors(): iterable
    {
        yield '400' => [400, 'prompt is too long: 250000 tokens > 200000 maximum'];
        yield '404' => [404, 'model: claude-test'];
    }

    #[DataProvider('otherErrors')]
    public function testPassesAnErrorThatIsNotAboutTheThreadOnWithoutResending(int $status, string $message): void
    {
        $this->serve(self::reply('msg_1'), self::errorResponse($status, $message), self::reply('msg_3'));
        $failed = null;

        Async::run(function () use (&$failed): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $failed = $this->turn($messages);
            $this->assertCount(2, $this->requests());
            // "Threads stay on: after a fix such as compaction, the next request starts a new thread."
            $this->turn($messages);
        });

        $this->assertSame(StopReason::Error, $failed?->stopReason);
        $this->assertStringContainsString($message, (string) $failed->errorMessage);
        $this->assertNull($failed->diagnostics);
        $this->assertSame(['type' => 'create'], $this->requests()[2]['body']['thread']);
    }

    public function testLeavesStrictToolRejectionsToTheStrictToolFallback(): void
    {
        $this->serve(self::errorResponse(400, self::GRAMMAR_ERROR), self::reply('msg_2'));
        $model = $this->model(compat: new AnthropicMessagesCompat(strictTools: true, supportsMessageThreads: true));
        $strictTool = new Tool('lookup', 'Tool lookup', self::schema(), constrainedSampling: ['type' => 'json_schema', 'strict' => 'prefer']);
        $result = null;

        Async::run(function () use (&$result, $model, $strictTool): void {
            $result = $this->turn([self::user('first')], $model, tools: [$strictTool]);
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        $this->assertSame(['create', 'create'], $this->threadTypes());
        $this->assertSame(['anthropic_strict_tools_fallback'], array_map(static fn ($diagnostic): string => $diagnostic->type, $result->diagnostics ?? []));
    }

    public function testStartsANewThreadWhenARetriedContinueFindsTheThreadInFlight(): void
    {
        $this->serve(
            self::reply('msg_1'),
            self::errorResponse(503, 'overloaded', null, ['retry-after-ms' => '0']),
            self::errorResponse(400, 'in flight', 'thread_continue_in_flight'),
            self::reply('msg_4'),
        );
        $result = null;

        Async::run(function () use (&$result): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $messages[] = self::user('second');
            $result = $this->say($messages, options: $this->options(maxRetries: 1));
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        $this->assertSame(['create', 'continue', 'continue', 'create'], $this->threadTypes());
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function explicitBetaHeaders(): iterable
    {
        yield 'without the threads beta' => ['fine-grained-tool-streaming-2025-05-14'];
        yield 'set to null' => [null];
    }

    #[DataProvider('explicitBetaHeaders')]
    public function testFailsOnceThenTurnsThreadsOffWithAnExplicitAnthropicBetaHeader(?string $header): void
    {
        // The endpoint rejects `thread` from a request without the threads beta.
        $this->serve(self::errorResponse(400, 'thread: Extra inputs are not permitted'), self::reply('msg_2'), self::reply('msg_3'));
        $result = null;

        Async::run(function () use (&$result, $header): void {
            $options = $this->options(headers: ['anthropic-beta' => $header]);
            $messages = [self::user('first')];
            $result = $this->say($messages, options: $options);
            $messages[] = self::user('second');
            $this->say($messages, options: $options);
        });

        $this->assertSame(StopReason::Stop, $result?->stopReason);
        $this->assertSame(['create', null, null], $this->threadTypes());
        $this->assertNotContains(self::THREADS_BETA, $this->requests()[0]['betas']);
    }

    /** @return iterable<string, array{0: array<string, mixed>|'abort'}> */
    public static function unrecordedReplies(): iterable
    {
        yield 'an aborted reply' => ['abort'];
        yield 'a stream error' => [['end' => 'error_event']];
        yield 'a stream that ends early' => [['end' => 'no_message_stop']];
        yield 'a refusal' => [['stopReason' => 'refusal']];
        yield 'a fallback model reply' => [['model' => 'claude-fallback']];
    }

    /** @param array<string, mixed>|'abort' $spec */
    #[DataProvider('unrecordedReplies')]
    public function testDoesNotRecordAReplyThatCannotBeContinued(array|string $spec): void
    {
        $this->serve(self::reply('msg_bad', ...($spec === 'abort' ? [] : $spec)), self::reply('msg_2'));

        Async::run(function () use ($spec): void {
            $controller = new AbortController();
            $messages = [self::user('first')];
            $first = $this->turn($messages, options: $spec === 'abort'
                ? $this->options(signal: $controller->signal, onProviderStreamEvent: static function (mixed $event) use ($controller): void {
                    if (is_array($event) && ($event['type'] ?? null) === 'message_start') {
                        $controller->abort();
                    }
                })
                : null);
            $messages[] = $first;
            $messages[] = self::user('second');
            $this->turn($messages);
        });

        $this->assertSame(['type' => 'create'], $this->requests()[1]['body']['thread']);
    }

    public function testDoesNotUseThreadsWithCacheRetentionNone(): void
    {
        $this->serve(self::reply('msg_1'));

        Async::run(function (): void {
            $messages = [self::user('first')];
            $this->say($messages, options: $this->options(cacheRetention: 'none'));
        });

        $request = $this->requests()[0];
        $this->assertArrayNotHasKey('thread', $request['body']);
        $this->assertNotContains(self::THREADS_BETA, $request['betas']);
    }

    public function testMatchesClaudeCodeToolNamesAcrossTurnsWithOauth(): void
    {
        $this->serve(
            self::reply('msg_oauth', toolCalls: [['id' => 'toolu_1', 'name' => 'Read', 'input' => ['value' => 'x']]]),
            self::reply('msg_2'),
        );
        $first = null;

        Async::run(function () use (&$first): void {
            $options = $this->options(apiKey: 'sk-ant-oat-test');
            $messages = [self::user('read it')];
            $first = $this->say($messages, options: $options, tools: [self::tool('read')]);
            $messages[] = self::toolResult('toolu_1', 'read');
            $this->say($messages, options: $options, tools: [self::tool('read')]);
        });

        $call = array_values(array_filter($first?->content ?? [], static fn ($block): bool => $block instanceof ToolCall))[0] ?? null;
        $this->assertSame('read', $call?->name);
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_oauth'], $this->requests()[1]['body']['thread']);
    }

    public function testSendsCacheWarmingReplaysWithoutAThreadAndKeepsTheConversationsThread(): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2'), self::reply('msg_3'));

        Async::run(function (): void {
            $messages = [self::user('first')];
            $this->say($messages);
            $this->turn(array_slice($messages, 0, 1), options: $this->options(maxTokens: 1, disableContinuation: true));
            $messages[] = self::user('second');
            $this->say($messages);
        });

        [$real, $warm, $next] = $this->requests();
        $this->assertArrayNotHasKey('thread', $warm['body']);
        $this->assertSame(1, $warm['body']['max_tokens']);
        $this->assertSame($real['body']['messages'], $warm['body']['messages']);
        $this->assertSame($real['betas'], $warm['betas']);
        $this->assertSame(['type' => 'continue', 'previous_message_id' => 'msg_1'], $next['body']['thread']);
    }

    public function testEndingTheSessionForgetsWhereItsThreadContinues(): void
    {
        $this->serve(self::reply('msg_1'), self::reply('msg_2'));

        Async::run(function (): void {
            $messages = [self::user('first')];
            $this->say($messages);
            Anthropic::cleanupSessionResources($this->sessionId);
            $messages[] = self::user('second');
            $this->say($messages);
        });

        $this->assertSame(['type' => 'create'], $this->requests()[1]['body']['thread']);
    }

    public function testThreadsAreOffWithoutCompatAwayFromAnthropicsOwnEndpoint(): void
    {
        $this->serve(self::reply('msg_1'));

        Async::run(function (): void {
            $messages = [self::user('first')];
            $this->say($messages, $this->model(compat: null));
        });

        $request = $this->requests()[0];
        $this->assertArrayNotHasKey('thread', $request['body']);
        $this->assertNotContains(self::THREADS_BETA, $request['betas']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool|null, 3: bool}> */
    public static function defaults(): iterable
    {
        yield 'anthropic on api.anthropic.com' => ['anthropic', 'https://api.anthropic.com', null, true];
        yield 'anthropic elsewhere' => ['anthropic', 'https://gateway.example.com', null, false];
        // #11987: the endpoint decides, whatever the provider is called.
        yield 'another provider id on api.anthropic.com' => ['anthropic-1-api', 'https://api.anthropic.com', null, true];
        yield 'another provider id elsewhere' => ['anthropic-1-api', 'https://gateway.example.com', null, false];
        yield 'turned off' => ['anthropic', 'https://api.anthropic.com', false, false];
        yield 'turned on for a gateway' => ['custom', 'https://gateway.example.com', true, true];
    }

    #[DataProvider('defaults')]
    public function testTheDefaultIsAnthropicsOwnEndpointOnly(string $provider, string $baseUrl, ?bool $flag, bool $expected): void
    {
        $model = new Model(
            'claude-test',
            'Test Claude',
            Api::AnthropicMessages,
            $provider,
            $baseUrl,
            200_000,
            100,
            false,
            ['text'],
            new Pricing(),
            compat: $flag === null ? null : new AnthropicMessagesCompat(supportsMessageThreads: $flag),
        );

        $this->assertSame($expected, (new \ReflectionMethod(Anthropic::class, 'supportsMessageThreads'))->invoke(null, $model));
    }

    // -- harness ---------------------------------------------------------------------------------

    /** @param list<string> ...$replies */
    private function serve(array ...$replies): void
    {
        $this->url = rtrim($this->server->startSequence(array_values($replies)), '/');
    }

    /**
     * Every request the server got, in order: the decoded body and the `anthropic-beta` list.
     *
     * @return list<array{body: array<string, mixed>, betas: list<string>}>
     */
    private function requests(): array
    {
        $requests = [];

        foreach (preg_split('/(?=POST \/)/', $this->server->received(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $request) {
            [$head, $body] = explode("\r\n\r\n", $request, 2);
            $betas = preg_match('/^anthropic-beta:[ \t]*(.*?)\r?$/im', $head, $match) === 1 && $match[1] !== ''
                ? explode(',', $match[1])
                : [];
            $requests[] = ['body' => json_decode($body, true, flags: JSON_THROW_ON_ERROR), 'betas' => $betas];
        }

        return $requests;
    }

    /** @return list<string|null> each request's `thread.type`, null for none */
    private function threadTypes(): array
    {
        return array_map(static fn (array $request): ?string => $request['body']['thread']['type'] ?? null, $this->requests());
    }

    private function model(string $id = 'claude-test', bool $reasoning = false, ?AnthropicMessagesCompat $compat = new AnthropicMessagesCompat(supportsMessageThreads: true)): Model
    {
        return new Model($id, 'Test Claude', Api::AnthropicMessages, 'anthropic', $this->url, 200_000, 100, $reasoning, ['text'], new Pricing(), compat: $compat);
    }

    private function options(mixed ...$named): AnthropicOptions
    {
        return new AnthropicOptions(...[
            'apiKey' => 'test-key',
            'sessionId' => $this->sessionId,
            'maxRetries' => 0,
            ...$named,
        ]);
    }

    /**
     * One turn, upstream's `turn()`: the conversation with a system prompt and a `lookup` tool.
     *
     * @param list<mixed> $messages
     * @param list<Tool>|null $tools
     */
    private function turn(array $messages, ?Model $model = null, ?AnthropicOptions $options = null, string $systemPrompt = 'You are a test assistant.', ?array $tools = null): AssistantMessage
    {
        $context = Transcript::normalizeContext(new Context($messages, $systemPrompt, $tools ?? [self::tool('lookup')]));
        $stream = (new Anthropic())->stream($model ?? $this->model(), $context, $options ?? $this->options());

        foreach ($stream as $ignored) {
        }

        return $stream->result()->await();
    }

    /**
     * Upstream's `say()`: a turn whose reply joins the conversation.
     *
     * @param list<mixed> $messages
     * @param list<Tool>|null $tools
     */
    private function say(array &$messages, ?Model $model = null, ?AnthropicOptions $options = null, ?array $tools = null): AssistantMessage
    {
        $result = $this->turn($messages, $model, $options, tools: $tools);
        $messages[] = $result;

        return $result;
    }

    /**
     * `say()` from a data provider's description of the turn.
     *
     * @param list<mixed> $messages
     * @param array<string, mixed> $input
     */
    private function sayWith(array &$messages, array $input): void
    {
        $result = $this->turn(
            $messages,
            $this->model($input['modelId'] ?? 'claude-test', $input['reasoning'] ?? false),
            $this->options(...($input['options'] ?? [])),
            $input['systemPrompt'] ?? 'You are a test assistant.',
            array_map(self::tool(...), $input['tools'] ?? ['lookup']),
        );
        $messages[] = $result;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<string> each message's effort when it is an effort message, else its role
     */
    private static function effortsOrRoles(array $messages): array
    {
        return array_map(static fn (array $message): string => $message['output_config']['effort'] ?? $message['role'], $messages);
    }

    private static function user(string $text): UserMessage
    {
        return new UserMessage($text, 1);
    }

    private static function toolResult(string $id, string $name): ToolResultMessage
    {
        return new ToolResultMessage($id, $name, [new TextContent('result')], timestamp: 1);
    }

    private static function tool(string $name): Tool
    {
        return new Tool($name, "Tool {$name}", self::schema());
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value']];
    }

    /**
     * Upstream's `reply()`: a streamed answer with `id`, from `model`, and the blocks asked for.
     *
     * @param list<array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param 'error_event'|'no_message_stop'|null $end an event to send instead of finishing normally
     * @return list<string>
     */
    private static function reply(
        string $id,
        string $model = 'claude-test',
        ?string $text = null,
        ?string $thinking = null,
        array $toolCalls = [],
        ?string $stopReason = null,
        ?string $end = null,
    ): array {
        $events = [['message_start', ['message' => ['id' => $id, 'model' => $model, 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]]]];
        $index = 0;

        if ($thinking !== null) {
            $events[] = ['content_block_start', ['index' => $index, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]];
            $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'thinking_delta', 'thinking' => $thinking]]];
            $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']]];
            $events[] = ['content_block_stop', ['index' => $index]];
            $index++;
        }

        $events[] = ['content_block_start', ['index' => $index, 'content_block' => ['type' => 'text', 'text' => '']]];
        $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text ?? 'answer']]];
        $events[] = ['content_block_stop', ['index' => $index]];
        $index++;

        foreach ($toolCalls as $call) {
            $events[] = ['content_block_start', ['index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => new \stdClass()]]];
            $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($call['input'])]]];
            $events[] = ['content_block_stop', ['index' => $index]];
            $index++;
        }

        if ($end === 'error_event') {
            $events[] = ['error', ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]];
        } else {
            $events[] = ['message_delta', ['delta' => ['stop_reason' => $stopReason ?? ($toolCalls !== [] ? 'tool_use' : 'end_turn')], 'usage' => ['output_tokens' => 1]]];

            if ($end !== 'no_message_stop') {
                $events[] = ['message_stop', []];
            }
        }

        $body = '';

        foreach ($events as [$type, $data]) {
            $body .= "event: {$type}\ndata: " . json_encode(['type' => $type] + $data) . "\n\n";
        }

        return ["HTTP/1.1 200 OK\r\ncontent-type: text/event-stream\r\ncontent-length: " . strlen($body) . "\r\n\r\n", $body];
    }

    /**
     * Upstream's `errorResponse()`: Anthropic's error body, with `details.error_code` when given.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function errorResponse(int $status, string $message, ?string $errorCode = null, array $headers = []): array
    {
        $type = match ($status) {
            404 => 'not_found_error',
            429 => 'rate_limit_error',
            default => 'invalid_request_error',
        };
        $error = ['type' => $type, 'message' => $message];

        if ($errorCode !== null) {
            $error['details'] = ['error_code' => $errorCode];
        }

        $body = (string) json_encode(['type' => 'error', 'error' => $error]);
        $head = "HTTP/1.1 {$status} Error\r\ncontent-type: application/json\r\ncontent-length: " . strlen($body) . "\r\n";

        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return ["{$head}\r\n{$body}"];
    }
}
