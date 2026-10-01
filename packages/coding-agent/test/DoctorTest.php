<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Models;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Doctor\DoctorReport;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Palette;

final class DoctorTest extends TestCase
{
    private function session(): AgentSession
    {
        $agent = new Agent(new AgentOptions(apiKey: 'test-key'));
        $model = Models::get('gemini-2.5-flash-lite');
        if ($model !== null) {
            $agent->setModel($model);
        }

        return new AgentSession($agent, sys_get_temp_dir());
    }

    public function testInspectCollectsAllFiveDiagnosticSections(): void
    {
        $session = $this->session();
        $auth = Auth::inMemory();
        $report = Doctor::inspect($session, $auth);

        $this->assertInstanceOf(DoctorReport::class, $report);

        // 1. PHP Runtime
        $this->assertSame(PHP_VERSION, $report->php['version']);
        $this->assertTrue($report->php['ok']);
        $this->assertArrayHasKey('ext-mbstring', $report->php['extensions']);
        $this->assertArrayHasKey('ext-openssl', $report->php['extensions']);

        // 2. Binaries
        $this->assertArrayHasKey('stty', $report->binaries);
        $this->assertArrayHasKey('git', $report->binaries);
        $this->assertArrayHasKey('fd', $report->binaries);
        $this->assertArrayHasKey('rg', $report->binaries);

        // 3. Auth
        $this->assertArrayHasKey('providers', $report->auth);
        $this->assertArrayHasKey('antigravityAccounts', $report->auth);

        // 4. Proxy
        $this->assertArrayHasKey('enabled', $report->proxy);

        // 5. Session
        $this->assertSame(sys_get_temp_dir(), $report->session['cwd']);
        $this->assertArrayHasKey('model', $report->session);
        $this->assertArrayHasKey('provider', $report->session);
    }

    public function testRenderTuiFormatsColorizedSectionsWithMarks(): void
    {
        $session = $this->session();
        $auth = Auth::inMemory();
        $report = Doctor::inspect($session, $auth);

        $palette = Palette::dark(true);
        $output = Doctor::renderTui($report, $palette);

        $this->assertStringContainsString('[1. PHP Runtime & Extensions]', $output);
        $this->assertStringContainsString('[2. External Binaries & Tooling]', $output);
        $this->assertStringContainsString('[3. Model Providers & Credentials]', $output);
        $this->assertStringContainsString('[4. Network & Proxy]', $output);
        $this->assertStringContainsString('[5. Active Session State]', $output);
    }

    public function testRenderPlainFormatsTextWithoutAnsiCodes(): void
    {
        $session = $this->session();
        $auth = Auth::inMemory();
        $report = Doctor::inspect($session, $auth);

        $plain = Doctor::renderPlain($report);

        $this->assertStringNotContainsString("\e[", $plain);
        $this->assertStringContainsString('[1. PHP Runtime & Extensions]', $plain);
        $this->assertStringContainsString('[2. External Binaries & Tooling]', $plain);
        $this->assertStringContainsString('[3. Model Providers & Credentials]', $plain);
        $this->assertStringContainsString('[4. Network & Proxy]', $plain);
        $this->assertStringContainsString('[5. Active Session State]', $plain);
    }
}
