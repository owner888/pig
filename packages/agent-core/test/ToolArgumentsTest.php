<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Agent\InvalidToolArguments;
use Pig\Agent\ToolArguments;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;

/**
 * Upstream's `normalizeOptionalNulls()` and `coerceWithJsonSchema()`, the two steps
 * `validateToolArguments()` runs before the schema: what a strict-sampled call's nulls become,
 * what a mistyped primitive is bent into, and what neither touches.
 */
final class ToolArgumentsTest extends TestCase
{
    public function testANullForAnOptionalParameterIsDropped(): void
    {
        $this->assertSame(['path' => 'a'], $this->validate(['path' => 'a', 'offset' => null, 'limit' => null]));
    }

    public function testANullForARequiredParameterIsNotDroppedButCoerced(): void
    {
        // Only an *optional* parameter's null means "left out". A required one is kept — and then
        // coerced like any other value: upstream's `coercePrimitiveByType(null, "string")` is "".
        // This test used to expect "path: must be string"; that was before the coercion step was
        // ported, and upstream does not refuse it either. A plain JSON schema — an extension's, an
        // MCP server's — and so no `Value.Convert` first; a built-in tool's gives "null" instead,
        // see `testABuiltInToolsArgumentsAreConvertedTheWayTypeBoxConvertsThem`.
        $this->assertSame(['path' => ''], $this->validate(['path' => null]));
    }

    /**
     * Upstream's built-in tools are TypeBox schemas, and `validateToolArguments()` runs
     * `Value.Convert` over their arguments before anything else touches them; that conversion is not
     * `coerceWithJsonSchema()`'s, and pig used to give the built-ins the latter only. Every expected
     * value here is what upstream's own `validateToolArguments()` returned under typebox 1.3.27 (the
     * version it pins) for the same schema built with `Type.Object(…)`.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|string $expected the arguments, or the line of the error
     */
    #[DataProvider('typeBoxConversions')]
    public function testABuiltInToolsArgumentsAreConvertedTheWayTypeBoxConvertsThem(array $arguments, array|string $expected): void
    {
        $tool = new Tool('t', 'A built-in', [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string'],
                'ignoreCase' => ['type' => 'boolean'],
                'context' => ['type' => 'number'],
                'whole' => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'gone' => ['type' => 'null'],
            ],
            'required' => ['pattern'],
        ], typeBox: true);

        if (is_string($expected)) {
            $this->expectException(InvalidToolArguments::class);
            $this->expectExceptionMessage($expected);
        }

        $this->assertSame($expected, ToolArguments::validate($tool, new ToolCall('c1', 't', $arguments)));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>|string}> */
    public static function typeBoxConversions(): iterable
    {
        // `TryString`: null is "null" — not the "" the plain-schema coercion makes of it.
        yield 'required string null' => [['pattern' => null], ['pattern' => 'null']];
        yield 'string from int' => [['pattern' => 5], ['pattern' => '5']];
        yield 'string from float' => [['pattern' => 1.5], ['pattern' => '1.5']];
        yield 'string from false' => [['pattern' => false], ['pattern' => 'false']];
        // `TryNumber`: JS's unary `+`, so "" is 0; then "true"/"false" in any case; then `123n`.
        yield 'number from empty' => [['pattern' => 'x', 'context' => ''], ['pattern' => 'x', 'context' => 0]];
        yield 'number from padded' => [['pattern' => 'x', 'context' => ' 10 '], ['pattern' => 'x', 'context' => 10]];
        yield 'number from hex' => [['pattern' => 'x', 'context' => '0x10'], ['pattern' => 'x', 'context' => 16]];
        yield 'number from exponent' => [['pattern' => 'x', 'context' => '1e3'], ['pattern' => 'x', 'context' => 1000.0]];
        yield 'number from TRUE' => [['pattern' => 'x', 'context' => 'TRUE'], ['pattern' => 'x', 'context' => 1]];
        yield 'number from bigint' => [['pattern' => 'x', 'context' => '12n'], ['pattern' => 'x', 'context' => 12]];
        yield 'number from true' => [['pattern' => 'x', 'context' => true], ['pattern' => 'x', 'context' => 1]];
        // `FromInteger`: `Math.trunc()`.
        yield 'integer from decimal string' => [['pattern' => 'x', 'whole' => '2.7'], ['pattern' => 'x', 'whole' => 2]];
        yield 'integer from float' => [['pattern' => 'x', 'whole' => 2.7], ['pattern' => 'x', 'whole' => 2]];
        yield 'integer from negative' => [['pattern' => 'x', 'whole' => '-2.7'], ['pattern' => 'x', 'whole' => -2]];
        // `TryBoolean`: "true"/"false" in any case, and "1"/"0" and 1/0.
        yield 'boolean from TRUE' => [['pattern' => 'x', 'ignoreCase' => 'TRUE'], ['pattern' => 'x', 'ignoreCase' => true]];
        yield 'boolean from "1"' => [['pattern' => 'x', 'ignoreCase' => '1'], ['pattern' => 'x', 'ignoreCase' => true]];
        yield 'boolean from "0"' => [['pattern' => 'x', 'ignoreCase' => '0'], ['pattern' => 'x', 'ignoreCase' => false]];
        yield 'boolean from 1' => [['pattern' => 'x', 'ignoreCase' => 1], ['pattern' => 'x', 'ignoreCase' => true]];
        yield 'boolean from 2' => [['pattern' => 'x', 'ignoreCase' => 2], 'ignoreCase: must be boolean'];
        yield 'boolean from yes' => [['pattern' => 'x', 'ignoreCase' => 'yes'], 'ignoreCase: must be boolean'];
        // `TryArray`: a lone value is wrapped, then each item converted.
        yield 'array from a lone value' => [['pattern' => 'x', 'tags' => 'one'], ['pattern' => 'x', 'tags' => ['one']]];
        yield 'array items converted' => [['pattern' => 'x', 'tags' => [1, true]], ['pattern' => 'x', 'tags' => ['1', 'true']]];
        // `TryNull`.
        yield 'null from NULL' => [['pattern' => 'x', 'gone' => 'NULL'], ['pattern' => 'x', 'gone' => null]];
        yield 'null from 0' => [['pattern' => 'x', 'gone' => 0], ['pattern' => 'x', 'gone' => null]];
    }

    public function testAValueNoCoercionCanFixIsStillAnError(): void
    {
        // An array for a string has no conversion, so the schema answers it as it always did.
        $this->expectException(InvalidToolArguments::class);
        $this->expectExceptionMessage('path: must be string');

        $this->validate(['path' => ['a']]);
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
            // `['a']` and not `7`, which is now coerced to "7" and accepted.
            $this->validate(['path' => ['a'], 'limit' => null]);
            $this->fail('expected the call to be refused');
        } catch (InvalidToolArguments $error) {
            $this->assertStringContainsString('"limit": null', $error->getMessage());
        }
    }

    // ---- coercion: upstream's `coerceWithJsonSchema()` -----------------------------------------

    public function testAStringOfDigitsIsTheNumberTheSchemaAskedFor(): void
    {
        // The everyday case: a model that writes every argument as a string. This used to be
        // refused with "must be number" and cost a round trip for nothing.
        $this->assertSame(
            ['count' => 10, 'ratio' => 1.5, 'whole' => 3],
            $this->coerce(['count' => '10', 'ratio' => '1.5', 'whole' => '3']),
        );
    }

    public function testBooleansAndStringsAreBentTheWaysUpstreamBendsThem(): void
    {
        // "true"/"false" and 1/0 to a boolean; a number or a boolean to a string, written as JS
        // writes it (no ".0" on a whole number); true/false to 1/0 for a number.
        $this->assertSame(
            ['flag' => true, 'other' => false, 'label' => '5', 'note' => 'true', 'count' => 1],
            $this->coerce(['flag' => 'true', 'other' => 0, 'label' => 5, 'note' => true, 'count' => true]),
        );
    }

    public function testAValueThatAlreadyFitsOneTypeOfAUnionIsLeftAlone(): void
    {
        // `type: ["string", "number"]` with "5": a string is one of the types, so nothing is
        // converted — upstream's `matchesUnionMember`. And `anyOf` likewise: a branch that already
        // accepts the value wins before any branch is coerced for.
        $this->assertSame(['either' => '5', 'any' => '7'], $this->coerce(['either' => '5', 'any' => '7']));
    }

    public function testAUnionThatFitsNoneIsCoercedForTheFirstTypeThatChangesIt(): void
    {
        // `["integer", "null"]` with "4": not an integer, not null — the integer coercion is the
        // first to change it. `anyOf: [{integer}, {boolean}]` with "false": the integer branch
        // cannot take it, the boolean one can once coerced.
        $this->assertSame(['maybe' => 4, 'mixed' => false], $this->coerce(['maybe' => '4', 'mixed' => 'false']));
    }

    public function testCoercionReachesIntoArraysAndAdditionalProperties(): void
    {
        $this->assertSame(
            ['lines' => [1, 2], 'pair' => ['true', 3], 'extra' => ['a' => 1, 'b' => 2]],
            $this->coerce(['lines' => ['1', '2'], 'pair' => [true, '3'], 'extra' => ['a' => '1', 'b' => '2']]),
        );
    }

    public function testWhatCannotBeConvertedIsRefusedShowingWhatTheModelSent(): void
    {
        // "abc" is no number and "2.5" no integer, so both reach the schema as they were; the
        // message prints the model's own arguments, as upstream prints `toolCall.arguments` —
        // the "10" that *was* coerced is shown as the string it arrived as.
        try {
            $this->coerce(['count' => 'abc', 'whole' => '2.5', 'ratio' => '10']);
            $this->fail('expected the call to be refused');
        } catch (InvalidToolArguments $error) {
            $this->assertStringContainsString('count: must be number', $error->getMessage());
            $this->assertStringContainsString('whole: must be integer', $error->getMessage());
            $this->assertStringContainsString('"ratio": "10"', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function coerce(array $arguments): array
    {
        $tool = new Tool('t', 'A tool', [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'number'],
                'ratio' => ['type' => 'number'],
                'whole' => ['type' => 'integer'],
                'flag' => ['type' => 'boolean'],
                'other' => ['type' => 'boolean'],
                'label' => ['type' => 'string'],
                'note' => ['type' => 'string'],
                'either' => ['type' => ['string', 'number']],
                'any' => ['anyOf' => [['type' => 'number'], ['type' => 'string']]],
                'maybe' => ['type' => ['integer', 'null']],
                'mixed' => ['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]],
                'lines' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'pair' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'number']]],
                'extra' => ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
            ],
        ]);

        return ToolArguments::validate($tool, new ToolCall('c1', 't', $arguments));
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
