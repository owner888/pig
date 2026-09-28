<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * pig's own version, as Composer resolved it.
 *
 * **The git tag is the one source, and this reads Composer's answer rather than a field.** It used
 * to read `"version"` out of `composer.json`, on the reasoning that one written-down number beats
 * two — and that was the right instinct pointed at the wrong file: Packagist derives a package's
 * version from its **tag** and says in as many words that the field "should be omitted", because a
 * field and a tag are exactly the two copies that can disagree. So the field is gone and
 * `Composer\InstalledVersions` answers, which is Composer telling us what it installed.
 *
 * What that costs is one platform requirement, `composer-runtime-api`, which is Composer's own
 * runtime and not a package anything downloads — the same kind of entry as `php` or `ext-json`.
 *
 * **A checkout is not a release, and Composer says so in its own words.** A working tree with no tag
 * reachable comes back as `1.0.0+no-version-set` and a branch as `dev-main`; both are printed as
 * they are, because `no-version-set` is a sentence a developer can read and an invented `0.0.0`
 * would be a number they would have to go and check. What must not happen is comparing one against
 * Packagist, and that rule lives in `Cli\UpdateCheck` with the other reasons it says nothing.
 */
final class Version
{
    /**
     * The package as Composer and Packagist know it — this repository's own name.
     *
     * **One copy, here.** `Cli\UpdateCheck` asks Packagist about it and builds the install command
     * out of it, and this asks Composer about it; a second spelling is the one kind of typo that
     * would make those two questions be about different packages.
     */
    public const string PACKAGE = 'pig/pig';

    /**
     * The version this build is, as Composer resolved it, without a `v` on the front.
     *
     * **A build Composer has never heard of throws**, rather than answering `0.0.0` or `unknown`.
     * Every other loader here names what it cannot read instead of carrying on, and a made-up
     * version is worse than a missing feature: it is a number `/changelog` filters on and the update
     * check would compare against Packagist.
     */
    public static function current(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (OutOfBoundsException $problem) {
            throw new CodingAgentError(
                "Cannot read pig's own version: Composer does not know about " . self::PACKAGE
                . '. Was this installed with composer?',
                previous: $problem,
            );
        }

        if ($version === null || $version === '') {
            throw new CodingAgentError("Cannot read pig's own version: Composer has no version for " . self::PACKAGE . '.');
        }

        // Packagist keeps the tag as it was cut, so `v0.1.0` reaches here; `Cli\UpdateCheck` strips
        // it on the other side of the comparison for the same reason.
        return ltrim($version, 'vV');
    }
}
