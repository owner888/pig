<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;

/**
 * What a hook or extension file declares that PHP cannot take back — and what to do about it
 * on `/reload`.
 *
 * Node unloads a module by dropping it from `require.cache`; a PHP class or function, once
 * `require`d, is in the process for good, and a second `require` of the same file is a fatal
 * `Cannot redeclare`. pig used to answer that in two wrong ways at once: the loaders refused any
 * file with a named class in it, and every bundled extension therefore kept its classes in sibling
 * files behind a hand-written `if (!class_exists(...)) require` — which made `/reload` run new
 * closures against the old classes, in silence, and let two copies of one extension pick a winner
 * by load order. The developer's call was to say it instead: a named class is allowed, the loader
 * knows which files declare what, and the two things that go wrong are each said by name.
 *
 * - **A class already in memory from somewhere else** refuses the load, naming the file: two
 *   *different* copies of an extension cannot both be in one process. Two *identical* copies — the
 *   installed one and a checkout's, which is how this repository is run — are one extension, and
 *   the second is served by the first's factory without a word.
 * - **A reload of a file with classes** cannot `require` it again, so the factory from the first
 *   load is run again instead — the extension does not vanish on `/reload` — and when the files
 *   have changed since, the loader says the running version is the one loaded at startup and that
 *   a restart picks the change up. A file of nothing but closures is not here at all: it reloads
 *   whole, as it always has.
 *
 * A class the extension's own files declared is not a conflict with itself: a test that `require`s
 * them before loading the entry, or an entry that `require_once`s them inside its factory, is the
 * same code at the same path.
 *
 * Scanned, not executed: every `.php` under a folder extension (its `index.php`'s directory,
 * `vendor/`, `node_modules/` and tests left out), or the one file, tokenized for named classes,
 * interfaces, traits, enums and functions, namespace included.
 */
final class DeclaredSymbols
{
    /** Directories under an extension that are somebody else's code or nobody's at runtime. */
    private const array SKIPPED = ['vendor', 'node_modules', 'test', 'tests'];

    /** @var array<string, array{factory: Closure, hash: string}> what each file's first load gave, by resolved path */
    private static array $loaded = [];

    /**
     * The named classes, interfaces, traits, enums and functions this file and its siblings declare,
     * fully qualified. Empty for a file of closures and anonymous classes — one `/reload` can take.
     *
     * @return list<string>
     */
    public static function in(string $resolved): array
    {
        $names = [];

        foreach (self::files($resolved) as $file) {
            $code = file_get_contents($file);

            if ($code === false) {
                continue;
            }

            foreach (self::declaredIn($code) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The first of $declared that something *outside* this file's own set already declared, as
     * `name from /path/to/file`, or null when the load is free to go ahead.
     *
     * @param list<string> $declared
     */
    public static function conflict(string $resolved, array $declared): ?string
    {
        $own = array_flip(self::files($resolved));

        foreach ($declared as $name) {
            $by = self::declaredBy($name);

            if ($by !== null && !isset($own[$by])) {
                return "{$name} from {$by}";
            }
        }

        return null;
    }

    /** The file that already declared $name in this process, or null when nothing has. */
    public static function declaredBy(string $name): ?string
    {
        if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false) || enum_exists($name, false)) {
            $file = (new ReflectionClass($name))->getFileName();

            return $file === false ? '(built in)' : $file;
        }

        if (function_exists($name)) {
            $file = (new ReflectionFunction($name))->getFileName();

            return $file === false ? '(built in)' : $file;
        }

        return null;
    }

    /**
     * A fingerprint of the file and its siblings, so a reload can tell whether anything changed —
     * and so two copies at two paths can be told to be one: the paths go in relative to the
     * entry, never absolute.
     */
    public static function hashOf(string $resolved): string
    {
        $hash = hash_init('sha256');
        $root = dirname($resolved);

        foreach (self::files($resolved) as $file) {
            // A single file has no layout to fingerprint, and its own name is not its content.
            $name = $file === $resolved ? '' : substr($file, strlen($root));
            hash_update($hash, $name . "\0" . (string) file_get_contents($file) . "\0");
        }

        return hash_final($hash);
    }

    /** Keep what a first load of a file with declarations gave, for the loads that cannot `require` it. */
    public static function remember(string $resolved, Closure $factory, string $hash): void
    {
        self::$loaded[$resolved] = ['factory' => $factory, 'hash' => $hash];
    }

    /**
     * The factory to run instead of a `require`: this path's own from its first load (`changed`
     * says whether the files have moved on since), or the factory of an identical copy loaded
     * from another path. Null for a path and content this process has not loaded.
     *
     * @return array{factory: Closure, changed: bool}|null
     */
    public static function recall(string $resolved, string $hash): ?array
    {
        if (isset(self::$loaded[$resolved])) {
            return ['factory' => self::$loaded[$resolved]['factory'], 'changed' => self::$loaded[$resolved]['hash'] !== $hash];
        }

        foreach (self::$loaded as $kept) {
            if ($kept['hash'] === $hash) {
                return ['factory' => $kept['factory'], 'changed' => false];
            }
        }

        return null;
    }

    /** For tests, which load the same path under different contents. */
    public static function forget(): void
    {
        self::$loaded = [];
    }

    /**
     * The files a load of $resolved brings in: the file, or everything under a folder extension.
     *
     * @return list<string>
     */
    private static function files(string $resolved): array
    {
        if (basename($resolved) !== 'index.php') {
            return [$resolved];
        }

        $files = [];
        self::walk(dirname($resolved), $files);
        sort($files);

        return $files;
    }

    /** @param list<string> $files */
    private static function walk(string $directory, array &$files): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                if (!in_array($entry, self::SKIPPED, true)) {
                    self::walk($path, $files);
                }
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    }

    /**
     * The declarations in one file's source, fully qualified.
     *
     * A `T_CLASS` is a declaration unless it is `new class` or `Foo::class`; a `T_FUNCTION` is one
     * unless it is a method (inside a class body, anonymous ones included), a closure (no name), or
     * a `use function` import. A declaration inside an `if` still counts: it happens when the file
     * runs, and the file is what a reload would run again.
     *
     * @return list<string>
     */
    private static function declaredIn(string $code): array
    {
        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);
        $names = [];
        $namespace = '';
        $depth = 0;
        /** @var list<int> the brace depth each open class body started at */
        $classDepth = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $next = self::nextMeaningful($tokens, $i);
                $namespace = $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED])
                    ? $next->text . '\\'
                    : '';

                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $before = self::previousMeaningful($tokens, $i);

                // `Foo::class` is a name, not a body: nothing opens.
                if ($before !== null && $before->is(T_DOUBLE_COLON)) {
                    continue;
                }

                $classDepth[] = $depth;

                if (($before === null || !$before->is(T_NEW)) && count($classDepth) === 1) {
                    $next = self::nextMeaningful($tokens, $i);

                    if ($next !== null && $next->is(T_STRING)) {
                        $names[] = $namespace . $next->text;
                    }
                }

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;

                if ($classDepth !== [] && end($classDepth) === $depth) {
                    array_pop($classDepth);
                }
            } elseif ($token->is(T_FUNCTION) && $classDepth === []) {
                $before = self::previousMeaningful($tokens, $i);

                if ($before !== null && $before->is(T_USE)) {
                    continue;
                }

                $at = self::indexAfter($tokens, $i);

                // `function &byReference()` — the name is one token further on.
                if ($at !== null && $tokens[$at]->text === '&') {
                    $at = self::indexAfter($tokens, $at);
                }

                if ($at !== null && $tokens[$at]->is(T_STRING)) {
                    $names[] = $namespace . $tokens[$at]->text;
                }
            }
        }

        return $names;
    }

    /** @param list<PhpToken> $tokens */
    private static function nextMeaningful(array $tokens, int $from): ?PhpToken
    {
        $at = self::indexAfter($tokens, $from);

        return $at === null ? null : $tokens[$at];
    }

    /** @param list<PhpToken> $tokens */
    private static function indexAfter(array $tokens, int $from): ?int
    {
        for ($j = $from + 1, $n = count($tokens); $j < $n; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private static function previousMeaningful(array $tokens, int $from): ?PhpToken
    {
        for ($j = $from - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
