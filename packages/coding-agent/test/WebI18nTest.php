<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * The web page's two languages (`assets/js/i18n.js`) have the same keys, and every key the page
 * uses — `t()`, `data-i18n`, `data-i18n-attr` — is in both. Run under Node against the real files.
 */
final class WebI18nTest extends TestCase
{
    public function testEveryKeyThePageUsesIsInBothLanguages(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/web-i18n.mjs')
            . ' ' . escapeshellarg((string) realpath(__DIR__ . '/../src/Web/assets')) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web i18n: passed', implode("\n", $out));
    }
}
