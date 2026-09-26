<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\JsonSchema;

/**
 * The JSON Schema subset, which stands in for upstream's AJV.
 *
 * What is worth testing here is not that a string is a string. It is the three places a
 * hand-written validator goes wrong: **an unknown keyword must not fail**, PHP cannot tell an
 * object from an array, and every message is read by a model that will act on it.
 */
final class JsonSchemaTest extends TestCase
{
    /** @param array<string, mixed> $schema */
    private function errors(array $schema, mixed $value): array
    {
        return JsonSchema::errors($schema, $value);
    }

    /** @param array<string, mixed> $schema */
    private function assertValid(array $schema, mixed $value, string $why = ''): void
    {
        $this->assertSame([], $this->errors($schema, $value), $why);
    }

    // ---- the thing a hand-written validator gets wrong first -------------------------------

    public function testAKeywordThisDoesNotImplementIsIgnoredAndNotAFailure(): void
    {
        // The most important line in the file. A schema using something unimplemented has to keep
        // working, or adding a keyword to a tool breaks the tool instead of tightening it.
        $this->assertValid(['type' => 'string', 'format' => 'email'], 'not-an-email');
        $this->assertValid(['type' => 'object', '$ref' => '#/definitions/Thing'], ['a' => 1]);
        $this->assertValid(['type' => 'string', 'contentEncoding' => 'base64'], 'x');
    }

    public function testAnEmptySchemaAcceptsAnything(): void
    {
        // Which is what `{}` means, and what a tool with no parameters declares.
        foreach ([null, 1, 'x', [], ['a' => 1], [1, 2]] as $value) {
            $this->assertValid([], $value);
        }
    }

    // ---- the PHP problem -------------------------------------------------------------------

    public function testAnEmptyArrayIsBothAnEmptyListAndAnEmptyObject(): void
    {
        // `json_decode(assoc: true)` gives an array for both, so `[]` is indistinguishable. There
        // is no third answer, and rejecting one of them would reject a valid document for being
        // ambiguous with another valid one.
        $this->assertValid(['type' => 'array'], []);
        $this->assertValid(['type' => 'object'], []);
    }

    public function testAListIsNotAnObjectAndAnObjectIsNotAList(): void
    {
        $this->assertSame(['root: must be object'], $this->errors(['type' => 'object'], [1, 2, 3]));
        $this->assertSame(['root: must be array'], $this->errors(['type' => 'array'], ['a' => 1]));
    }

    public function testAWholeFloatSatisfiesAnIntegerBecauseJsonHasOneNumberType(): void
    {
        // A provider that sends `2.0` for a count is sending 2, and AJV agrees.
        $this->assertValid(['type' => 'integer'], 2.0);
        $this->assertSame(['root: must be integer'], $this->errors(['type' => 'integer'], 2.5));
    }

    public function testABooleanIsNotANumber(): void
    {
        // PHP would say `is_numeric(true)` is false but arithmetic on it works, and a loose check
        // here would let `true` through as a count.
        $this->assertSame(['root: must be number'], $this->errors(['type' => 'number'], true));
        $this->assertSame(['root: must be integer'], $this->errors(['type' => 'integer'], true));
    }

    // ---- types -----------------------------------------------------------------------------

    /** @return list<array{0: string, 1: mixed, 2: bool}> */
    public static function types(): array
    {
        return [
            ['string', 'x', true],
            ['string', 1, false],
            ['number', 1.5, true],
            ['number', '1.5', false],
            ['boolean', false, true],
            ['boolean', 0, false],
            ['null', null, true],
            ['null', '', false],
            ['object', ['a' => 1], true],
            ['array', [1], true],
        ];
    }

    #[DataProvider('types')]
    public function testEachType(string $type, mixed $value, bool $valid): void
    {
        $this->assertSame($valid, $this->errors(['type' => $type], $value) === []);
    }

    public function testATypeCanBeSeveral(): void
    {
        // `["string", "null"]` is how an optional string is usually written.
        $schema = ['type' => ['string', 'null']];

        $this->assertValid($schema, 'x');
        $this->assertValid($schema, null);
        $this->assertSame(['root: must be string,null'], $this->errors($schema, 1));
    }

    // ---- objects ---------------------------------------------------------------------------

    public function testAMissingRequiredPropertyIsReportedAtItsOwnPath(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['edit' => ['type' => 'object', 'required' => ['path']]],
            'required' => ['edit'],
        ];

        // Upstream reports the bare property name and so cannot say which object it was missing
        // from. A nested one here reads `edit/path`.
        $this->assertSame(
            ["edit/path: must have required property 'path'"],
            $this->errors($schema, ['edit' => []]),
        );
    }

    public function testPropertiesAreCheckedRecursivelyAndPathsNest(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'edits' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'properties' => ['line' => ['type' => 'integer']]],
                ],
            ],
        ];

        $this->assertSame(
            ['edits/1/line: must be integer'],
            $this->errors($schema, ['edits' => [['line' => 1], ['line' => 'two']]]),
        );
    }

    public function testAdditionalPropertiesFalseRejectsWhatWasNotDeclared(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'additionalProperties' => false,
        ];

        $this->assertValid($schema, ['path' => 'a.php']);
        $this->assertSame(
            ['root: must NOT have additional properties'],
            $this->errors($schema, ['path' => 'a.php', 'mode' => 'w']),
        );
    }

    public function testAdditionalPropertiesCanBeASchemaOfItsOwn(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];

        $this->assertValid($schema, ['a' => 'x', 'b' => 'y']);
        $this->assertSame(['b: must be string'], $this->errors($schema, ['a' => 'x', 'b' => 2]));
    }

    public function testPropertyCounts(): void
    {
        $schema = ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 2];

        $this->assertValid($schema, ['a' => 1]);
        $this->assertSame(['root: must NOT have fewer than 1 properties'], $this->errors($schema, []));
        $this->assertSame(
            ['root: must NOT have more than 2 properties'],
            $this->errors($schema, ['a' => 1, 'b' => 2, 'c' => 3]),
        );
    }

    // ---- arrays ----------------------------------------------------------------------------

    public function testItemCountsAndUniqueness(): void
    {
        $schema = ['type' => 'array', 'minItems' => 2, 'maxItems' => 3, 'uniqueItems' => true];

        $this->assertValid($schema, [1, 2]);
        $this->assertSame(['root: must NOT have fewer than 2 items'], $this->errors($schema, [1]));
        $this->assertSame(['root: must NOT have more than 3 items'], $this->errors($schema, [1, 2, 3, 4]));
        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 0 and 1 are identical)'],
            $this->errors($schema, [1, 1]),
        );
    }

    public function testTheDuplicateIsNamedByPositionAsAjvNamesIt(): void
    {
        // The message is read by the model, which then has to fix the list. "one of these is a
        // duplicate" makes it read the whole list again; AJV says which two, and a model that has
        // seen AJV's wording everywhere else has seen the indices too.
        $schema = ['type' => 'array', 'uniqueItems' => true];

        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 1 and 3 are identical)'],
            $this->errors($schema, [1, 2, 3, 2]),
        );
    }

    public function testKeyOrderDoesNotCountAtAnyDepth(): void
    {
        // The sorting reached the top level only, so an object nested inside a list inside an
        // object was encoded with its keys in arrival order and the two read as different
        // documents. AJV's deep equality does not care about key order anywhere.
        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 0 and 1 are identical)'],
            $this->errors(
                ['type' => 'array', 'uniqueItems' => true],
                [['x' => [['b' => 1, 'a' => 2]]], ['x' => [['a' => 2, 'b' => 1]]]],
            ),
        );
    }

    public function testAWholeFloatIsTheSameItemAsItsInteger(): void
    {
        // `[1, 1.0]` is one number twice — JSON cannot say anything else, and the JavaScript AJV
        // compares with cannot even hold the difference. This works because `json_encode(1.0)` is
        // `1`; it is asserted rather than assumed, because the day it stops being true nothing
        // else would say so.
        $schema = ['type' => 'array', 'uniqueItems' => true];

        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 0 and 1 are identical)'],
            $this->errors($schema, [1, 1.0]),
        );
        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 0 and 1 are identical)'],
            $this->errors($schema, [['a' => 1], ['a' => 1.0]]),
        );

        // Different types are different items, which is AJV's answer too: `1` is not `"1"`, and
        // `0` is not `false`.
        $this->assertValid($schema, [1, '1']);
        $this->assertValid($schema, [0, false]);
    }

    public function testUniquenessDoesNotCareAboutKeyOrder(): void
    {
        // `{"a":1,"b":2}` and `{"b":2,"a":1}` are the same document, so these are duplicates.
        $this->assertSame(
            ['root: must NOT have duplicate items (items ## 0 and 1 are identical)'],
            $this->errors(
                ['type' => 'array', 'uniqueItems' => true],
                [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
            ),
        );
    }

    public function testItemsAsATupleIsCheckedByPosition(): void
    {
        $schema = ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]];

        $this->assertValid($schema, ['a', 1]);
        $this->assertSame(['1: must be integer'], $this->errors($schema, ['a', 'b']));
    }

    // ---- values ----------------------------------------------------------------------------

    public function testEnumAndConst(): void
    {
        $enum = ['type' => 'string', 'enum' => ['add', 'remove']];

        $this->assertValid($enum, 'add');
        $this->assertSame(['root: must be equal to one of the allowed values'], $this->errors($enum, 'multiply'));

        // `StringEnum` upstream is a TypeBox helper that produces exactly this shape, because
        // TypeBox's own `Type.Enum` emits `anyOf`/`const` and Google's API rejects that. In PHP a
        // schema is an array, so there is nothing to help with — you write the array.
        $this->assertValid(['const' => 5], 5);
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 5], 6));
    }

    public function testAWholeFloatEqualsTheIntegerItIsBecauseJsonHasOneNumberType(): void
    {
        // The same rule `isType()` already applies to `integer`, two methods away: a provider
        // that sends `5.0` for `const: 5` sent 5, and JSON has no way for it to have sent
        // anything else. AJV compares with JavaScript's one number type and agrees. Strict `!==`
        // refused it, and the model cannot correct a value that was already right.
        $this->assertValid(['const' => 5], 5.0);
        $this->assertValid(['const' => 5.0], 5);
        $this->assertValid(['enum' => [1, 2]], 2.0);
        $this->assertValid(['enum' => [1.5, 2]], 1.5);

        // Nested, because the same question is asked of every value inside.
        $this->assertValid(['enum' => [['a' => 1]]], ['a' => 1.0]);
        $this->assertValid(['const' => ['a' => [1, 2]]], ['a' => [1.0, 2.0]]);
    }

    public function testNumericEqualityDoesNotMakeEverythingEqualToEverything(): void
    {
        // PHP's `==` would say yes to all of these — `1 == true`, `0 == null`, `0 == false` —
        // which is why the comparison is numeric *only* when both sides are numbers.
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 5], '5'));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 1], true));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 0], null));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 0], false));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => true], 1));
        $this->assertSame(
            ['root: must be equal to one of the allowed values'],
            $this->errors(['enum' => [1, 2]], '2'),
        );

        // A whole float is the *same number*; a different number is still different.
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => 5], 5.5));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => ['a' => 1]], ['a' => 2]));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => ['a' => 1]], ['b' => 1]));
        $this->assertSame(['root: must be equal to constant'], $this->errors(['const' => [1, 2]], [1, 2, 3]));
    }

    public function testNumberBounds(): void
    {
        $this->assertSame(['root: must be >= 1'], $this->errors(['minimum' => 1], 0));
        $this->assertSame(['root: must be <= 10'], $this->errors(['maximum' => 10], 11));
        $this->assertSame(['root: must be > 0'], $this->errors(['exclusiveMinimum' => 0], 0));
        $this->assertSame(['root: must be < 10'], $this->errors(['exclusiveMaximum' => 10], 10));
        $this->assertValid(['minimum' => 1, 'maximum' => 10], 5);
    }

    public function testMultipleOfSurvivesBinaryFloatingPoint(): void
    {
        // Two traps in one line of arithmetic. `fmod(0.3, 0.1)` is `0.0999999999999999778` — close
        // to *the divisor*, not to zero — so a one-sided tolerance on the remainder calls 0.3 not a
        // multiple of 0.1, which is what the first version of this did. And AJV, which asks
        // `Number.isInteger(value / multipleOf)`, rejects it too; that is the standard's literal
        // reading and is deliberately not copied, because the reader is a model and
        // "0.3 must be multiple of 0.1" is a correction it cannot act on.
        $this->assertValid(['multipleOf' => 0.1], 0.3);
        $this->assertValid(['multipleOf' => 0.01], 1.21);
        $this->assertValid(['multipleOf' => 5], 20);
        $this->assertSame(['root: must be multiple of 5'], $this->errors(['multipleOf' => 5], 21));
        $this->assertSame(['root: must be multiple of 0.5'], $this->errors(['multipleOf' => 0.5], 1.2));
    }

    public function testStringLengthIsCountedInCharacters(): void
    {
        // Ten characters, not thirty bytes — `strlen` would make a Chinese string three times its
        // length and reject something the schema allows.
        $this->assertValid(['maxLength' => 4], '你好世界');
        $this->assertSame(['root: must NOT have more than 3 characters'], $this->errors(['maxLength' => 3], '你好世界'));
        $this->assertSame(['root: must NOT have fewer than 2 characters'], $this->errors(['minLength' => 2], 'x'));
    }

    public function testAPatternIsAnUndelimitedRegexAndPathsInItAreNotEscaped(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^src/.+\\.php$'];

        // The delimiter is `#`, so a pattern full of slashes needs no escaping — `/` as the
        // delimiter would have changed this pattern's meaning.
        $this->assertValid($schema, 'src/Thing.php');
        $this->assertSame(['root: must match pattern "^src/.+\\.php$"'], $this->errors($schema, 'tests/Thing.php'));
    }

    public function testAPatternThatIsNotAValidRegexIsTheSchemasProblemAndNotTheValues(): void
    {
        // Reporting it against the value would send the model looking in the wrong place for a
        // mistake it did not make.
        $this->assertValid(['type' => 'string', 'pattern' => '('], 'anything');
    }

    // ---- combinators -----------------------------------------------------------------------

    public function testAnyOfOneOfAllOfAndNot(): void
    {
        $anyOf = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];

        $this->assertValid($anyOf, 'x');
        $this->assertValid($anyOf, 1);
        // One sentence, not one per branch: a value that matched none of four alternatives
        // produces four sets of reasons and the useful fact is that it matched none.
        $this->assertSame(['root: must match a schema in anyOf'], $this->errors($anyOf, 1.5));

        $oneOf = ['oneOf' => [['type' => 'integer'], ['minimum' => 100]]];

        $this->assertValid($oneOf, 5, 'an integer under 100 matches exactly one');
        $this->assertSame(
            ['root: must match exactly one schema in oneOf'],
            $this->errors($oneOf, 200),
            'an integer over 100 matches both',
        );

        // `allOf` is a conjunction about the same value, so its complaints are reported at this
        // path rather than swallowed.
        $this->assertSame(
            ['root: must NOT have more than 2 characters'],
            $this->errors(['allOf' => [['type' => 'string'], ['maxLength' => 2]]], 'abc'),
        );

        $this->assertSame(['root: must NOT be valid'], $this->errors(['not' => ['type' => 'string']], 'x'));
        $this->assertValid(['not' => ['type' => 'string']], 1);
    }

    // ---- what pig's own tools declare -------------------------------------------------------

    public function testEveryBuiltInToolsSchemaAcceptsItsOwnDocumentedCall(): void
    {
        // The schemas in `ToolSet` use four keywords between them — type, properties, required,
        // description — so this is less about the validator than about there being no tool whose
        // own schema rejects the call the system prompt tells the model to make.
        $read = [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'offset' => ['type' => 'integer']],
            'required' => ['path'],
        ];

        $this->assertValid($read, ['path' => 'a.php']);
        $this->assertValid($read, ['path' => 'a.php', 'offset' => 10]);
    }
}
