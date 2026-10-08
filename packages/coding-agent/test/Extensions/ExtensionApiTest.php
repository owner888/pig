<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\UserMessage;
use Pig\Ai\TranscriptContext;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Events\AfterProviderResponseEvent;
use Pig\CodingAgent\Hooks\Events\BeforeProviderHeadersEvent;
use Pig\CodingAgent\Hooks\Events\BeforeProviderRequestEvent;
use Pig\CodingAgent\Hooks\Events\ProviderStreamEvent;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\BeforeProviderRequestResult;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Settings;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;

/**
 * What `ExtensionApi` grew to close the gap with upstream's `ExtensionAPI`: the provider
 * registration, the two provider-traffic hooks, flags, and the session seen from an extension.
 */
final class ExtensionApiTest extends TestCase
{
    use AssertsThrows;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        ProviderRegistry::forget();
        ExtensionApi::forgetFlags();
        ExtensionApi::forgetHttpRoutes();
        $this->server = new CannedServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        ExtensionApi::forgetFlags();
        ExtensionApi::forgetHttpRoutes();
        ExtensionApi::useSettings(null);
        Models::forgetRegistered();
    }

    // ---- providers ----------------------------------------------------------------------

    public function testRegisterProviderReachesTheRegistryAndUnregisterTakesItBack(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $api->registerProvider(new Provider('zzp', 'Probe', [new Model('m', 'M', Api::Extension, 'zzp', 'https://probe.invalid', 1, 1)]));

        $this->assertNotNull(Models::find('zzp', 'm'));
        $this->assertSame(['zzp'], $api->providers());

        $api->unregisterProvider('zzp');

        $this->assertNull(Models::find('zzp', 'm'));
        $this->assertSame([], $api->providers());
    }

    // ---- provider traffic ---------------------------------------------------------------

    public function testTheProviderHooksSeeThePayloadTheHeadersTheResponseAndEachStreamEvent(): void
    {
        // Upstream wires its extension provider events through the request's options:
        // `before_provider_request` is `onPayload` (chained, each handler given what the last one
        // returned, the answer replacing the body), `before_provider_headers` is `transformHeaders`
        // (handlers mutate the headers in place), `after_provider_response` is `onResponse` (status
        // and headers before the body is read) and `provider_stream_event` is
        // `onProviderStreamEvent` (each parsed event). pig used to observe every `HttpClient`
        // request process-wide instead, sign-ins included, with a `Request` object for a payload.
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $seen = ['events' => []];
        $api->on('before_provider_request', static fn (BeforeProviderRequestEvent $e): BeforeProviderRequestResult
            => new BeforeProviderRequestResult([...$e->payload, 'max_tokens' => 77]));
        $api->on('before_provider_request', static function (BeforeProviderRequestEvent $e) use (&$seen): BeforeProviderRequestResult {
            $seen['chained'] = $e->payload['max_tokens'] ?? null;

            return new BeforeProviderRequestResult([...$e->payload, 'metadata' => ['user_id' => 'hooked']]);
        });
        $api->on('before_provider_headers', static function (BeforeProviderHeadersEvent $e): void {
            $e->headers['x-trace'] = 'abc';
        });
        $api->on('after_provider_response', static function (AfterProviderResponseEvent $e) use (&$seen): void {
            $seen['status'] = $e->status;
            $seen['header'] = $e->headers['x-answered'] ?? null;
        });
        $api->on('provider_stream_event', static function (ProviderStreamEvent $e) use (&$seen): void {
            $seen['events'][] = [$e->provider, $e->api, $e->model, $e->data['type'] ?? null];
        });

        $events = [
            ['message_start', ['message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-5', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]],
            ['message_stop', []],
        ];
        $pieces = ["HTTP/1.1 200 OK\r\nx-answered: yes\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($events as [$type, $data]) {
            $body = "event: {$type}\ndata: " . json_encode(['type' => $type] + $data) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";
        $url = $this->server->start($pieces);

        $agent = new Agent(new AgentOptions(apiKey: 'test-key'));
        $sonnet = Models::get('claude-sonnet-4-5') ?? throw new \RuntimeException('no sonnet');
        $agent->setModel(new Model($sonnet->id, $sonnet->name, $sonnet->api, $sonnet->provider, rtrim($url, '/'), $sonnet->contextWindow, $sonnet->maxTokens, $sonnet->reasoning, $sonnet->input, $sonnet->pricing, $sonnet->headers, $sonnet->compat, $sonnet->thinkingLevelMap));
        $hooks = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work');
        $session = new AgentSession($agent, '/work', null, Settings::inMemory(), $hooks);

        Async::run(static fn () => $session->prompt('hi'));

        $head = $this->server->receivedHead();
        $body = $this->server->receivedJson();
        $this->assertStringContainsString("x-trace: abc\r\n", $head);
        $this->assertSame(77, $body['max_tokens']);
        $this->assertSame(['user_id' => 'hooked'], $body['metadata']);
        $this->assertSame(77, $seen['chained'], 'the second handler saw the first one\'s payload');
        $this->assertSame(200, $seen['status']);
        $this->assertSame('yes', $seen['header']);
        $this->assertSame(
            ['message_start', 'content_block_start', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop'],
            array_column($seen['events'], 3),
        );
        $this->assertSame(['anthropic', 'anthropic-messages', 'claude-sonnet-4-5'], array_slice($seen['events'][0], 0, 3));

        $session->dispose();
    }

    public function testAHttpRequestOutsideAProviderCallIsNotSeenByTheProviderHooks(): void
    {
        // Upstream's provider events are per request, through the stream options; a sign-in's
        // token exchange, or any other `HttpClient` call, is not a provider request.
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $seen = false;
        $api->on('before_provider_request', static function () use (&$seen): null {
            $seen = true;

            return null;
        });
        $api->on('after_provider_response', static function () use (&$seen): void {
            $seen = true;
        });
        $session = $this->session(new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work'));

        $url = $this->server->start(["HTTP/1.1 200 OK\r\ncontent-length: 2\r\nconnection: close\r\n\r\nok"]);

        Async::run(function () use ($url): void {
            (new HttpClient())->send(new Request('GET', $url))->body->all();
        });

        $this->assertFalse($seen);

        $session->dispose();
    }

    // ---- flags --------------------------------------------------------------------------

    public function testAFlagIsDeclaredThenReadOffTheCommandLine(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $api->registerFlag('probe-on', 'boolean', 'turn the probe on');
        $api->registerFlag('probe-name', 'string', 'what to call it', 'default-name');

        // Before the command line has been read: the defaults.
        $this->assertNull($api->getFlag('probe-on'));
        $this->assertSame('default-name', $api->getFlag('probe-name'));
        $this->assertNull($api->getFlag('nobody-declared-this'));

        // `bin/pig` hands over every option it saw; a boolean's presence is true.
        ExtensionApi::applyFlags(['probe-on' => '', 'probe-name' => 'typed', 'unrelated' => 'x']);

        $this->assertTrue($api->getFlag('probe-on'));
        $this->assertSame('typed', $api->getFlag('probe-name'));
        $this->assertArrayHasKey('probe-on', ExtensionApi::declaredFlags());
    }

    public function testAFlagTwoExtensionsDeclareIsTheSecondOnesMistake(): void
    {
        (new ExtensionApi('/work', 'a.php', 'a'))->registerFlag('shared');

        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn (): mixed => (new ExtensionApi('/work', 'b.php', 'b'))->registerFlag('shared'),
            'already declared',
        );
    }

    // ---- the session from an extension's side -------------------------------------------

    public function testTheSettingsAndTheSessionAreReachableOnceWired(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');

        $this->assertNull($api->getSettings());
        $this->assertNull($api->getModel(), 'no session yet');
        $this->assertFalse($api->setModel(Models::get('claude-sonnet-4-5') ?? throw new \RuntimeException('no sonnet')), 'nothing to switch');

        $settings = Settings::inMemory(['theme' => 'light']);
        ExtensionApi::useSettings($settings);
        $this->assertSame('light', $api->getSettings()?->theme());

        $runner = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work');
        $session = $this->session($runner);
        $api->withContext(static fn () => $runner->context());

        $this->assertSame('claude-sonnet-4-5', $api->getModel()?->id);
        $this->assertSame(ThinkingLevel::Off, $api->getThinkingLevel());

        $api->setThinkingLevel(ThinkingLevel::High);
        $this->assertSame(ThinkingLevel::High, $session->thinkingLevel());

        $session->dispose();
    }

    public function testSendUserMessageStartsATurnWhenIdle(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $runner = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work');
        $session = $this->session($runner);
        $api->withContext(static fn () => $runner->context());

        Async::run(static function () use ($api): void {
            $api->sendUserMessage('from the extension');
        });

        $messages = $session->messages();
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('from the extension', $messages[0]->content[0]->text);

        $session->dispose();
    }

    // ---- helpers ------------------------------------------------------------------------

    private function session(HookRunner $hooks): AgentSession
    {
        $agent = new Agent(new AgentOptions(
            streamFn: static function (Model $model, TranscriptContext $context): \Pig\Ai\Utils\AssistantMessageEventStream {
                $stream = new \Pig\Ai\Utils\AssistantMessageEventStream();
                $builder = new \Pig\Ai\Providers\AssistantMessageBuilder($model);
                Async::spawn(static function () use ($stream, $builder): void {
                    $i = $builder->startText(0);
                    $builder->append($i, 'text', 'ok');
                    $m = $builder->snapshot();
                    $stream->push(new \Pig\Ai\DoneEvent($m->stopReason, $m));
                    $stream->end();
                });

                return $stream;
            },
        ));
        $agent->setModel(Models::get('claude-sonnet-4-5') ?? throw new \RuntimeException('no sonnet'));

        $session = new AgentSession($agent, '/work', null, Settings::inMemory(), $hooks);
        $hooks->setSession($session);

        return $session;
    }
}
