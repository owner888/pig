<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * Codemode source format — upstream's `source.ts`: a script, optionally preceded by one options
 * line.
 *
 * ```php
 * // @options: {"max_output_tokens": 2000, "timeout_ms": 30000}
 * $text = $tools->read(['path' => 'composer.json']);
 * text(json_decode($text, true)['name']);
 * ```
 *
 * The language is PHP here and JavaScript upstream; the options line is the same.
 */
final class Source
{
    public const string OPTIONS_PREFIX = '// @options:';

    private const array SUPPORTED_FIELDS = ['max_output_tokens', 'timeout_ms'];

    private const string SUPPORTED_FIELDS_TEXT = '`max_output_tokens` and `timeout_ms`';

    /** Upstream's bound, `setTimeout`'s largest delay; kept so the two agree about what is refused. */
    private const int MAX_TIMEOUT_MS = 2_147_483_647;

    /**
     * @param array{maxOutputTokens?: int, timeoutMs?: int} $options
     */
    private function __construct(
        public readonly string $code,
        public readonly array $options,
    ) {
    }

    /**
     * Split an optional first-line `// @options: {...}` from the script. Throws
     * `SourceError` for empty input and invalid options.
     */
    public static function parse(string $input): self
    {
        if (trim($input) === '') {
            throw new SourceError('Expected PHP source text (non-empty). Provide PHP only, optionally with a first line `// @options: {"max_output_tokens": 1000}`.');
        }

        $newline = strpos($input, "\n");
        $firstLine = rtrim($newline === false ? $input : substr($input, 0, $newline), "\r");
        $trimmed = ltrim($firstLine);

        if (!str_starts_with($trimmed, self::OPTIONS_PREFIX)) {
            return new self($input, []);
        }

        $code = $newline === false ? '' : substr($input, $newline);

        if (trim($code) === '') {
            throw new SourceError('The @options line must be followed by PHP source on subsequent lines');
        }

        return new self($code, self::parseOptions(trim(substr($trimmed, strlen(self::OPTIONS_PREFIX)))));
    }

    /** @return array{maxOutputTokens?: int, timeoutMs?: int} */
    private static function parseOptions(string $directive): array
    {
        if ($directive === '') {
            throw new SourceError('@options must be a JSON object with supported fields ' . self::SUPPORTED_FIELDS_TEXT);
        }

        try {
            $value = json_decode($directive, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SourceError('@options must be valid JSON with supported fields ' . self::SUPPORTED_FIELDS_TEXT . ": {$error->getMessage()}");
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new SourceError('@options must be a JSON object with supported fields ' . self::SUPPORTED_FIELDS_TEXT);
        }

        foreach (array_keys($value) as $key) {
            if (!in_array($key, self::SUPPORTED_FIELDS, true)) {
                throw new SourceError('@options only supports ' . self::SUPPORTED_FIELDS_TEXT . "; got `{$key}`");
            }
        }

        $options = [];

        if (array_key_exists('max_output_tokens', $value)) {
            $tokens = $value['max_output_tokens'];

            if (!is_int($tokens) || $tokens < 0) {
                throw new SourceError('@options field `max_output_tokens` must be a non-negative safe integer');
            }

            $options['maxOutputTokens'] = $tokens;
        }

        if (array_key_exists('timeout_ms', $value)) {
            $timeout = $value['timeout_ms'];

            if (!is_int($timeout) || $timeout <= 0 || $timeout > self::MAX_TIMEOUT_MS) {
                throw new SourceError('@options field `timeout_ms` must be a positive integer up to ' . self::MAX_TIMEOUT_MS);
            }

            $options['timeoutMs'] = $timeout;
        }

        return $options;
    }
}
