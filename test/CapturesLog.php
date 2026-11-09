<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\CodingAgent\Logger;

/**
 * What `Logger` says while a piece of a test runs, kept from the console and handed to the test.
 *
 * A warning a test means to cause — a refresh that fails on purpose, a handler that throws — was
 * printed into the middle of the progress dots, where it reads as something having gone wrong. Kept
 * here, it is something the test can assert on instead.
 */
trait CapturesLog
{
    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /**
     * Run $work with the console quiet and every log line kept in `$logged`.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function capturingLog(Closure $work): mixed
    {
        Logger::setConsoleOutput(false);
        Logger::addHandler(self::class, function (string $level, string $message): void {
            $this->logged[] = ['level' => $level, 'message' => $message];
        });

        try {
            return $work();
        } finally {
            Logger::removeHandler(self::class);
            Logger::setConsoleOutput(true);
        }
    }

    /** @return list<string> the warnings logged so far, oldest first */
    private function loggedWarnings(): array
    {
        return array_values(array_map(
            static fn (array $line): string => $line['message'],
            array_filter($this->logged, static fn (array $line): bool => $line['level'] === Logger::LEVEL_WARNING),
        ));
    }
}
