<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Utils\Yaml;
use Pig\CodingAgent\Utils\YamlError;

/**
 * Frontmatter YAML, against upstream's parser: `test/fixtures/yaml-frontmatter.json` holds documents
 * and what `yaml` 2.9.0 — the version upstream pins — made of each, a value or a refusal. The
 * documents are what a `SKILL.md` or a prompt template is written with, and the ways they go wrong:
 * folded and literal descriptions, lists both ways, quoting, comments, the core schema's scalars,
 * and the refusals — a `: ` in a plain value, duplicate keys, unterminated quotes, reserved
 * characters, bad indentation.
 *
 * A refusal is matched as a refusal, not by its words: `Yaml`'s sentences are `yaml`'s where it has
 * one, and the line rather than the line and column.
 */
final class YamlTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function upstream(): iterable
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/yaml-frontmatter.json'), true);

        foreach ($fixture['cases'] as $i => $case) {
            yield "#{$i} " . substr(str_replace("\n", '⏎', $case['yaml']), 0, 50) => [$case['yaml'], $case];
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('upstream')]
    public function testWhatUpstreamsParserMakesOfIt(string $yaml, array $expected): void
    {
        try {
            $value = Yaml::parse($yaml);
        } catch (YamlError $error) {
            $this->assertArrayHasKey('error', $expected, "refused, where yaml read it: {$error->getMessage()}");

            return;
        }

        $this->assertArrayNotHasKey('error', $expected, 'read, where yaml refused it: ' . ($expected['error'] ?? ''));
        $this->assertSame(json_encode(self::comparable($expected['value'])), json_encode(self::comparable($value)));
    }

    public function testARefusalNamesTheLineInTheFile(): void
    {
        try {
            Yaml::parse("name: x\ndescription: Use when: asked", 2);
            $this->fail('read');
        } catch (YamlError $error) {
            $this->assertSame(3, $error->lineNumber);
            $this->assertSame('Nested mappings are not allowed in compact mappings at line 3', $error->getMessage());
        }
    }

    /** JSON's view of a value: what the fixture could hold, a whole float as an integer, the infinities as YAML writes them. */
    private static function comparable(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::comparable(...), $value),
            is_float($value) && is_nan($value) => '.nan',
            is_float($value) && is_infinite($value) => $value > 0 ? '.inf' : '-.inf',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (int) $value,
            default => $value,
        };
    }
}
