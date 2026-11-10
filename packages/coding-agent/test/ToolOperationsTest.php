<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Closure;
use Pig\CodingAgent\Tools\BashOperations;
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\CommandStopped;
use Pig\CodingAgent\Tools\EditOperations;
use Pig\CodingAgent\Tools\EditTool;
use Pig\CodingAgent\Tools\FileAccessError;
use Pig\CodingAgent\Tools\FileStat;
use Pig\CodingAgent\Tools\FindOperations;
use Pig\CodingAgent\Tools\FindTool;
use Pig\CodingAgent\Tools\GrepOperations;
use Pig\CodingAgent\Tools\GrepTool;
use Pig\CodingAgent\Tools\LsOperations;
use Pig\CodingAgent\Tools\LsTool;
use Pig\CodingAgent\Tools\ReadOperations;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\WriteOperations;
use Pig\CodingAgent\Tools\WriteTool;
use Pig\Test\AssertsThrows;

/**
 * Upstream's `*Operations`: each file tool given its own operations reads, writes and lists through
 * them and touches nothing on this machine — the cwd here does not exist, so a stray `is_file()`
 * would fail the test (docs/dev/design/storage.md, phase 2).
 */
final class ToolOperationsTest extends TestCase
{
    use AssertsThrows;

    private const string CWD = '/nowhere/on/this/machine';

    private static function text(AgentToolResult $result): string
    {
        $block = $result->content[0];
        self::assertInstanceOf(TextContent::class, $block);

        return $block->text;
    }

    /** @param array<string, string> $files absolute path => contents */
    private static function reader(array $files): ReadOperations
    {
        return new class ($files) implements ReadOperations {
            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function readFile(string $absolutePath): string
            {
                return $this->files[$absolutePath];
            }

            public function access(string $absolutePath): void
            {
                if (!isset($this->files[$absolutePath])) {
                    throw new FileAccessError(FileAccessError::NOT_FOUND, $absolutePath);
                }
            }

            public function detectImageMimeType(string $absolutePath): ?string
            {
                return null;
            }

            public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable
            {
                // In small pieces, so a line is split across chunks as a remote stream would split it.
                return str_split($this->files[$absolutePath], 3);
            }
        };
    }

    public function testReadReadsThroughItsOperations(): void
    {
        $tool = new ReadTool(self::CWD, operations: self::reader([self::CWD . '/notes.txt' => "first\nsecond\nthird\n"]));

        $this->assertStringContainsString("first\nsecond\nthird", self::text($tool->execute('1', ['path' => 'notes.txt'])));
        $this->assertStringStartsWith('second', self::text($tool->execute('2', ['path' => 'notes.txt', 'offset' => 2, 'limit' => 1])));
        $this->assertThrows(AgentError::class, static fn () => $tool->execute('3', ['path' => 'missing.txt']), 'No such file: missing.txt');
    }

    public function testWriteWritesThroughItsOperations(): void
    {
        $ops = new class implements WriteOperations {
            /** @var list<array{string, string}> */
            public array $calls = [];

            public function writeFile(string $absolutePath, string $content): void
            {
                $this->calls[] = ['write', $absolutePath . '=' . $content];
            }

            public function mkdir(string $dir): void
            {
                $this->calls[] = ['mkdir', $dir];
            }
        };

        $result = (new WriteTool(self::CWD, $ops))->execute('1', ['path' => 'src/new.txt', 'content' => '你好']);

        $this->assertSame([['mkdir', self::CWD . '/src'], ['write', self::CWD . '/src/new.txt=你好']], $ops->calls);
        $this->assertSame('Wrote 6 bytes to src/new.txt', self::text($result));
    }

    public function testWriteSaysWhichPathIsADirectory(): void
    {
        $ops = new class implements WriteOperations {
            public function writeFile(string $absolutePath, string $content): void
            {
                throw new FileAccessError(FileAccessError::IS_DIRECTORY, $absolutePath);
            }

            public function mkdir(string $dir): void
            {
            }
        };

        $this->assertThrows(AgentError::class, static fn () => (new WriteTool(self::CWD, $ops))->execute('1', ['path' => 'src', 'content' => 'x']), 'src is a directory');
    }

    public function testEditReadsAndWritesThroughItsOperations(): void
    {
        $ops = new class implements EditOperations {
            /** @var array<string, string> */
            public array $files = ['/nowhere/on/this/machine/a.txt' => "hello world\n"];

            public function readFile(string $absolutePath): string
            {
                return $this->files[$absolutePath];
            }

            public function writeFile(string $absolutePath, string $content): void
            {
                $this->files[$absolutePath] = $content;
            }

            public function access(string $absolutePath): void
            {
                if (!isset($this->files[$absolutePath])) {
                    throw new FileAccessError(FileAccessError::NOT_FOUND, $absolutePath);
                }
            }
        };
        $tool = new EditTool(self::CWD, $ops);

        $tool->execute('1', ['path' => 'a.txt', 'edits' => [['oldText' => 'world', 'newText' => 'there']]]);

        $this->assertSame("hello there\n", $ops->files[self::CWD . '/a.txt']);
        $this->assertThrows(AgentError::class, static fn () => $tool->execute('2', ['path' => 'b.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]]), 'File not found: b.txt');
    }

    public function testLsListsThroughItsOperations(): void
    {
        $ops = new class implements LsOperations {
            public function exists(string $absolutePath): bool
            {
                return true;
            }

            public function stat(string $absolutePath): FileStat
            {
                return new FileStat(!str_ends_with($absolutePath, '.md'));
            }

            public function readdir(string $absolutePath): array
            {
                return ['src', 'README.md'];
            }
        };

        $this->assertSame("README.md\nsrc/", self::text((new LsTool(self::CWD, $ops))->execute('1', [])));
    }

    public function testFindAsksItsOperationsGlobInsteadOfFd(): void
    {
        $ops = new class implements FindOperations {
            /** @var list<mixed> */
            public array $asked = [];

            public function exists(string $absolutePath): bool
            {
                return $absolutePath === '/nowhere/on/this/machine';
            }

            public function glob(string $pattern, string $cwd, array $ignore, int $limit): array
            {
                $this->asked = [$pattern, $cwd, $ignore, $limit];

                return ['/nowhere/on/this/machine/src/a.php', 'b.php'];
            }
        };
        $tool = new FindTool(self::CWD, $ops);

        $this->assertSame("src/a.php\nb.php", self::text($tool->execute('1', ['pattern' => '*.php'])));
        $this->assertSame(['*.php', self::CWD, ['**/node_modules/**', '**/.git/**'], 1001], $ops->asked, 'one past the limit (upstream #7121)');
        $this->assertStringContainsString('1 result limit reached', self::text($tool->execute('3', ['pattern' => '*.php', 'limit' => 1])));
        $this->assertThrows(AgentError::class, static fn () => $tool->execute('2', ['pattern' => '*', 'path' => 'gone']), 'No such directory: gone');
    }

    public function testGrepAsksItsOperationsWhetherThePathIsThere(): void
    {
        $ops = new class implements GrepOperations {
            public function isDirectory(string $absolutePath): bool
            {
                throw new FileAccessError(FileAccessError::NOT_FOUND, $absolutePath);
            }
        };

        $this->assertThrows(AgentError::class, static fn () => (new GrepTool(self::CWD, $ops))->execute('1', ['pattern' => 'x']), 'No such file or directory: .');
    }

    public function testAFailureKeepsItsReasonAfterTheToolsOwnSentence(): void
    {
        // A full disk and a read-only one are different next steps; "Could not write" alone is neither.
        $ops = new class implements WriteOperations {
            public function writeFile(string $absolutePath, string $content): void
            {
                throw new FileAccessError(FileAccessError::FAILED, "Could not write {$absolutePath}", 'No space left on device');
            }

            public function mkdir(string $dir): void
            {
            }
        };

        $this->assertThrows(
            AgentError::class,
            static fn () => (new WriteTool(self::CWD, $ops))->execute('1', ['path' => 'a.txt', 'content' => 'x']),
            'Could not write a.txt: No space left on device',
        );
    }

    public function testBashRunsItsCommandThroughItsOperations(): void
    {
        $ops = new class implements BashOperations {
            /** @var list<mixed> */
            public array $asked = [];

            public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
            {
                $this->asked = [$command, $cwd, $timeout];
                // An escape sequence split across two reads of stdout, with stderr in between: it is
                // completed by its own stream and stripped whole.
                $onData("\e[3", 1);
                $onData("oops\n", 2);
                $onData("1mhello\e[0m\n", 1);

                return 0;
            }
        };

        $result = (new BashTool(self::CWD, exposeSessionEnvironment: false, operations: $ops))->execute('1', ['command' => 'echo hello', 'timeout' => 5]);

        $this->assertSame(['echo hello', self::CWD, 5.0], $ops->asked);
        $this->assertSame("oops\nhello\n", self::text($result));
    }

    public function testBashSaysWhenItsOperationsStoppedTheCommand(): void
    {
        $ops = new class implements BashOperations {
            public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
            {
                $onData("partial\n", 1);

                throw new CommandStopped(timedOut: true);
            }
        };

        $this->assertThrows(
            AgentError::class,
            static fn () => (new BashTool(self::CWD, operations: $ops))->execute('1', ['command' => 'sleep 9', 'timeout' => 1]),
            'Command timed out after 1 seconds',
        );
    }
}
