<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * A package installed with Composer — upstream's `NpmSource`, with Packagist (or any repository
 * the person's Composer is configured for) where upstream has the npm registry.
 *
 * `composer:vendor/name@^1.2`: `name` is the Composer package name, `version` the constraint
 * after the `@` (Composer's own `^1.0@dev` stability flag survives, since only the first `@`
 * splits). `pinned` is an exact version, upstream's `isExactNpmVersion()`: `update` leaves it.
 */
final readonly class ComposerSource
{
    public function __construct(
        public string $name,
        public ?string $version = null,
    ) {
    }

    /** What `composer require` is handed: `vendor/name`, or `vendor/name:<constraint>`. */
    public function requirement(): string
    {
        return $this->version === null ? $this->name : "{$this->name}:{$this->version}";
    }

    public function pinned(): bool
    {
        return $this->version !== null && preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $this->version) === 1;
    }
}
