<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Rpc\RpcUi;

/**
 * The page's half of an extension's `$ctx->ui`: `hooks.js` run under Node against the requests
 * `RpcUi` really sends — widgets above and below the prompt, statuses, the title, the levels of
 * `notify`, a dialog's countdown — so the page and the protocol cannot drift apart unseen.
 */
final class WebHookUiTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Loop::reset();
    }

    public function testThePageKeepsWhatTheTerminalWouldDraw(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $sent = [];
        $ui = new RpcUi(static function (array $line) use (&$sent): void {
            $sent[] = $line;
        });

        $ui->setWidget('progress', ['step 1 of 3']);
        $ui->setWidget('plain', ['one', 'two'], ['placement' => 'above']);
        $ui->setWidget('colour', ["\e[31mred\e[39m text"], ['placement' => 'below']);
        $ui->setWidget('gone', ['soon cleared']);
        $ui->setWidget('gone', null);
        $ui->setWidget('progress', ['step 2 of 3']);
        $ui->setStatus('mcp', 'mcp: 2 servers');
        $ui->setStatus('build', 'building');
        $ui->setStatus('build', null);
        $ui->setStatus('lint', "\e[32mlint ok\e[39m");
        $ui->setTitle('pig — building');

        $notifies = [];
        foreach (['info', 'warning', 'error'] as $level) {
            $ui->notify("said at {$level}", $level);
            $notifies[] = end($sent);
        }

        // Two dialogs, asked and left unanswered: only the request each sends matters here.
        Async::spawn(static fn () => $ui->select('Pick one', ['a', 'b'], 1500));
        Async::spawn(static fn () => $ui->confirm('Sure?', 'really'));
        Loop::get()->delay(0.0, static fn () => null);
        Loop::get()->tick();

        $fixture = [
            'requests' => $sent,
            'notifies' => $notifies,
            // `RpcMode::commands()`' shape: an extension's command, a prompt template, a skill.
            'commands' => ['commands' => [
                ['name' => 'deploy', 'description' => 'Ship it', 'source' => 'extension'],
                ['name' => 'review', 'description' => 'Review a file', 'source' => 'prompt'],
                ['name' => 'skill:release', 'description' => 'Cut a release', 'source' => 'skill'],
                ['name' => '', 'source' => 'extension'],
            ]],
        ];

        $this->assertContains('select', array_column($sent, 'method'), 'the dialogs were asked');

        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/web-hook-ui.mjs')
            . ' ' . escapeshellarg((string) realpath(__DIR__ . '/../src/Web/assets/js'))
            . ' ' . escapeshellarg(base64_encode(json_encode($fixture, JSON_THROW_ON_ERROR))) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web hook ui: passed', implode("\n", $out));
    }
}
