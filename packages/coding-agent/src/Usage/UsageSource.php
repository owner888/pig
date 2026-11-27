<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

use Closure;
use InvalidArgumentException;

/** A usage source as `ExtensionApi::registerUsage()` keeps it. */
final readonly class UsageSource
{
    /** The shortest refresh: a status line is not worth more requests than this to anyone's quota service. */
    public const int MIN_REFRESH_SECONDS = 60;

    /**
     * @param string|array<string, string> $title by locale, as a web panel's
     * @param Closure(): UsageReport $fetch may make network calls; runs in a fiber
     */
    public function __construct(
        public string $id,
        public string|array $title,
        public Closure $fetch,
        public bool $status = false,
        public int $refreshSeconds = 300,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException("A usage id is lower-case letters, digits, '.', '_' and '-': '{$id}' is not one.");
        }

        if (is_array($title) && $title === []) {
            throw new InvalidArgumentException("Usage '{$id}' has no title.");
        }

        if ($refreshSeconds < self::MIN_REFRESH_SECONDS) {
            throw new InvalidArgumentException("Usage '{$id}': refreshSeconds is at least " . self::MIN_REFRESH_SECONDS . ", not {$refreshSeconds}.");
        }
    }

    /** The title in `$locale`, else in English, else the first one there is. */
    public function titleFor(string $locale = 'en'): string
    {
        return is_string($this->title) ? $this->title : ($this->title[$locale] ?? $this->title['en'] ?? (string) array_values($this->title)[0]);
    }

    public function fetch(): UsageReport
    {
        $report = ($this->fetch)();

        if (!$report instanceof UsageReport) {
            throw new \UnexpectedValueException("Usage '{$this->id}' answered " . get_debug_type($report) . ', not a UsageReport.');
        }

        return $report;
    }
}
