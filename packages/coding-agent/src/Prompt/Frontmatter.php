<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Utils\Yaml;
use Pig\CodingAgent\Utils\YamlError;

/**
 * The `---` block at the top of a skill or a prompt template — upstream's `utils/frontmatter.ts`.
 *
 * The block is the text between a first line starting `---` and the next line starting `---`,
 * read as YAML (`Utils\Yaml`, upstream's `yaml` package); the body is what follows, trimmed.
 * Without a block — no opening `---`, or no closing one — there are no fields and the body is the
 * whole file, untrimmed. A byte-order mark goes and line ends become `\n` either way, as upstream's.
 */
final class Frontmatter
{
    /**
     * The fields and the body. A block that is YAML but not a mapping — a list, a lone value —
     * has no fields, as upstream's `frontmatter.description` finds nothing on one.
     *
     * @return array{0: array<array-key, mixed>, 1: string}
     * @throws YamlError when the block is not YAML, upstream's `parse()` throwing
     */
    public static function parse(string $content): array
    {
        [$yaml, $body] = self::split($content);

        if ($yaml === null || $yaml === '') {
            return [[], $body];
        }

        // The block starts on the file's second line.
        $parsed = Yaml::parse($yaml, 2);

        return [is_array($parsed) && !array_is_list($parsed) ? $parsed : [], $body];
    }

    /** Upstream's `stripFrontmatter()`: the body alone, whether or not the block is valid YAML. */
    public static function body(string $content): string
    {
        return self::split($content)[1];
    }

    /** @return array{0: string|null, 1: string} the block's text (null when there is none), and the body */
    private static function split(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content);

        if (!str_starts_with($content, '---')) {
            return [null, $content];
        }

        $end = strpos($content, "\n---", 3);

        if ($end === false) {
            return [null, $content];
        }

        return [(string) substr($content, 4, max(0, $end - 4)), trim(substr($content, $end + 4))];
    }
}
