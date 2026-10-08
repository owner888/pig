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
}
