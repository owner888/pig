<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * `AGENTS.md` is sent with every request made in this repository, so its size is a cost every
 * turn pays. It reached 13,658 lines as `CLAUDE.md` — about 280k tokens, more than a local
 * model's whole window — one "just one more entry" at a time. The rule at its top says 200
 * lines; this is what keeps the rule.
 */
final class AgentsFileTest extends TestCase
{
    private const int MAX_LINES = 200;

    public function testTheAgentsFileStaysUnderTwoHundredLines(): void
    {
        $file = dirname(__DIR__, 3) . '/AGENTS.md';
        $this->assertFileExists($file);

        $lines = count(file($file) ?: []);

        $this->assertLessThanOrEqual(
            self::MAX_LINES,
            $lines,
            "AGENTS.md is {$lines} lines. Move what is not needed by every task into docs/dev/ and leave an index entry.",
        );
    }
}
