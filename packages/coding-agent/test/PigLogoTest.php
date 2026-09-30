<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\PigLogo;
use Pig\Tui\Ansi;
use Pig\Tui\Width;

final class PigLogoTest extends TestCase
{
    public function testReturnsTwoLines(): void
    {
        $lines = PigLogo::lines();

        $this->assertCount(2, $lines);
        $this->assertNotEmpty($lines[0]);
        $this->assertNotEmpty($lines[1]);
    }

    public function testBothLinesAreExactlyFourColumnsWide(): void
    {
        [$top, $bottom] = PigLogo::lines();

        // 4 cells wide in the terminal
        $this->assertSame(4, Width::visible($top));
        $this->assertSame(4, Width::visible($bottom));
    }

    public function testLogoUsesHalfBlocks(): void
    {
        [$top, $bottom] = PigLogo::lines();

        $this->assertStringContainsString('▀', $top);
        $this->assertStringContainsString('▀', $bottom);
    }
}
