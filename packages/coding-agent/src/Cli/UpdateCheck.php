<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\CodingAgent\Version;
use Throwable;

/**
 * Is there a newer pig than this one — asked of Packagist, once, at startup.
 *
 * Upstream's `checkForNewVersion()`, which fetches `registry.npmjs.org/<pkg>/latest`, compares the
 * version to its own and draws an `Update Available` box telling you the install command. **It does
 * not update itself, and neither does this**: `pi update` is a different feature entirely — it
 * updates *extensions* fetched from `npm:` and `git:` sources, which pig has no equivalent of
 * because its hooks, skills and tools are local paths.
 *
 * This document used to say the check was deliberately not ported, because "reaching for the
 * network at every start is a habit pig refuses elsewhere" and pig was not published. The
 * developer's call reversed both halves: pig goes to Packagist, and the check is on by default with
 * a way to turn it off — which is the shape the old objection actually asked for.
 *
 * Four things about it:
 *
 * - **Nothing to say is the answer to everything that goes wrong.** A 404 (which is what Packagist
 *   answers until pig is published, so that is today's normal case), a body that is not the shape
 *   expected, a proxy that refuses, a machine with no network: all of them are null and nothing on
 *   screen. Not a silent fallback — there is no value being invented — and the alternative is a
 *   warning every morning, which is how people learn to skip warnings.
 * - **It must not block the start.** The caller spawns it, so the answer arrives while the person
 *   is already typing and draws itself then, exactly as upstream's promise does.
 * - **Pre-releases are skipped.** `version_compare()` sorts `0.2.0-beta` below `0.2.0`, correctly,
 *   so a beta can only ever be the newest when there is no release above it — and offering a beta
 *   to somebody who did not ask for one is not what this is for.
 * - **The comparison is `version_compare()`**, as `Changelog`'s is, for the reason written there:
 *   the hand-rolled arithmetic upstream needs is wrong about `0.2` and `0.2.0-beta`.
 */
final class UpdateCheck
{
    /**
     * What to run, which is all this feature does about it.
     *
     * Assumes the global-require install, which is what declaring `bin` in `composer.json` is for.
     * If the release decides otherwise — a phar, a `create-project`, a tap — this string is the one
     * place to change. The package name comes from `Version`, which is the one place it is written.
     */
    /**
     * What to run to perform the update.
     */
    public const string COMMAND = 'pig update';

    /**
     * Short on purpose: nobody is waiting for this, and a machine behind a hostile network should
     * not have a slow start because of a feature that only ever draws one line.
     */
    private const float TIMEOUT = 3.0;

    /**
     * @param string $endpoint the metadata root, so a test can point it at a loopback server. The
     *        same seam every provider in `pig/ai` has, and not one that exists for the test alone.
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(self::TIMEOUT),
        private readonly string $endpoint = 'https://repo.packagist.org/p2/',
    ) {
    }

    /** The newest released version if it is newer than $current, or null when there is nothing to say. */
    public function newerThan(string $current): ?string
    {
        // Nothing to be behind, and nothing asked of Packagist either — a developer running from a
        // checkout gets no request, which is the other half of not drawing anything.
        if (!Version::isRelease($current)) {
            return null;
        }

        try {
            $newest = $this->newest();
        } catch (Throwable) {
            // Every failure is the same answer, and the docblock says why.
            return null;
        }

        if ($newest === null || version_compare($newest, $current, '<=')) {
            return null;
        }

        return $newest;
    }

    /** The highest released version Packagist lists, or null. */
    private function newest(): ?string
    {
        $response = $this->http->follow(new Request('GET', $this->endpoint . Version::PACKAGE . '.json', [
            'accept' => 'application/json',
        ]));

        $body = $response->body->all();

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($body, true);
        $versions = $decoded['packages'][Version::PACKAGE] ?? null;

        if (!is_array($versions)) {
            return null;
        }

        $newest = null;

        foreach ($versions as $release) {
            $version = is_array($release) ? ($release['version'] ?? null) : null;

            if (!is_string($version)) {
                continue;
            }

            // Packagist writes the tag as it was cut, so a `v` prefix reaches here; and a hyphen is
            // a pre-release, which is not what somebody who did not ask gets offered. The stripping
            // is `Version`'s, because the other side of this comparison does it too.
            $version = Version::plain($version);

            if ($version === '' || str_contains($version, '-')) {
                continue;
            }

            if ($newest === null || version_compare($version, $newest, '>')) {
                $newest = $version;
            }
        }

        return $newest;
    }
}
