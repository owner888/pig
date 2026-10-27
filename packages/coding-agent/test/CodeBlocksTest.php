<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\CodeBlocks;

final class CodeBlocksTest extends TestCase
{
    public function testEachFencedBlockWithItsLanguageLinesAndFirstLine(): void
    {
        $blocks = CodeBlocks::extract("Two ways:\n\n```php\n\$a = 1;\necho \$a;\n```\n\nor\n\n~~~bash\nls -la\n~~~\n");

        $this->assertSame([
            ['language' => 'php', 'code' => "\$a = 1;\necho \$a;", 'lines' => 2, 'preview' => '$a = 1;'],
            ['language' => 'bash', 'code' => 'ls -la', 'lines' => 1, 'preview' => 'ls -la'],
        ], $blocks);
    }

    public function testALongerFenceKeepsAShorterOneInsideItAsCode(): void
    {
        // A ```` block showing a ``` block is one block, which a regular expression stopping at the
        // first ``` would have cut in two.
        $blocks = CodeBlocks::extract("````markdown\n```js\nx()\n```\n````");

        $this->assertCount(1, $blocks);
        $this->assertSame("```js\nx()\n```", $blocks[0]['code']);
        $this->assertSame('markdown', $blocks[0]['language']);
    }

    public function testABlockInAListComesBackWithoutTheListsIndentation(): void
    {
        $blocks = CodeBlocks::extract("1. Run it:\n\n   ```sh\n   make\n     test\n   ```\n");

        $this->assertSame("make\n  test", $blocks[0]['code']);
    }

    public function testNoLanguageIsCodeAnEmptyBlockIsLeftOutAndAnOpenOneRunsToTheEnd(): void
    {
        $blocks = CodeBlocks::extract("```\n\n```\n\n```\nplain\n```\n\n```py {title=x}\nprint(1)");

        $this->assertSame(['code', 'py'], array_column($blocks, 'language'));
        $this->assertSame('print(1)', $blocks[1]['code']);
    }

    public function testInlineBackticksAreNotAFence(): void
    {
        $this->assertSame([], CodeBlocks::extract("Use ```this``` inline, and `that`."));
    }

    public function testALongFirstLineIsShortenedForTheList(): void
    {
        $blocks = CodeBlocks::extract("```\n" . str_repeat('x', 80) . "\n```");

        $this->assertSame(str_repeat('x', 50) . '…', $blocks[0]['preview']);
    }
}
