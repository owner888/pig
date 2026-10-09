<?php

declare(strict_types=1);

namespace Pig\Codemode\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Codemode\Declarations;
use Pig\Codemode\Identifier;
use Pig\Codemode\Sandbox;
use Pig\Codemode\Source;
use Pig\Codemode\SourceError;

final class CodemodeTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    // ---- source ---------------------------------------------------------------------------------

    public function testTheOptionsLineIsSplitOffAndChecked(): void
    {
        $plain = Source::parse("return 1;");
        $this->assertSame('return 1;', $plain->code);
        $this->assertSame([], $plain->options);

        $with = Source::parse("// @options: {\"max_output_tokens\": 500, \"timeout_ms\": 2000}\nreturn 1;");
        $this->assertSame("\nreturn 1;", $with->code, 'the line is taken off and the newline kept, so line numbers hold');
        $this->assertSame(['maxOutputTokens' => 500, 'timeoutMs' => 2000], $with->options);

        $this->assertSame("  // @options: {}\nx", "  // @options: {}\nx");
        $this->assertSame([], Source::parse("  // @options: {}\nreturn 1;")->options, 'leading whitespace is fine');
    }

    /** @return iterable<string, array{string, string}> */
    public static function badSources(): iterable
    {
        yield 'empty' => ['   ', 'Expected PHP source text (non-empty)'];
        yield 'options and nothing after' => ['// @options: {"timeout_ms": 1}', 'must be followed by PHP source'];
        yield 'options not json' => ["// @options: nope\nreturn 1;", 'must be valid JSON'];
        yield 'options not an object' => ["// @options: [1]\nreturn 1;", 'must be a JSON object'];
        yield 'unknown field' => ["// @options: {\"colour\": 1}\nreturn 1;", 'got `colour`'];
        yield 'negative tokens' => ["// @options: {\"max_output_tokens\": -1}\nreturn 1;", 'non-negative safe integer'];
        yield 'zero timeout' => ["// @options: {\"timeout_ms\": 0}\nreturn 1;", 'positive integer'];
        yield 'float timeout' => ["// @options: {\"timeout_ms\": 1.5}\nreturn 1;", 'positive integer'];
    }

    #[DataProvider('badSources')]
    public function testABadSourceIsRefusedByName(string $input, string $complaint): void
    {
        try {
            Source::parse($input);
            $this->fail('refused');
        } catch (SourceError $error) {
            $this->assertStringContainsString($complaint, $error->getMessage());
        }
    }

    // ---- identifiers and declarations -----------------------------------------------------------

    public function testAToolNameBecomesAPhpIdentifier(): void
    {
        $this->assertSame('mcp__docs__search', Identifier::of('mcp__docs__search'));
        $this->assertSame('my_tool', Identifier::of('my-tool'));
        $this->assertSame('_st', Identifier::of('1st'), "upstream's rule: a bad character is replaced, not prefixed");
        $this->assertSame('a_b', Identifier::of('a$b'), 'a dollar is not a method-name character');
        $this->assertSame('_', Identifier::of(''));
    }

    public function testASchemaIsRenderedAsAnArrayShape(): void
    {
        $this->assertSame('string', Declarations::schemaToType(['type' => 'string']));
        $this->assertSame('int', Declarations::schemaToType(['type' => 'integer']));
        $this->assertSame('float', Declarations::schemaToType(['type' => 'number']));
        $this->assertSame("'a'|'b'", Declarations::schemaToType(['enum' => ['a', 'b']]));
        $this->assertSame('42', Declarations::schemaToType(['const' => 42]));
        $this->assertSame('string|null', Declarations::schemaToType(['type' => ['string', 'null']]));
        $this->assertSame('list<int>', Declarations::schemaToType(['type' => 'array', 'items' => ['type' => 'integer']]));
        $this->assertSame('array{string, int}', Declarations::schemaToType(['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'integer']]]));
        $this->assertSame('array<string, mixed>', Declarations::schemaToType(['type' => 'object']));
        $this->assertSame('array{a: int, b?: string}', Declarations::schemaToType(['type' => 'object', 'properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'integer']], 'required' => ['a']]));
        $this->assertSame('array{a: int, ...<string, bool>}', Declarations::schemaToType(['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'additionalProperties' => ['type' => 'boolean']]));
        $this->assertSame('mixed', Declarations::schemaToType(true));
        $this->assertSame('never', Declarations::schemaToType(false));
        $this->assertSame('(string|int)&array{x?: int}', Declarations::schemaToType(['allOf' => [['type' => ['string', 'integer']], ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]]]));

        // Descriptions become comments, and a `$ref` is followed — once around a cycle.
        $described = Declarations::schemaToType(['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'Absolute path']], 'required' => ['path']]);
        $this->assertSame("array{\n  // Absolute path\n  path: string,\n}", $described);
        $cyclic = ['type' => 'object', 'properties' => ['next' => ['$ref' => '#']]];
        $this->assertSame('array{next?: array{next?: mixed}}', Declarations::schemaToType($cyclic), 'one expansion, then the guard');

        $tool = ['name' => 'read-file', 'description' => 'Read a file', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]];
        $this->assertSame('$tools->read_file(array{path: string} $input): string', Declarations::signature($tool));
        $this->assertStringContainsString("Read a file\n\ncodemode tool declaration:\n```php\n\$tools->read_file(", Declarations::sample($tool));
    }

    public function testAHugeInputSchemaCollapsesToMixed(): void
    {
        $properties = [];

        for ($i = 0; $i < 2000; $i++) {
            $properties["property_number_{$i}"] = ['type' => 'string'];
        }

        $this->assertSame('mixed', Declarations::schemaToType(['type' => 'object', 'properties' => $properties], Declarations::DEFAULT_INPUT_SCHEMA_MAX_CHARS));
    }

    // ---- the sandbox ----------------------------------------------------------------------------

    /** @param list<array{name: string, description?: string, execute: \Closure}> $tools */
    private function script(string $code, array $tools = [], ?float $timeout = 10.0, array $store = [], ?AbortController $abort = null, int $memory = Sandbox::DEFAULT_MEMORY_LIMIT_BYTES, array $globals = []): array
    {
        $sandbox = new Sandbox($tools, [
            'search_tools' => static fn (array $args): array => [['name' => 'found_' . $args[0], 'description' => '']],
            'describe_tool' => static fn (array $args): ?string => $args[0] === 'known' ? 'the known tool' : null,
            ...$globals,
        ], $timeout, $memory);

        return Async::run(static fn () => $sandbox->execute($code, $abort?->signal, $store));
    }

    private static function texts(array $result): array
    {
        return array_map(static fn (array $o): string => $o['text'] ?? '[image]', $result['output']);
    }

    public function testAScriptRunsCallsToolsAndReturnsAValue(): void
    {
        $seen = [];
        $result = $this->script(
            '$a = $tools->echo(["text" => "one"]); $b = $tools->my_tool(["n" => 2]); text($a); echo "printed\n"; return [$a, $b["doubled"]];',
            [
                ['name' => 'echo', 'execute' => static function (array $args) use (&$seen): string { $seen[] = $args; return 'echo: ' . $args['text']; }],
                ['name' => 'my-tool', 'execute' => static fn (array $args): array => ['doubled' => $args['n'] * 2]],
            ],
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['echo: one', 4], $result['value']);
        $this->assertSame(['echo: one', 'printed'], self::texts($result), 'text() and echo both reach the output, in order');
        $this->assertSame([['text' => 'one']], $seen);
    }

    public function testParallelRunsTheCallsAtOnceAndKeepsTheirOrder(): void
    {
        $order = [];
        $slow = ['name' => 'slow', 'execute' => static function (array $args) use (&$order): string {
            $order[] = "start {$args['n']}";
            Async::delay(0.2);
            $order[] = "end {$args['n']}";

            return "r{$args['n']}";
        }];

        $at = microtime(true);
        $result = $this->script(
            'return parallel([fn () => $tools->slow(["n" => 1]), fn () => $tools->slow(["n" => 2]), fn () => 42, fn () => $tools->slow(["n" => 3])]);',
            [$slow],
        );
        $elapsed = microtime(true) - $at;

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['r1', 'r2', 42, 'r3'], $result['value']);
        $this->assertLessThan(0.5, $elapsed, 'three 0.2s calls took one 0.2s: ' . round($elapsed, 2));
        $this->assertSame(['start 1', 'start 2', 'start 3'], array_slice($order, 0, 3), 'all three started before any ended');
    }

    public function testParallelKeepsTheCallersKeys(): void
    {
        $result = $this->script(
            'return parallel(["b" => fn () => $tools->id(["v" => 2]), "a" => fn () => $tools->id(["v" => 1])]);',
            [['name' => 'id', 'execute' => static fn (array $a): int => $a['v']]],
        );

        $this->assertSame(['b' => 2, 'a' => 1], $result['value'], 'string keys, in the order they were given');
    }

    public function testParallelSettledKeepsGoingPastAFailureAndParallelDoesNot(): void
    {
        $tools = [
            ['name' => 'ok', 'execute' => static fn (): string => 'fine'],
            ['name' => 'bad', 'execute' => static function (): never { throw new \RuntimeException('nope'); }],
        ];

        $settled = $this->script('return parallel_settled([fn () => $tools->bad([]), fn () => $tools->ok([])]);', $tools);
        $this->assertSame([['ok' => false, 'error' => 'nope'], ['ok' => true, 'value' => 'fine']], $settled['value']);

        $strict = $this->script('return parallel([fn () => $tools->bad([]), fn () => $tools->ok([])]);', $tools);
        $this->assertFalse($strict['ok']);
        $this->assertSame('script', $strict['error']['kind']);
        $this->assertStringContainsString('nope', $strict['error']['message']);
    }

    public function testAChainedCallInsideAnArmWorks(): void
    {
        $result = $this->script(
            'return parallel([fn () => $tools->add(["n" => $tools->add(["n" => 1])["n"]]), fn () => $tools->add(["n" => 10])])[0]["n"];',
            [['name' => 'add', 'execute' => static fn (array $a): array => ['n' => $a['n'] + 1]]],
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(3, $result['value']);
    }

    public function testTheSandboxHasNoFilesShellNetworkOrEnvironment(): void
    {
        foreach ([
            'return file_get_contents("/etc/hosts");',
            'return shell_exec("id");',
            'return getenv("HOME");',
            '$f = "sys" . "tem"; return $f("id");',
            'return fsockopen("127.0.0.1", 1);',
            'return (new SplFileObject("/etc/hosts"))->fgets();',
            'return include "/etc/hosts";',
            'ini_set("open_basedir", ""); return 1;',
        ] as $code) {
            $result = $this->script($code);
            $this->assertFalse($result['ok'], "{$code} must not run");
            $this->assertSame('script', $result['error']['kind'], $code);
            $this->assertMatchesRegularExpression('/undefined function|open_basedir restriction/', $result['error']['message'], $code);
        }

        // And the ordinary language is all there.
        $result = $this->script('return [strtoupper("php"), array_sum(range(1, 4)), json_encode(["a" => 1]), preg_match("/x/", "x"), mb_strlen("中文")];');
        $this->assertTrue($result['ok'], 'script succeeded: ' . json_encode($result['error'] ?? null));
        $this->assertSame(['PHP', 10, '{"a":1}', 1, 2], $result['value'] ?? null);
    }

    public function testAScriptErrorNamesTheScriptLine(): void
    {
        $result = $this->script("\$a = 1;\n\$b = [];\nreturn \$b['missing'];");

        $this->assertFalse($result['ok']);
        $this->assertSame('script', $result['error']['kind']);
        $this->assertStringContainsString('Undefined array key "missing"', $result['error']['message']);
        $this->assertStringContainsString('in script line 3', $result['error']['stack']);

        $result = $this->script('return $tools->nope([]);');
        $this->assertStringContainsString('$tools->nope() does not exist', $result['error']['message']);
        $this->assertStringContainsString('in script line 1', $result['error']['stack'], 'an error the prelude raised on the script\'s behalf still points at the script');
    }

    public function testAToolThatDoesNotExistNamesTheCloseMatches(): void
    {
        // Upstream's `guard()`: an error that says how to recover, instead of a bare "unknown
        // tool" that sends the model back to the catalogue. Compared with case and punctuation
        // removed, so the camelCased guess finds the snake_cased tool.
        $tools = [
            ['name' => 'read_text_file', 'execute' => static fn (): string => ''],
            ['name' => 'write_file', 'execute' => static fn (): string => ''],
            ['name' => 'bash', 'execute' => static fn (): string => ''],
        ];

        $result = $this->script('return $tools->readTextFile([]);', $tools);
        $this->assertStringContainsString('$tools->readTextFile() does not exist. Did you mean $tools->read_text_file()?', $result['error']['message']);

        $result = $this->script('return $tools->Bash([]);', $tools);
        $this->assertStringContainsString('Did you mean $tools->bash()?', $result['error']['message']);

        $result = $this->script('return $tools->file([]);', $tools);
        $this->assertStringContainsString('Did you mean $tools->read_text_file(), $tools->write_file()?', $result['error']['message'], 'a name inside two tools names both');

        $result = $this->script('return $tools->zzz([]);', $tools);
        $this->assertStringContainsString('Available: read_text_file, write_file, bash.', $result['error']['message'], 'nothing close and a short list: the list');
        $this->assertStringContainsString('search_tools($query) finds tools by topic', $result['error']['message']);
    }

    public function testAnOversizedStoreValueSaysWhatTheStoreIsFor(): void
    {
        $result = $this->script('store("blob", str_repeat("x", 300 * 1024));');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('store("blob") value has 307202 characters of JSON, more than the limit of 262144', $result['error']['message']);
        $this->assertStringContainsString('store() is for small state such as IDs or summaries', $result['error']['message']);
        $this->assertStringContainsString('Show images with image()', $result['error']['message']);
    }

    public function testTimeoutMemoryAndAbortAreEachNamed(): void
    {
        $result = $this->script('while (true) {}', timeout: 0.3);
        $this->assertSame('timeout', $result['error']['kind']);

        $result = $this->script('$x = str_repeat("x", 64 * 1024 * 1024); return strlen($x);', memory: 32 * 1024 * 1024);
        $this->assertSame('memory', $result['error']['kind']);
        $this->assertStringContainsString('Allowed memory size', $result['error']['message']);

        $abort = new AbortController();
        Loop::get()->delay(0.1, static fn () => $abort->abort('escape'));
        $result = $this->script('while (true) {}', timeout: 5.0, abort: $abort);
        $this->assertSame('aborted', $result['error']['kind']);
        $this->assertSame('escape', $result['error']['message']);

        Loop::get()->tick();
        $this->assertTrue(Loop::get()->isIdle(), 'a killed sandbox leaves nothing behind on the loop');
    }

    public function testOutputThatArrivedBeforeAFailureIsKept(): void
    {
        $result = $this->script('text("before"); throw new RuntimeException("mid");');

        $this->assertFalse($result['ok']);
        $this->assertSame(['before'], self::texts($result));
    }

    public function testExitScriptEndsSuccessfullyAndStoreWritesAreReported(): void
    {
        $result = $this->script('store("k", ["v" => 1]); store("gone", null); text(load("k")["v"]); text(load("old")); exit_script(); text("never");', store: ['old' => 'kept']);

        $this->assertTrue($result['ok']);
        $this->assertSame(['1', 'kept'], self::texts($result));
        $this->assertSame(['set' => ['k' => ['v' => 1]], 'delete' => ['gone']], $result['storeWrites']);

        // Writes of a failed script are still reported; the caller decides to drop them, as upstream's does.
        $failed = $this->script('store("k", 1); throw new RuntimeException("x");');
        $this->assertFalse($failed['ok']);
    }

    public function testImagesAndTheGlobalsReachTheOutput(): void
    {
        $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 8));
        $result = $this->script(
            'image("data:image/png;base64,' . $png . '"); image(["type" => "image", "data" => "' . $png . '", "mimeType" => "image/jpeg"]); text(search_tools("weather")); text(describe_tool("known")); text(describe_tool("unknown")); text(ALL_TOOLS); return null;',
            [['name' => 'a-tool', 'description' => 'the a tool', 'execute' => static fn (): string => '']],
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['type' => 'image', 'data' => $png, 'mimeType' => 'image/png'], $result['output'][0]);
        $this->assertSame('image/png', $result['output'][1]['mimeType'], 'the type comes from the bytes, not from what the block declared');
        $this->assertSame('[{"name":"found_weather","description":""}]', $result['output'][2]['text']);
        $this->assertSame('the known tool', $result['output'][3]['text']);
        $this->assertSame('null', $result['output'][4]['text']);
        $this->assertSame('[{"name":"a_tool","description":"the a tool"}]', $result['output'][5]['text'], 'ALL_TOOLS names the identifier, not the raw name');
    }

    /** @return iterable<string, array{string, string}> */
    public static function imagesThatAreNotOnes(): iterable
    {
        $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 8));

        yield 'a remote url' => ['"https://example/a.png"', 'remote image URLs are not supported'];
        yield 'a data url that is not base64' => ['"data:image/png,' . $png . '"', 'Pass a base64 data URI'];
        yield 'base64 with a character that is not' => ['"data:image/png;base64,' . substr($png, 0, 10) . '!' . substr($png, 11) . '"', 'not valid base64'];
        yield 'base64 of something that is no image' => ['"data:image/png;base64,' . base64_encode('hello world, not a picture') . '"', 'not a PNG, JPEG, GIF, or WebP'];
        yield 'an empty string' => ['""', 'image expects a non-empty image URL'];
        yield 'a list' => ['[1, 2]', 'image expects a non-empty image URL'];
        yield 'a block of another type' => ['["type" => "text", "text" => "x"]', 'image only accepts MCP image blocks, got "text"'];
        yield 'a block with no data' => ['["type" => "image", "data" => ""]', 'image expected MCP image data'];
    }

    #[DataProvider('imagesThatAreNotOnes')]
    public function testAnImageThatIsNotOneIsRefusedByName(string $argument, string $complaint): void
    {
        $result = $this->script("image({$argument}); return 1;");

        $this->assertFalse($result['ok']);
        $this->assertSame('TypeError', $result['error']['name']);
        $this->assertStringContainsString($complaint, $result['error']['message']);
        $this->assertSame([], $result['output'], 'nothing reached the output');
    }

    public function testWrappedBase64IsStraightenedAndAJpegIsDetectedAsOne(): void
    {
        $jpeg = base64_encode("\xff\xd8\xff\xe0" . str_repeat("\0", 20));
        $wrapped = chunk_split($jpeg, 8, "\n");
        $result = $this->script('image("data:image/png;base64,' . addcslashes($wrapped, "\n") . '"); return 1;');

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['type' => 'image', 'data' => $jpeg, 'mimeType' => 'image/jpeg'], $result['output'][0]);
    }

    public function testOutputPastTheLimitFailsTheScriptAndKeepsWhatCameBefore(): void
    {
        // Two texts of 9 MiB: the first is under the 16 MiB limit, the second takes the total past it.
        $result = $this->script('text(str_repeat("x", 9 * 1024 * 1024)); text("second"); text(str_repeat("y", 9 * 1024 * 1024)); text("never"); return 1;');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('script output exceeded the limit', $result['error']['message']);
        $this->assertSame([9 * 1024 * 1024, 6], array_map('strlen', self::texts($result)), 'what arrived before the limit is kept, nothing after it');

        // Catching it does not resume output: the failure was reported before the throw.
        $caught = $this->script('try { text(str_repeat("x", 17 * 1024 * 1024)); } catch (Throwable) {} text("b"); return 1;');
        $this->assertFalse($caught['ok'], 'a script that caught the limit and returned normally is still a failed one');
        $this->assertSame([], self::texts($caught), 'the oversized item never reaches the host, and nothing after it does either');
    }

    public function testWhatEchoWroteIsMarkedAsConsoleOutputAndTextIsNot(): void
    {
        $result = $this->script('echo "printed"; text("said"); print "more"; return null;');

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame([['type' => 'text', 'text' => 'printed', 'console' => true], ['type' => 'text', 'text' => 'said'], ['type' => 'text', 'text' => 'more', 'console' => true]], $result['output']);
    }

    public function testTheModelsNamespaceAndDescribeNamespaceReachTheHost(): void
    {
        $asked = [];
        $result = $this->script(
            'return [$models->getModelOfType("chat", "p", "m"), describe_namespace("ns")];',
            [],
            globals: [
                'models.getModelOfType' => static function (array $args) use (&$asked): array { $asked[] = $args; return ['id' => 'm']; },
                'describe_namespace' => static fn (array $args): array => ['name' => $args[0], 'tools' => []],
            ],
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame([['id' => 'm'], ['name' => 'ns', 'tools' => []]], $result['value']);
        $this->assertSame([['chat', 'p', 'm']], $asked, 'the arguments cross as a list');

        $missing = $this->script('return $models->generate_images();');
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('$models->generate_images() does not exist. Did you mean $models->generateImages()?', $missing['error']['message']);
    }

    public function testAToolIsReachableUnderItsRawNameToo(): void
    {
        $result = $this->script('return [$tools->{"my-tool"}([]), $tools->my_tool([])];', [['name' => 'my-tool', 'execute' => static fn (): string => 'hi']]);

        $this->assertSame(['hi', 'hi'], $result['value']);
    }
}
