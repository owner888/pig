<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\PartialJson;
use Pig\Ai\Utils\StreamingJson;

final class StreamingJsonTest extends TestCase
{
    /** @param array<array-key, mixed> $expected */
    #[DataProvider('fragments')]
    public function testReadsWhateverHasArrivedSoFar(string $json, array $expected): void
    {
        $this->assertSame($expected, self::read([$json]));
    }

    /** @return array<string, array{0: string, 1: array<array-key, mixed>}> */
    public static function fragments(): array
    {
        return [
            'nothing yet' => ['{', []],
            'a key with no colon' => ['{"path"', []],
            'a colon with no value' => ['{"path":', []],
            'cut inside a string value' => ['{"path": "/tmp/fo', ['path' => '/tmp/fo']],
            'the next key started' => ['{"path": "/tmp/foo", "cont', ['path' => '/tmp/foo']],
            'a number still growing' => ['{"line": 12', ['line' => 12]],
            'a number cut at its decimal point' => ['{"a":1,"temperature":0.', ['a' => 1, 'temperature' => 0]],
            'an exponent with no digits yet' => ['{"n": 1e', ['n' => 1]],
            'a half-written literal is dropped' => ['{"ok": tru', []],
            'a finished literal is kept' => ['{"ok": true', ['ok' => true]],
            'an array mid-flight' => ['{"ids": [1, 2', ['ids' => [1, 2]]],
            'nested objects still open' => ['{"a": {"b": "c"', ['a' => ['b' => 'c']]],
            'empty containers still open' => ['{"a": {"b": [', ['a' => ['b' => []]]],
            'a lone trailing backslash' => ['{"text": "back\\', ['text' => 'back']],
            // Where the old repair lost the whole string for these bytes, the escape is held back.
            'a truncated unicode escape is held back' => ['{"text": "snow \u26', ['text' => 'snow ']],
            'half a surrogate pair is held back' => ['{"text": "a\ud83d', ['text' => 'a']],
            'a character cut in half is held back' => ["{\"text\": \"a\xE4\xB8", ['text' => 'a']],
            'a bare scalar is not an arguments object' => ['5', []],
            'a string is not either' => ['"text"', []],
            'a list at the root' => ['[1, "two", [3', [1, 'two', [3]]],
        ];
    }

    #[DataProvider('lenient')]
    public function testReadsWhatIsNotQuiteJsonTheWayTheRepairDoes(string $json, mixed $expected): void
    {
        $this->assertSame($expected, self::read([$json]));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function lenient(): array
    {
        return [
            'a raw newline in a string' => ["{\"a\":\"x\ny\"}", ['a' => "x\ny"]],
            'an invalid escape stays text' => ['{"a":"C:\dir"}', ['a' => 'C:\dir']],
            'a \u without its digits stays text' => ['{"a":"\uZZ"}', ['a' => '\uZZ']],
            'a lone surrogate is a replacement character' => ['{"a":"\udc00x"}', ['a' => "\u{FFFD}x"]],
            'a missing comma' => ['{"a": 1 "b": 2}', ['a' => 1, 'b' => 2]],
            'a missing colon' => ['{"a" 1}', ['a' => 1]],
            'words that are no value are dropped' => ['{"a": NaN, "b": 1}', ['b' => 1]],
            'text after the root is ignored' => ['{"a": 1} {"b": 2}', ['a' => 1]],
            'bytes that are not UTF-8 are scrubbed' => ["{\"a\":\"x\xFFy\"}", ['a' => mb_scrub("x\xFFy", 'UTF-8')]],
        ];
    }

    /**
     * The point of the class: however the text is cut into pieces, the value is the one the whole
     * text gives — for every prefix of every document, through a hundred random cuttings.
     */
    #[DataProvider('documents')]
    public function testHowTheTextIsCutIntoPiecesChangesNothing(string $json): void
    {
        mt_srand(crc32($json));

        for ($length = 0; $length <= strlen($json); $length++) {
            $prefix = substr($json, 0, $length);
            $whole = self::read([$prefix]);
            $pieces = self::randomPieces($prefix);

            $this->assertSame($whole, self::read($pieces), 'cut as ' . json_encode($pieces, JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    /** A finished document reads exactly as `json_decode()` reads it. */
    #[DataProvider('documents')]
    public function testAFinishedDocumentIsWhatJsonDecodeSays(string $json): void
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(is_array($decoded) ? $decoded : [], self::read(str_split($json, 3)));
    }

    /**
     * What the old repair showed, prefix by prefix, except where this shows more: a prefix ending in
     * an escape or in half a character, which the repair could not decode and dropped the string for.
     */
    #[DataProvider('documents')]
    public function testEveryPrefixReadsAsThePartialJsonRepairReadIt(string $json): void
    {
        for ($length = 0; $length <= strlen($json); $length++) {
            $prefix = substr($json, 0, $length);

            if (preg_match('/\\\\(u[0-9a-fA-F]{0,3})?\z|\\\\u[dD][89abAB][0-9a-fA-F]{2}(\\\\(u[0-9a-fA-F]{0,3})?)?\z/', $prefix) === 1
                || !mb_check_encoding($prefix, 'UTF-8')) {
                continue;
            }

            $this->assertSame(PartialJson::parse($prefix), self::read([$prefix]), "at {$length}: " . substr($prefix, -40));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function documents(): array
    {
        $documents = [
            'a write' => ['path' => '/tmp/a.php', 'content' => "<?php\n\techo \"hi\\n\";\n// é 中 😀 \u{0001}\n"],
            'every scalar kind' => ['command' => 'ls -la', 'timeout' => 30, 'n' => -12.5e3, 'ok' => true, 'off' => false, 'none' => null, 'big' => 12345678901234567890],
            'edits' => ['edits' => [['old' => 'a"b', 'new' => 'c\d'], ['old' => '', 'new' => "x\ty"]], 'temperature' => 0.25],
            'nesting' => ['a' => ['b' => ['c' => [1, [2, [3, []]], new \stdClass()]]], 'empty' => [], 'obj' => new \stdClass(), '1' => 'numeric key', '' => 'empty key', 'z' => 1],
            'a list' => [1, 'two', [3.0, '4'], ['five' => 5]],
        ];
        $cases = [];

        foreach ($documents as $name => $document) {
            $cases["{$name}, escaped"] = [(string) json_encode($document)];
            $cases["{$name}, raw"] = [(string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $cases["{$name}, pretty"] = [(string) json_encode($document, JSON_PRETTY_PRINT)];
        }

        $cases['a duplicate key'] = ['{"a":1,"b":2,"a":3}'];
        $cases['a string at the root'] = ['"just text"'];
        $cases['a number at the root'] = ['-0.5e-3'];

        return $cases;
    }

    /**
     * A document that keeps a long array open copies it per snapshot — the cost the credit is there
     * to bound. The preview may lag while the array grows; it never lags once the array closes.
     */
    public function testAContainerKeptOpenLagsButCatchesUp(): void
    {
        $reader = new StreamingJson();
        $reader->append('{"ids":[');

        foreach (range(1, 5000) as $id) {
            $reader->append("{$id},");
            $value = $reader->value();
            $this->assertLessThanOrEqual($id, count($value['ids'] ?? []));
        }

        $reader->append('0]}');

        $this->assertSame([...range(1, 5000), 0], $reader->value()['ids']);
    }

    /**
     * The quadratic this replaced: four times the text should cost about four times as much, where
     * reparsing everything per delta cost sixteen. A ratio between two sizes, not milliseconds, so a
     * slow machine does not fail it — with room for noise, and none for the square.
     */
    public function testAStreamCostsTimeLinearInItsLength(): void
    {
        $small = self::writeArguments(32_000);
        $large = self::writeArguments(128_000);
        // Warm up, so the first measurement does not pay for autoloading.
        self::stream($small);

        $ratio = self::best(static fn () => self::stream($large)) / self::best(static fn () => self::stream($small));

        $this->assertLessThan(8.0, $ratio, 'streaming four times the text cost ' . round($ratio, 1) . ' times as much');
    }

    /** @param list<string> $pieces @return array<array-key, mixed> */
    private static function read(array $pieces): array
    {
        $reader = new StreamingJson();

        foreach ($pieces as $piece) {
            $reader->append($piece);
            $reader->value();
        }

        return $reader->value();
    }

    /** @return list<string> */
    private static function randomPieces(string $text): array
    {
        $pieces = [];

        for ($at = 0; $at < strlen($text);) {
            $size = mt_rand(1, 7);
            $pieces[] = substr($text, $at, $size);
            $at += $size;
        }

        return $pieces;
    }

    private static function writeArguments(int $bytes): string
    {
        $content = '';

        for ($line = 0; strlen($content) < $bytes; $line++) {
            $content .= "    \$x{$line} = \"value\\t é 中\";\n";
        }

        return (string) json_encode(['path' => '/tmp/a.php', 'content' => $content], JSON_UNESCAPED_UNICODE);
    }

    private static function stream(string $json): void
    {
        $reader = new StreamingJson();

        foreach (str_split($json, 20) as $piece) {
            $reader->append($piece);
            $reader->value();
        }
    }

    private static function best(\Closure $run): float
    {
        $best = INF;

        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $run();
            $best = min($best, hrtime(true) - $start);
        }

        return $best;
    }
}
