<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

/**
 * As much of a language as colouring it needs.
 *
 * Not a parser and not trying to be: what separates a useful highlighter from a
 * misleading one is knowing where comments and strings start and end, so that a
 * keyword inside a string stays the colour of the string. Everything past that —
 * which words are keywords, what a number looks like — is a list.
 *
 * Upstream gets this from `cli-highlight`, which wraps highlight.js and its ~200
 * grammars. That is a dependency this project does not take (see CLAUDE.md), and the
 * developer asked for a small one written here instead. The trade is honest: fewer
 * languages, and within a language a handful of things coloured slightly wrong that
 * highlight.js would get right. Colour is cosmetic — a mistake costs nothing but looks.
 */
final readonly class Grammar
{
    /**
     * The name each language is known by, and what it answers to.
     *
     * @var array<string, list<string>>
     */
    private const array ALIASES = [
        'php' => ['php'],
        'javascript' => ['javascript', 'js', 'jsx', 'mjs', 'cjs', 'typescript', 'ts', 'tsx'],
        'python' => ['python', 'py'],
        'go' => ['go', 'golang'],
        'rust' => ['rust', 'rs'],
        'java' => ['java', 'kotlin', 'kt'],
        'c' => ['c', 'h', 'cpp', 'c++', 'cc', 'hpp', 'cxx', 'objc', 'csharp', 'cs'],
        'ruby' => ['ruby', 'rb'],
        'shell' => ['shell', 'sh', 'bash', 'zsh', 'fish', 'console'],
        'json' => ['json', 'jsonc', 'json5'],
        'sql' => ['sql', 'mysql', 'postgres', 'postgresql', 'sqlite'],
        'css' => ['css', 'scss', 'sass', 'less'],
        'yaml' => ['yaml', 'yml', 'toml', 'ini'],
    ];

    /** The file extensions that mean each of them, for a path rather than a fence. */
    private const array EXTENSIONS = [
        'php' => 'php',
        'js' => 'javascript', 'jsx' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
        'ts' => 'javascript', 'tsx' => 'javascript',
        'py' => 'python', 'pyi' => 'python',
        'go' => 'go',
        'rs' => 'rust',
        'java' => 'java', 'kt' => 'java', 'kts' => 'java',
        'c' => 'c', 'h' => 'c', 'cpp' => 'c', 'cc' => 'c', 'cxx' => 'c', 'hpp' => 'c',
        'm' => 'c', 'mm' => 'c', 'cs' => 'c',
        'rb' => 'ruby',
        'sh' => 'shell', 'bash' => 'shell', 'zsh' => 'shell', 'fish' => 'shell',
        'json' => 'json',
        'sql' => 'sql',
        'css' => 'css', 'scss' => 'css', 'sass' => 'css', 'less' => 'css',
        'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'yaml', 'ini' => 'yaml',
    ];

    /**
     * @param list<string>                   $lineComment  runs to the end of the line
     * @param array{0: string, 1: string}|null $blockComment open and close
     * @param list<array{0: string, 1: bool}> $strings      delimiter, and whether \ escapes
     *        inside it. Longest delimiter first, so `"""` is tried before `"`.
     * @param list<string>                    $keywords
     * @param list<string>                    $types        spelled out where a language has
     *        a closed set of them; elsewhere Highlight's Capitalised-word guess is used
     * @param bool $hashStartsAttributes `#[` opens an attribute rather than a comment
     * @param bool $caseInsensitiveKeywords the lists below are lower case and matched
     *        that way — for SQL, where `SELECT` and `select` are the same word
     */
    private function __construct(
        public array $lineComment,
        public ?array $blockComment,
        public array $strings,
        public array $keywords,
        public array $types = [],
        public bool $capitalisedIsAType = true,
        public bool $hashStartsAttributes = false,
        public bool $caseInsensitiveKeywords = false,
    ) {
    }

    /** @return self|null null for a language with no grammar here, which draws plain */
    public static function for(string $language): ?self
    {
        $name = self::canonical($language);

        return $name === null ? null : self::make($name);
    }

    /** The language a file is in, going by its extension. */
    /**
     * Which grammar a file name asks for, or null for one nothing here can colour.
     *
     * The extension, where upstream takes the last dot-separated piece of the whole name.
     * The two agree on everything with a dot in it and differ on the names that have none:
     * `Makefile` and `Dockerfile` come back as themselves there, and upstream's table has a
     * row for each. Here they are extensionless and answer null — which is the same drawing
     * either way, because neither is among the thirteen grammars. **The day one of them is,
     * this is the line that has to learn about whole file names**, and it will look like the
     * grammar simply not working.
     */
    public static function fromPath(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? null;
    }

    private static function canonical(string $language): ?string
    {
        $wanted = strtolower(trim($language));

        foreach (self::ALIASES as $name => $aliases) {
            if (in_array($wanted, $aliases, true)) {
                return $name;
            }
        }

        return null;
    }

    private static function make(string $name): self
    {
        $cLike = ['//'];
        $block = ['/*', '*/'];
        $quotes = [['"', true], ["'", true]];

        return match ($name) {
            'php' => new self(['//', '#'], $block, [...$quotes], self::PHP, self::PHP_TYPES, true, true),
            'javascript' => new self($cLike, $block, [['`', true], ...$quotes], self::JAVASCRIPT, self::JS_TYPES),
            // Triple quotes first: a docstring opening with """ must not be read as an
            // empty "" followed by a stray ".
            'python' => new self(['#'], null, [['"""', true], ["'''", true], ...$quotes], self::PYTHON, self::PYTHON_TYPES),
            'go' => new self($cLike, $block, [['`', false], ...$quotes], self::GO, self::GO_TYPES),
            'rust' => new self($cLike, $block, [...$quotes], self::RUST, self::RUST_TYPES),
            'java' => new self($cLike, $block, [...$quotes], self::JAVA, self::JAVA_TYPES),
            'c' => new self($cLike, $block, [...$quotes], self::C, self::C_TYPES),
            'ruby' => new self(['#'], null, [...$quotes], self::RUBY, self::RUBY_TYPES),
            // Single quotes take no escapes in a shell, so `'a\'` is a string holding a
            // backslash, not an escaped quote. No block comment either: `<<'EOF'`
            // heredocs are their own world and guessing at them mis-colours whole scripts.
            'shell' => new self(['#'], null, [['"', true], ["'", false]], self::SHELL, [], false),
            'json' => new self([], null, [['"', true]], ['true', 'false', 'null'], [], false),
            'sql' => new self(['--', '#'], $block, [...$quotes], self::SQL, self::SQL_TYPES, false, false, true),
            'css' => new self($cLike, $block, [...$quotes], self::CSS, [], false),
            'yaml' => new self(['#'], null, [...$quotes], ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], [], false),
            default => new self([], null, [], []),
        };
    }

    // ---- the lists ----------------------------------------------------------------

    private const array PHP = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'extends',
        'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match',
        'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require',
        'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var',
        // No 'this': it is only ever written `$this`, and `$` is part of the word, so a
        // keyword spelled without it would never match anything.
        'while', 'xor', 'yield', 'true', 'false', 'null', 'parent', 'self',
    ];

    private const array PHP_TYPES = ['bool', 'int', 'float', 'string', 'object', 'mixed', 'void', 'never', 'iterable'];

    private const array JAVASCRIPT = [
        'as', 'async', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger',
        'declare', 'default', 'delete', 'do', 'else', 'enum', 'export', 'extends', 'finally', 'for',
        'from', 'function', 'get', 'if', 'implements', 'import', 'in', 'instanceof', 'interface',
        'let', 'namespace', 'new', 'of', 'private', 'protected', 'public', 'readonly', 'return',
        'satisfies', 'set', 'static', 'super', 'switch', 'this', 'throw', 'try', 'type', 'typeof',
        'var', 'void', 'while', 'with', 'yield', 'true', 'false', 'null', 'undefined',
    ];

    private const array JS_TYPES = ['any', 'bigint', 'boolean', 'never', 'number', 'object', 'string', 'symbol', 'unknown'];

    private const array PYTHON = [
        'and', 'as', 'assert', 'async', 'await', 'break', 'class', 'continue', 'def', 'del', 'elif',
        'else', 'except', 'finally', 'for', 'from', 'global', 'if', 'import', 'in', 'is', 'lambda',
        'match', 'nonlocal', 'not', 'or', 'pass', 'raise', 'return', 'try', 'while', 'with', 'yield',
        'True', 'False', 'None', 'self', 'cls',
    ];

    private const array PYTHON_TYPES = ['bool', 'bytes', 'dict', 'float', 'int', 'list', 'set', 'str', 'tuple'];

    private const array GO = [
        'break', 'case', 'chan', 'const', 'continue', 'default', 'defer', 'else', 'fallthrough',
        'for', 'func', 'go', 'goto', 'if', 'import', 'interface', 'map', 'package', 'range',
        'return', 'select', 'struct', 'switch', 'type', 'var', 'true', 'false', 'nil', 'iota',
    ];

    private const array GO_TYPES = [
        'bool', 'byte', 'complex64', 'complex128', 'error', 'float32', 'float64', 'int', 'int8',
        'int16', 'int32', 'int64', 'rune', 'string', 'uint', 'uint8', 'uint16', 'uint32', 'uint64', 'uintptr', 'any',
    ];

    private const array RUST = [
        'as', 'async', 'await', 'break', 'const', 'continue', 'crate', 'dyn', 'else', 'enum',
        'extern', 'fn', 'for', 'if', 'impl', 'in', 'let', 'loop', 'match', 'mod', 'move', 'mut',
        'pub', 'ref', 'return', 'self', 'Self', 'static', 'struct', 'super', 'trait', 'type',
        'unsafe', 'use', 'where', 'while', 'true', 'false',
    ];

    private const array RUST_TYPES = [
        'bool', 'char', 'f32', 'f64', 'i8', 'i16', 'i32', 'i64', 'i128', 'isize', 'str',
        'u8', 'u16', 'u32', 'u64', 'u128', 'usize',
    ];

    private const array JAVA = [
        'abstract', 'assert', 'break', 'case', 'catch', 'class', 'const', 'continue', 'data',
        'default', 'do', 'else', 'enum', 'extends', 'final', 'finally', 'for', 'fun', 'goto', 'if',
        'implements', 'import', 'instanceof', 'interface', 'internal', 'is', 'native', 'new',
        'object', 'open', 'override', 'package', 'private', 'protected', 'public', 'record',
        'return', 'sealed', 'static', 'strictfp', 'super', 'suspend', 'switch', 'synchronized',
        'this', 'throw', 'throws', 'transient', 'try', 'val', 'var', 'volatile', 'when', 'while',
        'yield', 'true', 'false', 'null',
    ];

    private const array JAVA_TYPES = ['boolean', 'byte', 'char', 'double', 'float', 'int', 'long', 'short', 'void'];

    private const array C = [
        'alignas', 'alignof', 'auto', 'break', 'case', 'catch', 'class', 'const', 'constexpr',
        'continue', 'default', 'delete', 'do', 'else', 'enum', 'explicit', 'extern', 'for',
        'friend', 'goto', 'if', 'inline', 'namespace', 'new', 'noexcept', 'nullptr', 'operator',
        'override', 'private', 'protected', 'public', 'register', 'return', 'sizeof', 'static',
        'struct', 'switch', 'template', 'this', 'throw', 'try', 'typedef', 'typename', 'union',
        'using', 'virtual', 'volatile', 'while', 'true', 'false', 'NULL',
    ];

    private const array C_TYPES = [
        'bool', 'char', 'double', 'float', 'int', 'long', 'short', 'signed', 'size_t',
        'unsigned', 'void', 'wchar_t', 'uint8_t', 'uint16_t', 'uint32_t', 'uint64_t',
        'int8_t', 'int16_t', 'int32_t', 'int64_t',
    ];

    private const array RUBY = [
        'alias', 'and', 'begin', 'break', 'case', 'class', 'def', 'defined?', 'do', 'else',
        'elsif', 'end', 'ensure', 'for', 'if', 'in', 'module', 'next', 'not', 'or', 'redo',
        'rescue', 'retry', 'return', 'self', 'super', 'then', 'undef', 'unless', 'until', 'when',
        'while', 'yield', 'true', 'false', 'nil', 'require', 'require_relative', 'attr_accessor',
    ];

    private const array RUBY_TYPES = ['Array', 'Hash', 'Integer', 'Float', 'String', 'Symbol'];

    private const array SHELL = [
        'case', 'do', 'done', 'elif', 'else', 'esac', 'fi', 'for', 'function', 'if', 'in',
        'local', 'return', 'select', 'then', 'until', 'while', 'export', 'readonly', 'declare',
        'source', 'alias', 'unset', 'echo', 'cd', 'set', 'trap', 'shift', 'exit',
    ];

    private const array SQL = [
        'add', 'all', 'alter', 'and', 'as', 'asc', 'between', 'by', 'case', 'cast', 'column',
        'constraint', 'create', 'cross', 'delete', 'desc', 'distinct', 'drop', 'else', 'end',
        'exists', 'foreign', 'from', 'full', 'group', 'having', 'in', 'index', 'inner', 'insert',
        'into', 'is', 'join', 'key', 'left', 'like', 'limit', 'not', 'null', 'offset', 'on', 'or',
        'order', 'outer', 'primary', 'references', 'returning', 'right', 'select', 'set', 'table',
        'then', 'union', 'unique', 'update', 'using', 'values', 'view', 'when', 'where', 'with',
    ];

    private const array SQL_TYPES = [
        'bigint', 'blob', 'boolean', 'char', 'date', 'decimal', 'double', 'float', 'int',
        'integer', 'json', 'jsonb', 'numeric', 'real', 'serial', 'smallint', 'text',
        'timestamp', 'uuid', 'varchar',
    ];

    private const array CSS = [
        'and', 'charset', 'container', 'font-face', 'from', 'import', 'keyframes', 'layer',
        'media', 'not', 'only', 'supports', 'to', 'important', 'include', 'mixin', 'extend', 'use',
    ];
}
