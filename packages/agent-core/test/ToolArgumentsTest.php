<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\InvalidToolArguments;
use Pig\Agent\ToolArguments;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;

/**
 * Upstream's `normalizeOptionalNulls()`, the step `validateToolArguments()` runs before the
 * schema: what a strict-sampled call's nulls become, and what they do not.
 */
final class ToolArgumentsTest extends TestCase
{
    public function testANullForAnOptionalParameterIsDropped(): void
    {
        $this->assertSame(['path' => 'a'], $this->validate(['path' => 'a', 'offset' => null, 'limit' => null]));
    }

    public function testANullForARequiredParameterIsStillAnError(): void
    {
        // Only an *optional* parameter's null means "left out". A required one has no such
        // reading, so the schema answers it as it always did.
        $this->expectException(InvalidToolArguments::class);
        $this->expectExceptionMessage('path: must be string');

        $this->validate(['path' => null]);
    }

    public function testAParameterThatAllowsNullKeepsIt(): void
    {
        // Checked against the tool's own schema: one that already takes null meant the null.
        $this->assertSame(['path' => 'a', 'note' => null], $this->validate(['path' => 'a', 'note' => null]));
    }

    public function testNullsAreDroppedInsideNestedObjectsAndArraysToo(): void
    {
        // Upstream recurses through `properties` and `items`, as the strict transform does.
        $this->assertSame(
            ['path' => 'a', 'edits' => [['old' => 'x'], ['old' => 'y', 'new' => 'z']]],
            $this->validate(['path' => 'a', 'edits' => [['old' => 'x', 'new' => null], ['old' => 'y', 'new' => 'z']]]),
        );
    }

    public function testTheErrorShowsTheArgumentsTheModelSent(): void
    {
        // Upstream prints `toolCall.arguments`, not the normalized copy, so the model sees its
        // own call — nulls included — next to what was wrong with it.
        try {
            $this->validate(['path' => 7, 'limit' => null]);
            $this->fail('expected the call to be refused');
        } catch (InvalidToolArguments $error) {
            $this->assertStringContainsString('"limit": null', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function validate(array $arguments): array
    {
        $tool = new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'offset' => ['type' => 'number'],
                'limit' => ['type' => 'number'],
                'note' => ['type' => ['string', 'null']],
                'edits' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['old' => ['type' => 'string'], 'new' => ['type' => 'string']],
                        'required' => ['old'],
                    ],
                ],
            ],
            'required' => ['path'],
        ]);

        return ToolArguments::validate($tool, new ToolCall('c1', 'read', $arguments));
    }
}
