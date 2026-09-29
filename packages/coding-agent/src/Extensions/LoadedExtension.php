<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

/**
 * An extension that loaded successfully.
 */
final readonly class LoadedExtension
{
    public function __construct(
        public string $path,
        public string $resolved,
        public string $name,
        public ExtensionApi $api,
    ) {
    }
}
