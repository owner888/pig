<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * The web page's sizes are tokens (`--text-*`, `--radius-*` in `style.css`'s `:root`), so a new
 * piece looks like the old ones; docs/dev/design/web-ui.md says which is for what. A font size or
 * a corner written in px — in the stylesheet or in an inline `style` the scripts build — is a size
 * made up for the occasion, which is what drew the scheduled-task pages larger than everything
 * around them.
 */
final class WebStyleTokensTest extends TestCase
{
    public function testFontSizesAndCornersAreTokens(): void
    {
        $assets = dirname(__DIR__) . '/src/Web/assets';
        $files = [
            ...glob($assets . '/css/*.css') ?: [],
            ...glob($assets . '/js/*.js') ?: [],
            ...glob($assets . '/js/components/*.js') ?: [],
            ...glob($assets . '/*.html') ?: [],
        ];
        $this->assertNotEmpty($files);

        $raw = [];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            // The tokens themselves are px, once, in `:root`.
            $text = (string) preg_replace('/^:root \{.*?^\s*\}/ms', '', $text);

            foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
                if (preg_match('/(font-size|border-radius):\s*[\d.]+px/', $line) === 1) {
                    $raw[] = basename($file) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $raw, 'Use a size token (docs/dev/design/web-ui.md) instead of a px value.');
    }
}
