<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * Opening a conversation, the folder picker, the session list, a terminal drawer or the login page
 * must not focus an input on a phone or touch screen, where focus brings up the on-screen keyboard
 * over half a small viewport before anything is read.
 */
final class WebAutoFocusTest extends TestCase
{
    public function testInitialFocusGoesThroughMobileGuard(): void
    {
        $assets = dirname(__DIR__) . '/src/Web/assets';
        $js = $assets . '/js';

        foreach (['index.html', 'login.html'] as $html) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bautofocus\b/i',
                (string) file_get_contents($assets . '/' . $html),
                "$html must not carry a static autofocus attribute.",
            );
        }

        $app = (string) file_get_contents($js . '/app.js');
        $this->assertMatchesRegularExpression('/function openTab\(tab\)\s*\{[\s\S]*?autoFocus\(promptInput\);\s*\}/', $app);
        $this->assertMatchesRegularExpression('/function openSessionSearch\(\)\s*\{[\s\S]*?autoFocus\(input\);/', $app);

        $cwd = (string) file_get_contents($js . '/components/CwdPicker.js');
        $this->assertStringContainsString('autoFocus(input);', $cwd);
        $this->assertStringNotContainsString('input.focus();', $cwd);

        $term = (string) file_get_contents($js . '/components/WebTerminal.js');
        $this->assertStringContainsString('autoFocus(tab.term);', $term);
        $this->assertStringContainsString('autoFocus(tab?.term);', $term);

        $login = (string) file_get_contents($js . '/login.js');
        $this->assertDoesNotMatchRegularExpression('/^\s*name\.focus\(\);/m', $login);
        $this->assertStringContainsString('(hover: none) and (pointer: coarse), (max-width: 768px)', $login);
    }

    public function testAutoFocusSkipsPhonesAndFocusesDesktop(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser module');
        }

        $utilsUrl = 'file://' . realpath(dirname(__DIR__) . '/src/Web/assets/js/utils.js');
        $script = <<<JS
const { autoFocus } = await import({$this->jsString($utilsUrl)});
let calls = 0;
const el = { focus() { calls++; } };

globalThis.window = { matchMedia: () => ({ matches: true }) };
autoFocus(el);
if (calls !== 0) { console.error("focused on mobile"); process.exit(1); }

globalThis.window = { matchMedia: () => ({ matches: false }) };
autoFocus(el);
if (calls !== 1) { console.error("did not focus on desktop"); process.exit(1); }
console.log("WebAutoFocus: passed");
JS;

        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' --input-type=module -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('WebAutoFocus: passed', implode("\n", $out));
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
