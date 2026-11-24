<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * Enter in the web prompt: it sends from a hardware keyboard and breaks the line on an on-screen
 * one, which has no Shift (`assets/js/keyboard.js`). Run under Node against the real file.
 */
final class WebKeyboardTest extends TestCase
{
    public function testEnterBreaksTheLineOnlyOnAnOnScreenKeyboard(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/web-keyboard.mjs')
            . ' ' . escapeshellarg((string) realpath(__DIR__ . '/../src/Web/assets')) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web keyboard: passed', implode("\n", $out));

        $app = (string) file_get_contents(__DIR__ . '/../src/Web/assets/js/app.js');
        $this->assertStringContainsString('!onScreenKeyboard()', $app, 'the prompt asks before Enter sends');
    }
}
