<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\WebMode;

final class WebModeTest extends TestCase
{
    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-web-test-' . bin2hex(random_bytes(6));
        mkdir($this->cwd, 0755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->cwd)) {
            $this->rmrf($this->cwd);
        }
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function session(): AgentSession
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));

        return new AgentSession($agent, $this->cwd);
    }

    public function testServesIndexHtmlAndApiEndpoints(): void
    {
        $port = 28088;
        $session = $this->session();
        $server = new HttpServer($session, $port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $this->assertTrue($server->isRunning());

            $http = new HttpClient(2.0);

            // 1. GET /
            $respIndex = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/"));
            $this->assertSame(200, $respIndex->status);
            $this->assertStringContainsString('<!DOCTYPE html>', $respIndex->body->all());

            // 2. GET /api/state
            $respState = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/state"));
            $this->assertSame(200, $respState->status);
            $stateData = json_decode($respState->body->all(), true);
            $this->assertIsArray($stateData);
            $this->assertArrayHasKey('tokensIn', $stateData);

            // 3. GET /api/messages
            $respMsgs = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/messages"));
            $this->assertSame(200, $respMsgs->status);
            $this->assertSame('[]', trim($respMsgs->body->all()));

            // 4. POST /api/abort
            $respAbort = $http->follow(new Request('POST', "http://127.0.0.1:{$port}/api/abort"));
            $this->assertSame(200, $respAbort->status);

            // Stop server
            $server->stop();
            $this->assertFalse($server->isRunning());
        });
    }

    public function testWebModeInstantiatesAndExposesStop(): void
    {
        $session = $this->session();
        $mode = new WebMode($session, 28089);
        $this->assertSame(28089, $mode->port);
        $mode->stop();
    }
}
