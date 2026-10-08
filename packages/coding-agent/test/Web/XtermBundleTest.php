<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;

/**
 * The vendored xterm.js build (5.5's ES module) as shipped had `requestMode()` — the DECRQM
 * answer, `CSI ? Ps $ p` — assign an undeclared `i` for its enum. A module is strict code, so the
 * first mode request threw ReferenceError inside the parser and the write it was in stopped there.
 * vim asks for modes as it starts (cursor blink, `?12$p`), so its screen never came up and
 * nothing typed after it was drawn. The bundle is patched to give the enum a plain `{}`.
 */
final class XtermBundleTest extends TestCase
{
    public function testTheModeRequestAnswerDoesNotAssignAnUndeclaredName(): void
    {
        $bundle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Web/assets/js/vendor/xterm.js');

        $this->assertStringContainsString('requestMode(e,t){', $bundle);
        $this->assertStringNotContainsString('(void 0||(i={}))', $bundle);
    }
}
