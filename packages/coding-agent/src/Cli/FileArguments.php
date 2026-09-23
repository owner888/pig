<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Agent\AgentError;
use Pig\Ai\ImageContent;
use Pig\CodingAgent\Tools\Paths;
use Pig\Tui\Images\ImageType;

/**
 * `@file` on the command line, turned into something to say to the model.
 *
 * `bin/pig @src/Thing.php "why is this slow?"` reads the file in front of the question, so
 * the first thing the model sees is the code rather than a request to go and find it. An
 * image goes in as an image — which is how a screenshot gets into a conversation without a
 * tool call.
 *
 * Wrapped in `<file name="...">` with the absolute path, both because the model needs to
 * know which file it is looking at and because a second `@file` would otherwise run into the
 * first with nothing between them.
 *
 * Ported from upstream's `cli/file-processor.ts`. One difference: it prints and calls
 * `process.exit(1)` on a missing file, this throws. A class that exits cannot be tested, and
 * `bin/pig` is the one place that knows how to end the program.
 */
final readonly class FileArguments
{
    /** @param list<ImageContent> $images */
    public function __construct(
        public string $text,
        public array $images,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->text === '' && $this->images === [];
    }

    /**
     * Read every one of them, in the order they were given.
     *
     * @param list<string> $paths as they were written, without the `@`
     * @throws AgentError for a path that is not there or cannot be read
     */
    public static function read(array $paths, string $cwd): self
    {
        $text = '';
        $images = [];

        foreach ($paths as $path) {
            $absolute = Paths::resolveForRead($path, $cwd);

            if (!is_file($absolute)) {
                throw new AgentError("No such file: {$path}");
            }

            // Empty files are skipped rather than refused: `@notes.md` on a file someone has
            // not written yet is a reasonable thing to have typed, and an empty
            // `<file>` element tells the model nothing it can use.
            if (filesize($absolute) === 0) {
                continue;
            }

            $mimeType = ImageType::ofFile($absolute);
            $bytes = file_get_contents($absolute);

            if ($bytes === false) {
                throw new AgentError("Could not read {$path}");
            }

            if ($mimeType !== null) {
                $images[] = new ImageContent(base64_encode($bytes), $mimeType);

                // The element is empty, and still worth sending: it is what tells the model
                // which file the picture beside it came from.
                $text .= "<file name=\"{$absolute}\"></file>\n";

                continue;
            }

            $text .= "<file name=\"{$absolute}\">\n{$bytes}\n</file>\n";
        }

        return new self($text, $images);
    }

    /**
     * The files in front of the question, as one message.
     *
     * With nothing said, the files are the whole message — `bin/pig -p @error.log` is a
     * reasonable way to ask what is in a log.
     */
    public function before(?string $message): string
    {
        return $message === null || $message === '' ? $this->text : $this->text . $message;
    }
}
