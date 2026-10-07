<?php

declare(strict_types=1);

/** php -l over every source and test file. */

$start = microtime(true);
$root = dirname(__DIR__);
$files = [];

// Extra directories may be named on the command line — `php test/lint.php ~/.pig/agent/extensions`
// sweeps the extensions a person wrote, which load into the same process as everything here and
// fail in exactly the same ways.
$dirs = ['packages', 'test', 'bin', 'examples', 'extensions'];
foreach (array_slice($argv, 1) as $extra) {
    $dirs[] = str_starts_with($extra, '/') ? $extra : "{$root}/{$extra}";
}

foreach ($dirs as $dir) {
    $dir = str_starts_with($dir, '/') ? $dir : "{$root}/{$dir}";
    if (!is_dir($dir)) {
        continue;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
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

// Batch syntax linting in chunks of 100 files instead of 637 individual exec() subprocesses.
foreach (array_chunk($files, 100) as $chunk) {
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . implode(' ', array_map(escapeshellarg(...), $chunk)) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        foreach ($chunk as $file) {
            $singleOutput = [];
            exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $singleOutput, $singleStatus);
            if ($singleStatus !== 0) {
                $failed++;
                echo "\033[31m✗\033[0m " . substr($file, strlen($root) + 1) . "\n";
                echo '  ' . implode("\n  ", $singleOutput) . "\n";
            }
        }
    }
}

foreach ($files as $file) {
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

    foreach (uncapturedClosureReads($source) as [$line, $name]) {
        $failed++;
        echo "\033[31m✗\033[0m " . (str_starts_with($file, $root) ? substr($file, strlen($root) + 1) : $file) . ":{$line}\n";
        echo "  \${$name} is read inside a closure that neither takes it as a parameter, captures it with use(), nor assigns it\n";
    }
}

/**
 * `$name` read inside a closure body where nothing could have given it a value.
 *
 * Every extension handler is `static function (...) use (...)`, and `$pi` exists only in the
 * file's outermost closure — so a `$pi->setSessionName()` inside a handler that did not
 * `use ($pi)` is `Undefined variable $pi` on the line that reaches it, which for
 * smart-session.php was the moment a title was generated, inside a `catch (Throwable)` that
 * swallowed it. `php -l` resolves no names; this reads them off the tokens.
 *
 * What counts as defined inside a closure: its parameters, its `use` list, `$this` unless the
 * closure is `static`, the superglobals, and any variable the body itself gives a value to —
 * `$x =` (not `==`/`===`/`=>`), `foreach (… as $x)` and `as $k => $x`, `catch (T $x)`, `static $x`,
 * `global $x`, and a variable whose **first** appearance is as a call argument, because
 * `preg_match($re, $s, $m)` and `exec($cmd, $out, $code)` assign through a reference and there is
 * no way to tell a by-ref parameter from the outside. That last rule is the one deliberate
 * blind spot: `foo($undefined)` as a plain read is not flagged. Arrow functions capture the
 * enclosing scope automatically and are skipped; nested closures are each checked in their own
 * scope, and a nested closure's `use` list counts as reads in the enclosing one.
 *
 * @return list<array{int, string}> line and variable name, in source order
 */
function uncapturedClosureReads(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $n = count($tokens);
    $found = [];

    $superglobals = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'argv', 'argc'];

    // Find the `{` that opens a block starting at or after $i (skipping a `use (...)` list and
    // a return type), and return [indexOfOpenBrace, indexOfCloseBrace].
    $block = static function (int $i) use ($tokens, $n): ?array {
        // $i is the last token of the signature already consumed (a `)`); scan from the next one.
        $depth = 0;
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j]->text;
            if ($t === '{' && $depth === 0) {
                $open = $j;
                $d = 0;
                for ($k = $j; $k < $n; $k++) {
                    $tt = $tokens[$k]->text;
                    if ($tt === '{' || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                        $d++;
                    } elseif ($tt === '}') {
                        $d--;
                        if ($d === 0) {
                            return [$open, $k];
                        }
                    }
                }
                return null;
            }
            if ($t === '(') {
                $depth++;
            } elseif ($t === ')') {
                $depth--;
            } elseif ($t === ';') {
                return null; // abstract / interface method, no body
            }
        }
        return null;
    };

    // Names of T_VARIABLE between two indices (exclusive), stripped of `$`.
    $varsBetween = static function (int $from, int $to) use ($tokens): array {
        $names = [];
        for ($j = $from + 1; $j < $to; $j++) {
            if ($tokens[$j]->is(T_VARIABLE)) {
                $names[] = substr($tokens[$j]->text, 1);
            }
        }
        return $names;
    };

    // Index of the `)` matching the `(` at $i.
    $closeParen = static function (int $i) use ($tokens, $n): int {
        $d = 0;
        for ($j = $i; $j < $n; $j++) {
            if ($tokens[$j]->text === '(') {
                $d++;
            } elseif ($tokens[$j]->text === ')') {
                if (--$d === 0) {
                    return $j;
                }
            }
        }
        return $n - 1;
    };

    for ($i = 0; $i < $n; $i++) {
        if (!$tokens[$i]->is(T_FUNCTION)) {
            continue;
        }
        $after = next_token($tokens, $i);
        if ($after === null || ($after->text !== '(' && $after->text !== '&')) {
            continue; // a named function or method: not a closure
        }

        $isStatic = false;
        for ($p = $i - 1; $p >= 0; $p--) {
            if ($tokens[$p]->isIgnorable()) {
                continue;
            }
            $isStatic = $tokens[$p]->is(T_STATIC);
            break;
        }

        // Parameters.
        $paramOpen = $i;
        while ($tokens[$paramOpen]->text !== '(') {
            $paramOpen++;
        }
        $paramClose = $closeParen($paramOpen);
        $defined = array_fill_keys($varsBetween($paramOpen, $paramClose), true);

        // `use (...)`.
        $useVars = [];
        $cursor = $paramClose;
        $maybeUse = next_token($tokens, $paramClose);
        if ($maybeUse !== null && $maybeUse->is(T_USE)) {
            $useOpen = $paramClose + 1;
            while ($tokens[$useOpen]->text !== '(') {
                $useOpen++;
            }
            $useClose = $closeParen($useOpen);
            $useVars = $varsBetween($useOpen, $useClose);
            foreach ($useVars as $v) {
                $defined[$v] = true;
            }
            // use (&$x) with no $x outside is legal (it becomes null); only a by-value
            // `use ($x)` is a read of the enclosing scope, and that scope is the outer loop's.
            $cursor = $useClose;
        }

        $body = $block($cursor);
        if ($body === null) {
            continue;
        }
        [$open, $close] = $body;

        // First pass over the body: what gets assigned, and which spans belong to nested
        // closures (their own scope — skipped here, visited by the outer loop in their turn).
        $nested = [];
        $assigned = [];
        $firstSeenAsRead = [];
        for ($j = $open + 1; $j < $close; $j++) {
            $tok = $tokens[$j];

            if ($tok->is(T_FUNCTION)) {
                $nx = next_token($tokens, $j);
                if ($nx !== null && ($nx->text === '(' || $nx->text === '&')) {
                    $po = $j;
                    while ($tokens[$po]->text !== '(') {
                        $po++;
                    }
                    $pc = $closeParen($po);
                    $cur = $pc;
                    $mu = next_token($tokens, $pc);
                    if ($mu !== null && $mu->is(T_USE)) {
                        $uo = $pc + 1;
                        while ($tokens[$uo]->text !== '(') {
                            $uo++;
                        }
                        $cur = $closeParen($uo);
                    }
                    $nb = $block($cur);
                    if ($nb !== null) {
                        // The nested closure's `use` list is reads in *this* scope; its params and body are not.
                        $nested[] = [$po, $nb[1], $pc, $cur];
                        $j = $nb[1];
                        continue;
                    }
                }
            }

            if ($tok->is(T_FN)) {
                // Arrow fn: its params are its own; its body reads the enclosing scope, which is us.
                $po = $j;
                while ($tokens[$po]->text !== '(') {
                    $po++;
                }
                $pc = $closeParen($po);
                foreach ($varsBetween($po, $pc) as $v) {
                    $assigned[$v] = true; // treat arrow params as defined for the enclosing read-check; cheap and safe
                }
                $j = $pc;
                continue;
            }

            if (!$tok->is(T_VARIABLE)) {
                continue;
            }
            $name = substr($tok->text, 1);
            $nx = next_token($tokens, $j);
            $pv = null;
            for ($p = $j - 1; $p > $open; $p--) {
                if (!$tokens[$p]->isIgnorable()) {
                    $pv = $tokens[$p];
                    break;
                }
            }

            $isAssign = $nx !== null && (
                $nx->text === '='
                || $nx->is([T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL])
                || $nx->is(T_INC) || $nx->is(T_DEC)
            );
            $isForeachTarget = $pv !== null && ($pv->is(T_AS) || $pv->is(T_DOUBLE_ARROW) || (($pv->text === '[' || $pv->text === ',') && isInsideForeachList($tokens, $j, $open)));
            $isCatchOrDecl = $pv !== null && ($pv->is(T_STATIC) || $pv->is(T_GLOBAL) || ($pv->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE]) && next_token($tokens, $j)?->text === ')' && isCatchVar($tokens, $j)));
            $isByRefArg = $pv !== null && $pv->text === '&';
            $isDestructure = $nx !== null && ($nx->text === ']' || $nx->text === ',') && isInsideListAssign($tokens, $j, $open);
            $isArgPosition = $pv !== null && ($pv->text === '(' || $pv->text === ',') && $nx !== null && ($nx->text === ',' || $nx->text === ')');

            if ($isAssign || $isForeachTarget || $isCatchOrDecl || $isByRefArg || $isDestructure) {
                $assigned[$name] = true;
            } elseif ($isArgPosition && !isset($assigned[$name]) && !isset($defined[$name]) && !isset($firstSeenAsRead[$name])) {
                // First sight is as a call argument: could be an out-parameter. Benefit of the doubt.
                $assigned[$name] = true;
            } else {
                $firstSeenAsRead[$name] = true;
            }
        }

        // Second pass: report reads that nothing defined. A nested closure's `use` list is
        // the one place inside a nested span that reads *our* variables.
        $reads = [];
        for ($j = $open + 1; $j < $close; $j++) {
            foreach ($nested as [$po, $nbClose, $pc, $cur]) {
                if ($j >= $po && $j <= $nbClose) {
                    if ($cur > $pc) {
                        // `use ($x)` reads our $x; `use (&$x)` creates it if we had none — PHP
                        // makes a by-reference capture spring into existence as null.
                        for ($k = $pc + 1; $k < $cur; $k++) {
                            if (!$tokens[$k]->is(T_VARIABLE)) {
                                continue;
                            }
                            $v = substr($tokens[$k]->text, 1);
                            $prev = $k - 1;
                            while ($prev > $pc && $tokens[$prev]->isIgnorable()) {
                                $prev--;
                            }
                            if ($tokens[$prev]->text === '&') {
                                $assigned[$v] = true;
                            } elseif ($v !== 'this') {
                                $reads[] = [$tokens[$k]->line, $v];
                            }
                        }
                    }
                    $j = $nbClose;
                    continue 2;
                }
            }
            if ($tokens[$j]->is(T_FN)) {
                $po = $j;
                while ($tokens[$po]->text !== '(') {
                    $po++;
                }
                $j = $closeParen($po);
                continue;
            }
            if ($tokens[$j]->is(T_VARIABLE)) {
                $reads[] = [$tokens[$j]->line, substr($tokens[$j]->text, 1)];
            }
        }

        $reported = [];
        foreach ($reads as [$line, $name]) {
            if ($name === 'this') {
                if ($isStatic && !isset($reported['this'])) {
                    $found[] = [$line, 'this'];
                    $reported['this'] = true;
                }
                continue;
            }
            if (in_array($name, $superglobals, true) || isset($defined[$name]) || isset($assigned[$name]) || isset($reported[$name])) {
                continue;
            }
            $found[] = [$line, $name];
            $reported[$name] = true;
        }
    }

    usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    return $found;
}

/** Is the variable at $i the `$e` of `catch (Type $e)`? */
function isCatchVar(array $tokens, int $i): bool
{
    for ($p = $i - 1; $p >= 0 && $i - $p < 12; $p--) {
        if ($tokens[$p]->is(T_CATCH)) {
            return true;
        }
        if ($tokens[$p]->text === ';' || $tokens[$p]->text === '{' || $tokens[$p]->text === '}') {
            return false;
        }
    }
    return false;
}

/** Is the variable at $i inside the `[$a, $b]` of `foreach (… as [$a, $b])` or `as $k => [$a, $b]`? */
function isInsideForeachList(array $tokens, int $i, int $floor): bool
{
    $d = 0;
    for ($p = $i - 1; $p > $floor; $p--) {
        $t = $tokens[$p]->text;
        if ($t === ']') {
            $d++;
        } elseif ($t === '[') {
            if ($d === 0) {
                for ($b = $p - 1; $b > $floor; $b--) {
                    if ($tokens[$b]->isIgnorable()) {
                        continue;
                    }
                    return $tokens[$b]->is(T_AS) || $tokens[$b]->is(T_DOUBLE_ARROW);
                }
                return false;
            }
            $d--;
        } elseif ($t === ';' || $t === '{' || $t === '}' || $t === '(') {
            return false;
        }
    }
    return false;
}

/** Is the variable at $i inside `[$a, $b] = …` or `list($a, $b) = …`? */
function isInsideListAssign(array $tokens, int $i, int $floor): bool
{
    $d = 0;
    for ($p = $i - 1; $p > $floor; $p--) {
        $t = $tokens[$p]->text;
        if ($t === ']' || $t === ')') {
            $d++;
        } elseif ($t === '[' || $t === '(') {
            if ($d === 0) {
                // Found the opener; is it followed (after its closer) by `=`?
                $n = count($tokens);
                $dd = 0;
                for ($q = $p; $q < $n; $q++) {
                    $tt = $tokens[$q]->text;
                    if ($tt === '[' || $tt === '(') {
                        $dd++;
                    } elseif ($tt === ']' || $tt === ')') {
                        if (--$dd === 0) {
                            $after = next_token($tokens, $q);
                            if ($after === null || $after->text !== '=') {
                                return false;
                            }
                            // `[` directly, or `list(` / `as [`
                            if ($t === '[') {
                                $before = null;
                                for ($b = $p - 1; $b > $floor; $b--) {
                                    if (!$tokens[$b]->isIgnorable()) {
                                        $before = $tokens[$b];
                                        break;
                                    }
                                }
                                // `$arr[...] = ` is an index write, not a destructure.
                                return $before === null || !$before->is(T_VARIABLE) && $before->text !== ']' && $before->text !== ')';
                            }
                            $before = null;
                            for ($b = $p - 1; $b > $floor; $b--) {
                                if (!$tokens[$b]->isIgnorable()) {
                                    $before = $tokens[$b];
                                    break;
                                }
                            }
                            return $before !== null && $before->is(T_LIST);
                        }
                    }
                }
                return false;
            }
            $d--;
        } elseif ($t === ';' || $t === '{' || $t === '}') {
            return false;
        }
    }
    return false;
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
    "%s%d files linted, %d with syntax errors\033[0m (took %.1fs)\n",
    $failed === 0 ? "\033[32m" : "\033[31m",
    count($files),
    $failed,
    microtime(true) - $start,
);

exit($failed === 0 ? 0 : 1);
