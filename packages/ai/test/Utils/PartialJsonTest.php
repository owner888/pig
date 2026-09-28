<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\PartialJson;

final class PartialJsonTest extends TestCase
{
    /** @param array<string, mixed> $expected */
    #[DataProvider('fragments')]
    public function testReadsWhateverHasArrivedSoFar(string $json, array $expected): void
    {
        $this->assertSame($expected, PartialJson::parse($json));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function fragments(): array
    {
        return [
            'complete object' => ['{"a":1}', ['a' => 1]],
            'complete nested object' => ['{"a":{"b":[1,2]}}', ['a' => ['b' => [1, 2]]]],
            'nothing yet' => ['{', []],
            'a key with no colon' => ['{"path"', []],
            'a colon with no value' => ['{"path":', []],
            'cut inside a string value' => ['{"path": "/tmp/fo', ['path' => '/tmp/fo']],
            'a finished value' => ['{"path": "/tmp/foo"', ['path' => '/tmp/foo']],
            'a trailing comma' => ['{"path": "/tmp/foo",', ['path' => '/tmp/foo']],
            'the next key started' => ['{"path": "/tmp/foo", "cont', ['path' => '/tmp/foo']],
            'a number still growing' => ['{"line": 12', ['line' => 12]],
            'a half-written literal is dropped' => ['{"ok": tru', []],
            'a finished literal is kept' => ['{"ok": true', ['ok' => true]],
            'an array mid-flight' => ['{"ids": [1, 2', ['ids' => [1, 2]]],
            'an array with a trailing comma' => ['{"ids": [1, 2,', ['ids' => [1, 2]]],
            'nested objects still open' => ['{"a": {"b": "c"', ['a' => ['b' => 'c']]],
            'escaped quotes inside the cut string' => [
                '{"text": "she said \"hi\" and',
                ['text' => 'she said "hi" and'],
            ],
            'a lone trailing backslash' => ['{"text": "back\\', ['text' => 'back']],
            'a truncated unicode escape falls back' => ['{"text": "snow \u26', []],
            'newlines inside a value' => ['{"content": "line one\nline', ['content' => "line one\nline"]],
            'empty object' => ['{}', []],
            'a bare scalar is not an arguments object' => ['5', []],
        ];
    }

    #[DataProvider('nothings')]
    public function testEmptyInputIsAnEmptyObject(?string $json): void
    {
        $this->assertSame([], PartialJson::parse($json));
    }

    /** @return array<string, array{0: ?string}> */
    public static function nothings(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => ["  \n "]];
    }

    public function testGrowsMonotonicallyAsATooLCallStreams(): void
    {
        // What the UI actually sees: one delta at a time, and the object filling in.
        $complete = '{"path":"/tmp/a.php","limit":100}';
        $seen = [];

        for ($length = 1; $length <= strlen($complete); $length++) {
            $seen[] = PartialJson::parse(substr($complete, 0, $length));
        }

        $this->assertSame(['path' => '/tmp/a.php', 'limit' => 100], end($seen));

        // Never throws, never returns a non-array, and the key count never goes backwards.
        $keys = 0;
        foreach ($seen as $step) {
            $this->assertGreaterThanOrEqual($keys, count($step));
            $keys = count($step);
        }
    }

    /**
     * Every prefix of a real call says part of what the whole call says, and nothing else.
     *
     * The corpus this file needs, and the reason it exists rather than another row in
     * `fragments()`: the docblock on `PartialJson` records a run against `partial-json@0.1.7`
     * over every prefix of a corpus, and **that run was never kept as a test** — so 32 of the
     * 97 mutations of a 257-line hand-written stand-in for a package survived the suite. The
     * test above could not see them either: it asserts the key *count* never goes backwards,
     * and a wrong safe-end still counts the same keys while holding the wrong values.
     *
     * So the assertion is on the values, as a property rather than a table. For a prefix p of
     * a valid document D, `parse(p)` may hold less than `parse(D)` and may hold a value still
     * being written — `"/tmp/a"` of `"/tmp/a.php"`, `12` of `125` — and may hold nothing at
     * all. What it may never do is invent a key or contradict one, which is what a mis-placed
     * safe end produces: a document closed at the wrong offset parses to the wrong shape.
     *
     * The documents are chosen for the one thing `fragments()` has no row for — a bracket that
     * *closes* mid-document, which is where `close()` and `open()` differ at all.
     *
     * @param string $complete a document that is valid JSON on its own
     */
    #[DataProvider('completeCalls')]
    public function testEveryPrefixSaysPartOfWhatTheWholeCallSaysAndNothingElse(string $complete): void
    {
        $whole = PartialJson::parse($complete);
        $this->assertNotSame([], $whole, 'the corpus document itself must parse');

        $keys = 0;

        for ($length = 1; $length <= strlen($complete); $length++) {
            $prefix = substr($complete, 0, $length);
            $parsed = PartialJson::parse($prefix);

            $this->assertTrue(
                self::isPartOf($parsed, $whole),
                sprintf("at %d bytes, %s is not part of the whole call:\n  %s", $length, json_encode($parsed), $prefix),
            );

            $this->assertGreaterThanOrEqual($keys, count($parsed), "the keys went backwards at {$length} bytes");
            $keys = count($parsed);
        }

        $this->assertSame($whole, PartialJson::parse($complete));
    }

    /** @return array<string, array{0: string}> */
    public static function completeCalls(): array
    {
        return [
            // An `edit` with a list of objects: brackets closing inside the document, twice.
            'a list of objects' => ['{"edits":[{"old":"a","new":"b"},{"old":"c","new":"d"}],"path":"/x"}'],
            // The empty container, which closes one byte after it opens.
            'empty containers first' => ['{"a":{},"b":[],"c":{"d":[1,2]},"e":"end"}'],
            'every scalar kind' => ['{"command":"ls -la","timeout":30,"ok":true,"off":false,"none":null}'],
            'escapes and newlines' => ['{"text":"she said \"hi\"\nand left","n":-12.5}'],
            'nesting three deep' => ['{"a":{"b":{"c":[[1],[2,3]]}},"z":1}'],
            'a single pair' => ['{"path":"/tmp/a.php"}'],
        ];
    }

    /**
     * Is $part what $whole looks like part-way through arriving?
     *
     * A string or a number may be cut short, a list may be short of its tail, an object may be
     * short of its later keys — but every key present has to be one $whole has, with a value
     * that is itself part of $whole's.
     */
    private static function isPartOf(mixed $part, mixed $whole): bool
    {
        if (is_array($part)) {
            if (!is_array($whole)) {
                return false;
            }

            foreach ($part as $key => $value) {
                if (!array_key_exists($key, $whole) || !self::isPartOf($value, $whole[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_string($part)) {
            return is_string($whole) && str_starts_with($whole, $part);
        }

        if (is_int($part) || is_float($part)) {
            // `12` of `125` and `-12` of `-12.5` are both a number half written.
            return (is_int($whole) || is_float($whole))
                && str_starts_with(self::digits($whole), self::digits($part));
        }

        return $part === $whole;
    }

    private static function digits(int|float $number): string
    {
        return is_float($number) ? rtrim(rtrim(sprintf('%.10F', $number), '0'), '.') : (string) $number;
    }
}
