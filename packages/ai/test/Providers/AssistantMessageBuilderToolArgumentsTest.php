<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\Utils\PartialJson;

/**
 * The builder reads a tool call's argument deltas once each (see `StreamingJson`), and a custom
 * tool's input without reading it at all. What a finished call carries must not change for that.
 */
final class AssistantMessageBuilderToolArgumentsTest extends TestCase
{
    public function testAStreamedCallFinishesWithWhatTheWholeTextSays(): void
    {
        $json = (string) json_encode(['path' => '/tmp/a.php', 'content' => "line\n\t\"quoted\" é 中 😀\n", 'n' => 1.5]);
        $builder = self::builder();
        $index = $builder->startToolCall(0, 'call', 'write');

        foreach (str_split($json, 3) as $piece) {
            $builder->append($index, 'json', $piece);
            $sofar = $builder->jsonOf($index);

            // What the reparse showed, except where the reader shows more: at an escape cut short
            // or a character cut in half, which the reparse could not decode.
            if (mb_check_encoding($sofar, 'UTF-8') && preg_match('/\\\\(u[0-9a-fA-F]{0,3})?\z/', $sofar) !== 1) {
                $this->assertSame(PartialJson::parse($sofar), $builder->toolCallOf($index)->arguments, $sofar);
            }
        }

        $this->assertSame(json_decode($json, true), $builder->toolCallOf($index)->arguments);
        $builder->finishToolCall($index);
        $this->assertSame(json_decode($json, true), $builder->toolCallOf($index)->arguments);
        $this->assertSame($json, $builder->jsonOf($index));
    }

    public function testDeltasAfterASeedAreReadWithTheSeed(): void
    {
        $builder = self::builder();
        $index = $builder->startToolCall(0, 'call', 'read');
        $builder->seedJson($index, '{"pa');
        $this->assertSame([], $builder->toolCallOf($index)->arguments);

        $builder->append($index, 'json', 'th":"/x"');

        $this->assertSame(['path' => '/x'], $builder->toolCallOf($index)->arguments);
    }

    public function testDeltasAfterSetJsonContinueIt(): void
    {
        $builder = self::builder();
        $index = $builder->startToolCall(0, 'call', 'read');
        $builder->setJson($index, '{"a":1,');
        $builder->append($index, 'json', '"b":2}');

        $this->assertSame(['a' => 1, 'b' => 2], $builder->toolCallOf($index)->arguments);
    }

    /**
     * `setCustomInput()` against what the providers did before it: the input as JSON through
     * `setJson()`. The same arguments and, once asked for, the same JSON — invalid UTF-8 included.
     */
    public function testACustomInputIsWhatItsJsonWouldSay(): void
    {
        foreach (['', 'plain', "*** Begin Patch\n+ \"q\" é 中 \\ /path\n", "bad \xFF byte", '12'] as $input) {
            $old = self::builder();
            $oldIndex = $old->startToolCall(0, 'call', 'apply_patch');
            $object = new \stdClass();
            $object->input = $input;
            $old->setJson($oldIndex, (string) json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            $new = self::builder();
            $newIndex = $new->startToolCall(0, 'call', 'apply_patch');
            $new->setCustomInput($newIndex, 'input', $input);

            $this->assertSame($old->toolCallOf($oldIndex)->arguments, $new->toolCallOf($newIndex)->arguments, $input);
            $this->assertSame($old->jsonOf($oldIndex), $new->jsonOf($newIndex), $input);

            $old->finishToolCall($oldIndex);
            $new->finishToolCall($newIndex);
            $this->assertEquals($old->toolCallOf($oldIndex), $new->toolCallOf($newIndex), $input);
        }
    }

    private static function builder(): AssistantMessageBuilder
    {
        return new AssistantMessageBuilder(new Model('m', 'M', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 1000, 100));
    }
}
