<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Tool;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\UnsupportedStrictJsonSchema;

/**
 * The strict form of a tool schema, as upstream's `constrained-sampling.ts` builds it.
 *
 * Gemini 3's `VALIDATED` mode is what sends it today; these pin the rewriting itself, which is
 * where a port goes quietly wrong (JS objects vs PHP lists, `undefined` vs a key set to null).
 */
final class ConstrainedSamplingTest extends TestCase
{
    public function testEveryPropertyBecomesRequiredAndAnOptionalOneAlsoTakesNull(): void
    {
        $strict = ConstrainedSampling::makeStrictJsonSchema([
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
                // Already nullable: left as it is rather than wrapped twice.
                'note' => ['type' => ['string', 'null']],
                'edits' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['old' => ['type' => 'string'], 'new' => ['type' => 'string']],
                    'required' => ['old'],
                ]],
            ],
            'required' => ['path', 'edits'],
        ]);

        $this->assertSame(['path', 'limit', 'note', 'edits'], $strict['required'], 'in property order');
        $this->assertFalse($strict['additionalProperties']);
        $this->assertSame(['anyOf' => [['type' => 'integer'], ['type' => 'null']]], $strict['properties']['limit']);
        $this->assertSame(['type' => ['string', 'null']], $strict['properties']['note']);

        // Nested objects get the same treatment, at every depth.
        $item = $strict['properties']['edits']['items'];
        $this->assertSame(['old', 'new'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame(['anyOf' => [['type' => 'string'], ['type' => 'null']]], $item['properties']['new']);
    }

    public function testSchemasWithNoStrictFormAreRefused(): void
    {
        foreach ([
            'oneOf' => ['type' => 'object', 'properties' => ['x' => ['oneOf' => [['type' => 'string']]]]],
            'open object' => ['type' => 'object', 'additionalProperties' => true],
            'object union' => ['type' => 'object', 'properties' => ['x' => ['anyOf' => [['type' => 'object']]]]],
            'unknown required' => ['type' => 'object', 'properties' => [], 'required' => ['ghost']],
            'not an object' => ['type' => 'string'],
        ] as $case => $schema) {
            try {
                ConstrainedSampling::makeStrictJsonSchema($schema);
                $this->fail("{$case}: expected a refusal");
            } catch (UnsupportedStrictJsonSchema) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Upstream #12049: OpenAI strict mode rejects a schema node without a type. */
    public function testANodeWithoutATypeIsRefusedAndABareEnumIsNot(): void
    {
        foreach ([
            'untyped array items' => ['type' => 'object', 'properties' => ['commandArguments' => ['type' => 'array', 'items' => []]]],
            'any value' => ['type' => 'object', 'properties' => ['value' => ['description' => 'Any JSON value']], 'required' => ['value']],
            'bare const' => ['type' => 'object', 'properties' => ['mode' => ['const' => 'fast']], 'required' => ['mode']],
        ] as $case => $schema) {
            $error = null;

            try {
                ConstrainedSampling::makeStrictJsonSchema($schema);
            } catch (UnsupportedStrictJsonSchema $refused) {
                $error = $refused->getMessage();
            }

            $this->assertSame('schemas without a type are unsupported', $error, $case);
        }

        // "keeps strict mode for enum-only schemas"
        $enum = ['type' => 'object', 'properties' => ['level' => ['enum' => ['low', 'high']]], 'required' => ['level']];
        $this->assertSame(['enum' => ['low', 'high']], ConstrainedSampling::makeStrictJsonSchema($enum)['properties']['level']);
        $this->assertTrue(ConstrainedSampling::resolveJsonSchemaStrictSampling(
            new Tool('t', 'd', $enum, ['type' => 'json_schema', 'strict' => 'prefer']),
            true,
        ));
        // With "prefer" an untyped node falls back to a tool that is not strict.
        $untyped = ['type' => 'object', 'properties' => ['value' => ['description' => 'Any JSON value']]];
        $this->assertNull(ConstrainedSampling::resolveJsonSchemaStrictSampling(new Tool('t', 'd', $untyped, ['type' => 'json_schema', 'strict' => 'prefer']), true));
        // And with "require" it fails here, before the provider can refuse it.
        try {
            ConstrainedSampling::resolveJsonSchemaStrictSampling(new Tool('t', 'd', $untyped, ['type' => 'json_schema', 'strict' => 'require']), true);
            $this->fail('expected a refusal');
        } catch (\Pig\Ai\ProviderError $error) {
            $this->assertSame('Tool "t" requires JSON-schema constrained sampling, but schemas without a type are unsupported.', $error->getMessage());
        }
    }

    public function testOnlyAToolThatAsksIsStrict(): void
    {
        // Upstream's default is no: a tool without `constrainedSampling` (or with `false`) goes as
        // written whatever the provider supports.
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

        $this->assertNull(ConstrainedSampling::resolveJsonSchemaStrictSampling(new Tool('t', 'd', $schema), true));
        $this->assertNull(ConstrainedSampling::resolveJsonSchemaStrictSampling(new Tool('t', 'd', $schema, false), true));
        $this->assertTrue(ConstrainedSampling::resolveJsonSchemaStrictSampling(
            new Tool('t', 'd', $schema, ['type' => 'json_schema', 'strict' => 'prefer']),
            true,
        ));
        $this->assertSame($schema, ConstrainedSampling::getJsonSchemaToolParameters(new Tool('t', 'd', $schema), null));
    }

    // ---- the grammar half ----------------------------------------------------------------

    public function testAGrammarToolResolvesToItsLarkGrammarAndItsOneRequiredStringProperty(): void
    {
        $tool = new Tool('patch', 'd', ['type' => 'object', 'properties' => ['input' => ['type' => 'string']], 'required' => ['input']], [
            'type' => 'grammar',
            'variants' => ['openai_regex' => '.*', 'openai_lark' => 'start: "a"'],
        ]);

        // Lark wins when both are given; regex is the fallback.
        $this->assertSame(
            ['format' => 'lark', 'definition' => 'start: "a"', 'inputProperty' => 'input'],
            ConstrainedSampling::resolveGrammarConstrainedSampling($tool, true),
        );
        // An endpoint without grammar tools sends it as a function tool: no grammar, no error.
        $this->assertNull(ConstrainedSampling::resolveGrammarConstrainedSampling($tool, false));
        $this->assertSame(['patch' => 'input'], ConstrainedSampling::createGrammarToolInputProperties([$tool], true));
        $this->assertSame([], ConstrainedSampling::createGrammarToolInputProperties([$tool], false));
    }

    public function testAGrammarToolThatCannotBeSentIsAnErrorThatNamesTheTool(): void
    {
        // Upstream throws rather than sending something other than what the tool asked for.
        foreach ([
            [['type' => 'object', 'properties' => ['input' => ['type' => 'string']], 'required' => ['input']], ['openai_lark' => '  '], 'no supported grammar variant was provided.'],
            [['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a', 'b']], ['openai_regex' => 'x'], 'requires exactly one required string property.'],
            [['type' => 'object', 'properties' => ['n' => ['type' => 'number']], 'required' => ['n']], ['openai_regex' => 'x'], 'property n must have type string.'],
        ] as [$schema, $variants, $message]) {
            try {
                ConstrainedSampling::resolveGrammarConstrainedSampling(new Tool('patch', 'd', $schema, ['type' => 'grammar', 'variants' => $variants]), true);
                $this->fail('expected an error ending ' . $message);
            } catch (\Pig\Ai\ProviderError $error) {
                $this->assertStringStartsWith('Tool "patch" cannot use grammar constrained sampling: ', $error->getMessage());
                $this->assertStringEndsWith($message, $error->getMessage());
            }
        }
    }

    public function testRawInputIsReSaidAsTheGrowingJsonOfItsArgumentsObject(): void
    {
        // Upstream's `appendGrammarToolInputJsonDelta()`: the first delta opens the object, each
        // delta is the new text escaped as JSON string content, and closing adds `"}` — so the
        // deltas concatenate to the arguments' JSON.
        $buffer = ConstrainedSampling::newGrammarToolInputJsonBuffer();

        $this->assertSame('{"input":"a\\"', ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', 'a"', false));
        $this->assertNull(ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', 'a"', false), 'nothing new, nothing said');
        $this->assertSame('\\n/é"}', ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', "a\"\n/é", true));
        $this->assertNull(ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', "a\"\n/é", true), 'closing twice with the same input is fine');

        $this->expectExceptionMessage('grammar tool input for property "input" changed after it was closed');
        ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', 'other', true);
    }

    public function testInputThatDoesNotGrowFromWhatWasSaidIsAnError(): void
    {
        $buffer = ConstrainedSampling::newGrammarToolInputJsonBuffer();
        ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', 'abc', false);

        $this->expectExceptionMessage('grammar tool input for property "input" changed non-monotonically');
        ConstrainedSampling::appendGrammarToolInputJsonDelta($buffer, 'input', 'abX', false);
    }
}
