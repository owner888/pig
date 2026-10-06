<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Tools\ToolSelection;

/**
 * `--tools` and `--exclude-tools` entries, read the way upstream's `createToolNameMatcher()` reads
 * them: a name, or a pattern where `*` is the only thing that means anything.
 */
final class ToolSelectionTest extends TestCase
{
    public function testANameIsExactAndAStarMatchesAnything(): void
    {
        $selection = new ToolSelection(['read', 'zoo_*', '*_search']);

        $this->assertTrue($selection->matches('read'));
        $this->assertFalse($selection->matches('reader'), 'a name is not a prefix');
        $this->assertTrue($selection->matches('zoo_feed'));
        $this->assertTrue($selection->matches('zoo_'), 'a star matches nothing at all too');
        $this->assertTrue($selection->matches('web_search'));
        $this->assertFalse($selection->matches('bash'));
    }

    public function testOnlyTheStarIsSpecial(): void
    {
        // A tool name has no business containing regex punctuation, so none of it is read as any:
        // `mcp__a.b__x` is a literal dot and `[` is a literal bracket.
        $this->assertTrue((new ToolSelection(['mcp__a.b__*']))->matches('mcp__a.b__x'));
        $this->assertFalse((new ToolSelection(['mcp__a.b__*']))->matches('mcp__aXb__x'));
        $this->assertTrue((new ToolSelection(['t[1]']))->matches('t[1]'));
        $this->assertFalse((new ToolSelection(['t?']))->matches('tx'), 'no `?`');
    }

    public function testAnAllowlistKeepsMcpToolsUnlessAnEntryNamesThem(): void
    {
        // `--tools read` means "that built-in", not "and no MCP servers". Upstream's 1.0.3 rule.
        $plain = new ToolSelection(['read']);
        $this->assertTrue($plain->allows('mcp__gh__issues'));
        $this->assertTrue($plain->allows('read_mcp_resource'), 'the resource tools count as MCP too');
        $this->assertFalse($plain->allows('bash'));

        // One `mcp__` entry and the exception is off: now only what matches stays.
        $narrowed = new ToolSelection(['read', 'mcp__gh__*']);
        $this->assertTrue($narrowed->allows('mcp__gh__issues'));
        $this->assertFalse($narrowed->allows('mcp__fs__read'));
        $this->assertFalse($narrowed->allows('read_mcp_resource'));
    }

    public function testADenylistHasNoMcpException(): void
    {
        // `matches()` is the plain question, which is the one `--exclude-tools` asks.
        $this->assertTrue((new ToolSelection(['mcp__*']))->matches('mcp__gh__issues'));
        $this->assertFalse((new ToolSelection(['bash']))->matches('mcp__gh__issues'));
    }

    public function testUnmatchedNamesTheTyposAndNotTheMcpEntries(): void
    {
        $selection = new ToolSelection(['read', 'raed', 'zoo_*', 'nope_*', 'mcp__later__*']);

        // An `mcp__` entry is not a typo even when nothing matches it yet: its server connects
        // after startup.
        $this->assertSame(['raed', 'nope_*'], $selection->unmatched(['read', 'zoo_feed']));
    }
}
