<?php

declare(strict_types=1);

/** php -l over every source and test file. */

$root = dirname(__DIR__);
$files = [];

foreach (['packages', 'test', 'bin', 'examples'] as $dir) {
    if (!is_dir("{$root}/{$dir}")) {
        continue;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $file) {
        if (!$file->isFile() || str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }

        // An extension, or a `#!` line saying it is PHP anyway — `bin/pig` is the second
        // kind, and leaving an executable unlinted is leaving the entry point unlinted.
        if ($file->getExtension() === 'php' || str_starts_with((string) file_get_contents($file->getPathname(), false, null, 0, 20), '#!/usr/bin/env php')) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failed = 0;

/**
 * Functions newer than the declared floor in composer.json.
 *
 * php -l parses; it does not resolve function names, so a call to one of these sails
 * through the lint and fails at run time only on the line that happens to reach it.
 * Syntax above the floor is caught by linting with the floor's own php binary; this
 * covers the other half.
 */
$tooNew = [
    // All 8.4. Raise the floor and this list shrinks.
    'array_find', 'array_find_key', 'array_any', 'array_all',
    'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
    'fpow', 'request_parse_body', 'http_get_last_response_headers',
];

foreach ($files as $file) {
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo '  ' . implode("\n  ", $output) . "\n";
    }

    $output = [];

    $source = (string) file_get_contents($file);

    foreach ($tooNew as $function) {
        if (preg_match('/(?<![\w$>])' . $function . '\s*\(/', $source) === 1) {
            $failed++;
            echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
            echo "  {$function}() is newer than the floor in composer.json\n";
        }
    }

    // `@` hides every other thing that could have gone wrong in the same call, and the
    // one it was reached for is always the one you already knew about.
    if (preg_match('/(?<![\'"\w])@[a-z_]\w*\s*\(/i', (string) preg_replace('/(?s)\/\*.*?\*\/|\/\/[^\n]*|\'[^\']*\'|"[^"]*"/', '', $source), $match) === 1) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo "  error suppression: {$match[0]}\n";
    }

    foreach (undefinedPropertyReads($source) as $name) {
        $failed++;
        echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
        echo "  \$this->{$name} is read and no property called {$name} is declared\n";
    }
}

/**
 * `$this->name` reads in a class that declares no property `name`.
 *
 * `php -l` does not resolve properties either, and a misspelled one is a warning at run time on
 * the line that reaches it — `/bug` read `$this->terminalUi` where the field is `$ui`, and the
 * only command for reporting a fault failed with `Undefined property` the first time anybody
 * typed it. Only classes that extend nothing and have no `__get`, because an inherited or
 * magic property cannot be seen from one file. Read off PHP's own tokens rather than a regex,
 * because a `'` inside a docblock is where the first regex version lost the rest of the file.
 *
 * @return list<string>
 */
function undefinedPropertyReads(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);
    $classes = 0;
    $declared = [];
    $reads = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->is(T_CLASS)) {
            // `Foo::class` and anonymous classes are not declarations of this file's class.
            $previous = $i > 0 ? $tokens[$i - 1] : null;
            $next = next_token($tokens, $i);

            if ($previous?->is(T_DOUBLE_COLON) || $next?->is(T_NEW) || $i > 0 && $tokens[$i - 1]->is(T_NEW)) {
                continue;
            }

            if ($next?->is(T_STRING)) {
                $classes++;

                // Extends anything: inherited properties are out of reach from here.
                for ($j = $i; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                    if ($tokens[$j]->is(T_EXTENDS)) {
                        return [];
                    }
                }
            }

            continue;
        }

        if ($token->is(T_FUNCTION) && next_token($tokens, $i)?->text === '__get') {
            return [];
        }

        // A declaration: a visibility keyword followed (through modifiers, a type, and the
        // promoted-parameter `&`/`...`) by a variable.
        if ($token->is([T_PUBLIC, T_PRIVATE, T_PROTECTED])) {
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];

                if ($t->is(T_VARIABLE)) {
                    $declared[substr($t->text, 1)] = true;
                    break;
                }

                if ($t->is(T_FUNCTION) || $t->is(T_CONST) || $t->text === ';' || $t->text === '{') {
                    break;
                }
            }

            continue;
        }

        // A read: `$this->name` not followed by `(`.
        if ($token->is(T_VARIABLE) && $token->text === '$this') {
            $arrow = next_token($tokens, $i);
            $name = $arrow !== null && $arrow->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) ? next_token($tokens, $i, 2) : null;

            if ($name?->is(T_STRING) && next_token($tokens, $i, 3)?->text !== '(') {
                $reads[$name->text] = true;
            }
        }
    }

    if ($classes !== 1) {
        return [];
    }

    return array_values(array_filter(array_keys($reads), static fn (string $name): bool => !isset($declared[$name])));
}

/**
 * The $n-th token after $i that is not whitespace or a comment.
 *
 * @param list<PhpToken> $tokens
 */
function next_token(array $tokens, int $i, int $n = 1): ?PhpToken
{
    $count = count($tokens);

    for ($j = $i + 1; $j < $count; $j++) {
        if ($tokens[$j]->isIgnorable()) {
            continue;
        }

        if (--$n === 0) {
            return $tokens[$j];
        }
    }

    return null;
}

printf(
    "%s%d files linted, %d with syntax errors\033[0m\n",
    $failed === 0 ? "\033[32m" : "\033[31m",
    count($files),
    $failed,
);

exit($failed === 0 ? 0 : 1);
