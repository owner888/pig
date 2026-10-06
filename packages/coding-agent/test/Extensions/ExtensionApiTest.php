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
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Events\AfterProviderResponseEvent;
use Pig\CodingAgent\Hooks\Events\BeforeProviderRequestEvent;
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
        HttpClient::observe(null, null);
        $this->server = new CannedServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        ExtensionApi::forgetFlags();
        ExtensionApi::forgetHttpRoutes();
        ExtensionApi::useSettings(null);
        HttpClient::observe(null, null);
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

    public function testAHookSeesEveryRequestAndMayChangeItAndSeesTheAnswer(): void
    {
        // `before_provider_request` is chained — each handler gets what the last one returned —
        // and `after_provider_response` sees the status and headers before the body is read.
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $seen = [];
        $api->on('before_provider_request', static fn (BeforeProviderRequestEvent $e): BeforeProviderRequestResult
            => new BeforeProviderRequestResult(new Request($e->request->method, $e->request->url, [...$e->request->headers, 'x-first' => '1'], $e->request->body)));
        $api->on('before_provider_request', static function (BeforeProviderRequestEvent $e) use (&$seen): BeforeProviderRequestResult {
            $seen['chained'] = $e->request->headers['x-first'] ?? null;

            return new BeforeProviderRequestResult(new Request($e->request->method, $e->request->url, [...$e->request->headers, 'x-second' => '2'], $e->request->body));
        });
        $api->on('after_provider_response', static function (AfterProviderResponseEvent $e) use (&$seen): void {
            $seen['status'] = $e->status;
            $seen['header'] = $e->headers['x-answered'] ?? null;
        });

        $url = $this->server->start(["HTTP/1.1 200 OK\r\nx-answered: yes\r\ncontent-length: 2\r\nconnection: close\r\n\r\nok"]);
        $session = $this->session(new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work'));

        Async::run(function () use ($url): void {
            (new HttpClient())->send(new Request('GET', $url))->body->all();
        });

        $head = $this->server->receivedHead();
        $this->assertStringContainsString('x-first: 1', $head);
        $this->assertStringContainsString('x-second: 2', $head);
        $this->assertSame('1', $seen['chained'], 'the second handler saw the first one\'s header');
        $this->assertSame(200, $seen['status']);
        $this->assertSame('yes', $seen['header']);

        $session->dispose();
    }

    public function testWithNobodyListeningTheClientIsNotObserved(): void
    {
        // A session with no provider hooks pays nothing per request: the observer is only
        // installed when a hook asked for one.
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $api->on('agent_start', static fn (): null => null);
        $runner = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)], '/work');

        $this->assertFalse($runner->listensToProviderTraffic());

        $api->on('after_provider_response', static fn (): null => null);

        $this->assertTrue($runner->listensToProviderTraffic());
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
            streamFn: static function (Model $model, Context $context): \Pig\Ai\Utils\AssistantMessageEventStream {
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
