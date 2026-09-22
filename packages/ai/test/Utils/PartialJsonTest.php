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
}
