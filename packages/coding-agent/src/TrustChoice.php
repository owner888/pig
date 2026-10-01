<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/**
 * One answer to "trust this project?": what it means now, and what it writes down.
 *
 * `updates` empty is "this session only" — upstream's option, and the one for a repository
 * somebody is looking at once and does not want a line about in their trust file.
 */
final class TrustChoice
{
    /** @param array<string, bool|null> $updates path => decision, null forgetting the path */
    public function __construct(
        public readonly string $label,
        public readonly bool $trusted,
        public readonly array $updates,
    ) {
    }
}
