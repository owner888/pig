<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Tools\LocalReadOperations;
use Pig\CodingAgent\Tools\ReadOperations;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\Truncate;
use Pig\CodingAgent\Tools\Truncation;
use Pig\Test\AssertsThrows;

/**
 * Upstream #11750: `read` streams the file instead of loading it whole. The whole-file algorithm
 * it replaced is kept here as the reference (`wholeFile()`), and every case is read in chunks of
 * 1, 7 and 4,096 bytes and the default — text, details and errors the same, byte for byte.
 */
final class ReadStreamingTest extends ToolTestCase
{
    use AssertsThrows;

    public function testTheStreamedReadIsTheWholeFileReadForEveryRangeAndChunkSize(): void
    {
        $files = [
            'empty' => '',
            'one line' => 'a',
            'trailing newline' => "a\n",
            'two lines' => "a\nb",
            'only newlines' => "\n\n\n",
            'crlf' => "x\r\ny\r\n",
            'multibyte and invalid' => "é漢\nü\xff\xfe\n",
            'bom' => "\xEF\xBB\xBFbom\nline",
            '2001 lines' => implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 2001))),
            '2001 lines then an empty one' => implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 2001))) . "\n\nmore",
            'a first line over the budget' => str_repeat('x', 60_000) . "\nnext\n",
            'a line exactly the budget' => str_repeat('y', Truncate::MAX_BYTES) . "\n",
            'a line one under the budget' => str_repeat('y', Truncate::MAX_BYTES - 1) . "\nz",
            'the byte cap mid-file' => implode("\n", array_fill(0, 3000, str_repeat('w', 30))),
            'the line cap on an empty line' => implode("\n", array_fill(0, 2000, '')) . "\n",
            'both caps near an empty line' => implode("\n", array_fill(0, 1999, str_repeat('q', 25))) . "\n\n" . str_repeat('r', 100),
        ];

        mt_srand(11750);

        for ($file = 0; $file < 12; $file++) {
            $lines = [];

            for ($i = 0, $n = mt_rand(0, 2500); $i < $n; $i++) {
                $lines[] = mt_rand(0, 9) === 0 ? '' : str_repeat(chr(97 + mt_rand(0, 25)), mt_rand(0, mt_rand(0, 1) === 1 ? 40 : 3000));
            }

            $files["random {$file}"] = implode("\n", $lines) . (mt_rand(0, 1) === 1 ? "\n" : '');
        }

        foreach ($files as $name => $contents) {
            $this->file('f.txt', $contents);
            $total = substr_count($contents, "\n") + 1;
            $offsets = array_values(array_unique([null, 1, 2, $total - 1, $total, $total + 1, intdiv($total, 2), 1999, 2000, 2001, 2002]));

            foreach ([1, 7, 4096, ReadTool::CHUNK_BYTES] as $chunk) {
                // Byte at a time is slow; it is the small files that have the edges to hit.
                if ($chunk < 4096 && strlen($contents) > 8_192) {
                    continue;
                }

                $tool = new ReadTool($this->cwd, chunkBytes: $chunk);

                foreach ($offsets as $offset) {
                    if ($offset !== null && $offset < 1) {
                        continue;
                    }

                    foreach ([null, 0, 1, 2, 5, 1999, 2000, 2001, 3000] as $limit) {
                        $arguments = array_filter(['path' => 'f.txt', 'offset' => $offset, 'limit' => $limit], static fn (mixed $v): bool => $v !== null);
                        $label = "{$name}, chunk {$chunk}, offset " . var_export($offset, true) . ', limit ' . var_export($limit, true);
                        $expected = self::wholeFile($contents, $arguments);

                        try {
                            $result = $this->execute($tool, $arguments);
                        } catch (AgentError $error) {
                            $this->assertSame($expected, ['error' => $error->getMessage()], $label);

                            continue;
                        }

                        $this->assertEquals($expected, ['text' => $this->textOf($result), 'details' => $result->details], $label);
                    }
                }
            }
        }
    }

    public function testALargeFileIsNotHeldWhole(): void
    {
        $line = str_repeat('l', 99) . "\n";
        $path = $this->file('big.log', '');
        $handle = fopen($path, 'wb');

        for ($i = 0; $i < 400; $i++) {
            fwrite($handle, str_repeat($line, 1_000));
        }

        fclose($handle);
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $result = $this->execute(new ReadTool($this->cwd), ['path' => 'big.log', 'offset' => 399_999, 'limit' => 1]);

        $this->assertStringStartsWith(str_repeat('l', 99), $this->textOf($result));
        $this->assertStringContainsString('2 more lines in file. Use offset=400000 to continue', $this->textOf($result));
        // 40 MB on disk; a chunk and the answer in memory.
        $this->assertLessThan(8 << 20, memory_get_peak_usage() - $before);
    }

    /**
     * Upstream #11956, "stops reading a local file when aborted mid-read". Its other half — an
     * abort while the file is opened crashing Node on an unwatched stream error — has no PHP
     * counterpart: the stream here never held the signal, the tool looks at it between chunks and
     * the generator's `finally` closes the file. What can be held is that an abort stops the read
     * at the next look, with most of the file unread.
     */
    public function testAnAbortStopsTheReadWithMostOfTheFileUnread(): void
    {
        $path = $this->file('abort-mid-read.txt', '');
        $handle = fopen($path, 'wb');

        for ($i = 0; $i < 24; $i++) {
            fwrite($handle, str_repeat('x', 1 << 20));
        }

        fclose($handle);

        $controller = new AbortController();
        $operations = new class ($controller) implements ReadOperations {
            public int $chunks = 0;

            public bool $closed = false;

            private readonly LocalReadOperations $local;

            public function __construct(private readonly AbortController $controller)
            {
                $this->local = new LocalReadOperations();
            }

            #[\Override]
            public function readFile(string $absolutePath): string
            {
                return $this->local->readFile($absolutePath);
            }

            #[\Override]
            public function access(string $absolutePath): void
            {
                $this->local->access($absolutePath);
            }

            #[\Override]
            public function detectImageMimeType(string $absolutePath): ?string
            {
                return $this->local->detectImageMimeType($absolutePath);
            }

            #[\Override]
            public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable
            {
                try {
                    foreach ($this->local->createReadStream($absolutePath, $signal) as $chunk) {
                        $this->chunks++;
                        $this->controller->abort();

                        yield $chunk;
                    }
                } finally {
                    $this->closed = true;
                }
            }
        };

        $this->assertThrows(
            AbortError::class,
            fn () => (new ReadTool($this->cwd, operations: $operations))->execute('call-1', ['path' => 'abort-mid-read.txt'], $controller->signal),
        );
        // 24 chunks on disk; the signal is looked at every 8 MB.
        $this->assertSame(8, $operations->chunks);
        gc_collect_cycles();
        $this->assertTrue($operations->closed, 'leaving the loop closes the stream');
    }

    /**
     * The read as it was: `file_get_contents()`, `explode()`, `array_slice()`, `Truncate::head()`.
     *
     * @param array<string, mixed> $arguments
     * @return array{text: string, details: array<string, mixed>|null}|array{error: string}
     */
    private static function wholeFile(string $contents, array $arguments): array
    {
        $lines = explode("\n", $contents);
        $total = count($lines);
        $offset = isset($arguments['offset']) ? max(1, (int) $arguments['offset']) : 1;
        $start = $offset - 1;

        if ($start >= $total) {
            return ['error' => "Offset {$offset} is past the end of the file, which has {$total} lines"];
        }

        $limit = isset($arguments['limit']) ? max(0, (int) $arguments['limit']) : null;
        $selected = $limit === null ? array_slice($lines, $start) : array_slice($lines, $start, $limit);
        $truncation = Truncate::head(implode("\n", $selected));
        $notice = self::notice($truncation, $lines, $offset, $limit);
        $text = $notice === null
            ? $truncation->content
            : ($truncation->content === '' ? "[{$notice}]" : $truncation->content . "\n\n[{$notice}]");
        $details = array_filter(
            ['truncation' => $truncation->truncated ? $truncation : null, 'notice' => $notice],
            static fn (mixed $value): bool => $value !== null,
        );

        return ['text' => $text, 'details' => $details === [] ? null : $details];
    }

    /** @param list<string> $lines */
    private static function notice(Truncation $truncation, array $lines, int $offset, ?int $limit): ?string
    {
        $total = count($lines);
        $start = $offset - 1;

        if ($truncation->firstLineExceedsLimit) {
            $size = Truncate::size(strlen($lines[$start]));
            $cap = Truncate::MAX_BYTES;

            return "Line {$offset} is {$size}, over the " . Truncate::size($cap) . ' limit. '
                . "Use bash: sed -n '{$offset}p' f.txt | head -c {$cap}";
        }

        if ($truncation->truncated) {
            $last = $offset + $truncation->outputLines - 1;
            $why = $truncation->truncatedBy === 'lines' ? '' : ' (' . Truncate::size(Truncate::MAX_BYTES) . ' limit)';

            return "Showing lines {$offset}-{$last} of {$total}{$why}. Use offset=" . ($last + 1) . ' to continue';
        }

        if ($limit !== null && $start + $limit < $total) {
            return ($total - ($start + $limit)) . ' more lines in file. Use offset=' . ($start + $limit + 1) . ' to continue';
        }

        return null;
    }
}
