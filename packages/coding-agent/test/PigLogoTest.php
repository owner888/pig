<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\PigLogo;
use Pig\Tui\Ansi;

final class PigLogoTest extends TestCase
{
    public function testTheWordmarkReadsPig(): void
    {
        $this->assertSame('Pig', Ansi::strip(PigLogo::wordmark()));
    }

    public function testEachLetterHasItsOwnColourAndTheColourEnds(): void
    {
        $wordmark = PigLogo::wordmark();

        $this->assertSame(3, substr_count($wordmark, "\x1b[0m"));
        $this->assertStringEndsWith("g\x1b[0m", $wordmark);
    }
}
