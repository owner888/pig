<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * A configured package that could not be installed while its resources were being resolved —
 * upstream's `PackageInstallFailure`. It is left out, as offline mode leaves a package out, and
 * named in a warning; the install is tried again on the next start.
 */
final readonly class PackageInstallFailure
{
    /** @param string $scope `user`, `project` or `temporary` (an `-e` source) */
    public function __construct(
        public string $source,
        public string $scope,
        public string $message,
    ) {
    }

    /** Upstream's `formatPackageInstallWarning()`: one line, with how to stop it happening. */
    public function warning(): string
    {
        $hint = $this->scope === 'temporary'
            ? 'Check the source and try again.'
            : "Run `pig remove {$this->source}" . ($this->scope === 'project' ? ' --local' : '') . '` to remove it from settings.json.';

        return "Extension package \"{$this->source}\": failed to install ({$this->message}). {$hint}";
    }
}
