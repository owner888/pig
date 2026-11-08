<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;

/** A slash command a hook added, and what to run when someone types it. */
final readonly class RegisteredCommand
{
    /** @param Closure(string, ExtensionContext): void $handler given everything after the name */
    public function __construct(
        public string $name,
        public string $description,
        public Closure $handler,
        public string $extensionPath = '',
    ) {
    }
}
