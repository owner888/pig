<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Keys;

final class KeysTest extends TestCase
{
    public function testKittyModifierDetection(): void
    {
        // Enter: 13, Shift: modifier bit 1, value 2 -> \e[13;2u
        $this->assertTrue(Keys::isShiftEnter("\x1b[13;2u"));
        $this->assertFalse(Keys::isShiftEnter("\r"));
        $this->assertFalse(Keys::isShiftEnter("\x1b[13;9u"));

        // Enter: 13, Super (Cmd): modifier bit 8, value 9 -> \e[13;9u
        $this->assertTrue(Keys::isCmdEnter("\x1b[13;9u"));
        $this->assertFalse(Keys::isCmdEnter("\r"));
        $this->assertFalse(Keys::isCmdEnter("\x1b[13;2u"));

        // Enter: 13, Alt: modifier bit 2, value 3 -> \e[13;3u
        $this->assertTrue(Keys::isAltEnter("\x1b[13;3u"));
        $this->assertTrue(Keys::isAltEnter("\x1b\r"));
    }

    public function testMatchesNameSupportsCommandAndShiftCombinations(): void
    {
        $this->assertTrue(Keys::matchesName("\x1b[13;9u", 'command+enter'));
        $this->assertTrue(Keys::matchesName("\x1b[13;9u", 'cmd+enter'));
        $this->assertTrue(Keys::matchesName("\x1b[13;9u", 'super+enter'));
        $this->assertTrue(Keys::matchesName("\x1b[13;9u", 'meta+enter'));

        $this->assertTrue(Keys::matchesName("\x1b[13;2u", 'shift+enter'));
        $this->assertTrue(Keys::matchesName("\x1b[13;3u", 'alt+enter'));
        $this->assertTrue(Keys::matchesName("\x1b\r", 'alt+enter'));
    }
}
