<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\UpdateCheck;
use Pig\CodingAgent\Version;
use Pig\Test\CannedServer;

/**
 * Is there a newer pig — asked of a canned Packagist rather than the real one.
 *
 * What is worth testing is not the HTTP. It is that **every way this can go wrong is silence**,
 * because the thing it draws appears at startup and a feature that can put a wrong sentence there
 * is worse than one that says nothing — and because until pig is published, Packagist answers 404
 * and that is the ordinary case rather than an edge one.
 */
final class UpdateCheckTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    /** @param array<string, mixed>|string $body */
    private function asking(array|string $body, int $status = 200, string $reason = 'OK'): UpdateCheck
    {
        $payload = is_string($body) ? $body : (string) json_encode($body);

        $url = $this->server->start([
            "HTTP/1.1 {$status} {$reason}\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($payload) . "\r\n"
            . "connection: close\r\n\r\n"
            . $payload,
        ]);

        return new UpdateCheck(new HttpClient(5.0), $url);
    }

    /** @param list<string> $versions */
    private static function packagist(array $versions): array
    {
        return ['packages' => [Version::PACKAGE => array_map(
            static fn (string $version): array => ['version' => $version],
            $versions,
        )]];
    }

    public function testANewerReleaseIsReported(): void
    {
        $check = $this->asking(self::packagist(['0.2.0', '0.1.0']));

        $this->assertSame('0.2.0', Async::run(static fn () => $check->newerThan('0.1.0')));
    }

    public function testTheHighestIsTakenAndNotTheFirstOneListed(): void
    {
        // Packagist writes them newest first, and that is a convention rather than a promise —
        // `version_compare()` is what decides, which is also why `0.10.0` beats `0.9.0` here.
        $check = $this->asking(self::packagist(['0.9.0', '0.10.0', '0.2.0']));

        $this->assertSame('0.10.0', Async::run(static fn () => $check->newerThan('0.1.0')));
    }

    public function testTheVersionThisIsGetsNothing(): void
    {
        $check = $this->asking(self::packagist(['0.2.0', '0.1.0']));

        $this->assertNull(Async::run(static fn () => $check->newerThan('0.2.0')));
    }

    public function testSomethingNewerThanPackagistKnowsAboutGetsNothing(): void
    {
        // A working copy sitting ahead of the last release, which is where pig is developed.
        $check = $this->asking(self::packagist(['0.2.0']));

        $this->assertNull(Async::run(static fn () => $check->newerThan('0.3.0')));
    }

    public function testAPreReleaseIsNotOfferedToSomebodyWhoDidNotAskForOne(): void
    {
        $check = $this->asking(self::packagist(['0.2.0-beta.1', '0.1.0']));

        $this->assertNull(Async::run(static fn () => $check->newerThan('0.1.0')));

        // And it does not hide the release above it either.
        $both = $this->asking(self::packagist(['0.3.0-rc1', '0.2.0', '0.1.0']));

        $this->assertSame('0.2.0', Async::run(static fn () => $both->newerThan('0.1.0')));
    }

    public function testATagCutWithAVeeIsStillThatVersion(): void
    {
        $check = $this->asking(self::packagist(['v0.2.0']));

        $this->assertSame('0.2.0', Async::run(static fn () => $check->newerThan('0.1.0')));
    }

    /**
     * Everything that goes wrong is the same answer, and nothing on screen.
     *
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('waysItCanGoWrong')]
    public function testThereIsNothingToSayWhenTheAnswerIsNotOne(array|string $body, int $status): void
    {
        $check = $this->asking($body, $status, $status === 404 ? 'Not Found' : 'Nope');

        $this->assertNull(Async::run(static fn () => $check->newerThan('0.1.0')));
    }

    /** @return array<string, array{0: array<string, mixed>|string, 1: int}> */
    public static function waysItCanGoWrong(): array
    {
        return [
            // Today's ordinary case: pig is not published, so this is what Packagist says.
            'not published yet' => [['status' => 'error'], 404],
            'packagist having a bad day' => [['status' => 'error'], 500],
            'not json at all' => ['<html>go away</html>', 200],
            'json of the wrong shape' => [['packages' => 'surprise'], 200],
            'a package that is not this one' => [['packages' => ['someone/else' => []]], 200],
            'no versions in it' => [['packages' => [Version::PACKAGE => []]], 200],
            'a release with no version field' => [['packages' => [Version::PACKAGE => [['ref' => 'x']]]], 200],
            'an empty body' => ['', 200],
        ];
    }

    public function testAnUnreachablePackagistIsNotAnError(): void
    {
        // RFC 2606 says this cannot resolve, which is `ProxyTest`'s trick and for the same reason:
        // a version of this that threw would take the start down with it.
        $check = new UpdateCheck(new HttpClient(1.0), 'https://packagist.invalid/p2/');

        $this->assertNull(Async::run(static fn () => $check->newerThan('0.1.0')));
    }

    // ---- the version it is comparing against ---------------------------------------------

    public function testTheVersionIsComposersAnswerAndIsWrittenDownNowhere(): void
    {
        // **The tag is the source, so nothing in the repository may hold the number.** Packagist
        // derives a package's version from its tag and says the `version` field "should be
        // omitted" for exactly that reason — a field and a tag are the two copies that can
        // disagree. This asserted the field's value until the day pig was nearly published.
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayNotHasKey('version', $manifest, 'the tag decides; a field here can disagree with it');
        $this->assertSame(Version::PACKAGE, $manifest['name'] ?? null, 'and Composer is asked about this name');
        $this->assertNotSame('', Version::current());
    }

    /**
     * A tag cut with a `v` on it is not the version it reports, on either side of the comparison.
     *
     * **This was an `InstalledVersions::reload()` and it could not work.** `getInstalled()` consults
     * every registered ClassLoader's dataset *before* the one `reload()` sets, so a fabricated
     * version is ignored wherever an autoloader is registered — which is every real run. It passed
     * under the verification shim, which registers none, and failed under PHPUnit reporting the real
     * `dev-main`. The rule is a pure string operation and existed in two places, so it is one now and
     * tested as what it is.
     */
    #[DataProvider('versionsWithAndWithoutAVee')]
    public function testATagCutWithAVeeIsNotTheVersionItReports(string $given, string $expected): void
    {
        $this->assertSame($expected, Version::plain($given));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function versionsWithAndWithoutAVee(): array
    {
        return [
            'the common spelling of a tag' => ['v0.1.0', '0.1.0'],
            'a capital, which git allows' => ['V0.1.0', '0.1.0'],
            'already plain' => ['0.1.0', '0.1.0'],
            'a pre-release keeps its suffix' => ['v0.2.0-beta.1', '0.2.0-beta.1'],
            'a branch is left alone' => ['dev-main', 'dev-main'],
            'and so is what Composer says with no tag' => ['1.0.0+no-version-set', '1.0.0+no-version-set'],
        ];
    }

    public function testWhatCurrentReportsHasNoVeeOnIt(): void
    {
        // The other end: whatever Composer answers on this machine, the version pig reports and
        // compares is the plain one. Both readers of the rule go through the one implementation.
        $this->assertSame(Version::plain(Version::current()), Version::current());
    }

    public function testACheckoutIsNotOutOfDateAndIsNotAsked(): void
    {
        // What Composer answers for a working tree: `dev-main` for a branch, and
        // `1.0.0+no-version-set` when no tag is reachable. **The second is the dangerous one** — it
        // reads as `1.0.0`, so an unguarded `version_compare()` puts a checkout ahead of every real
        // release and goes quiet for a reason that has nothing to do with the truth. The endpoint
        // here is a server that would answer a newer version, so a gate that let either through
        // would report one.
        $branch = $this->asking(self::packagist(['9.9.9']));

        $this->assertNull(Async::run(static fn () => $branch->newerThan('dev-main')));

        $untagged = $this->asking(self::packagist(['9.9.9']));

        $this->assertNull(Async::run(static fn () => $untagged->newerThan('1.0.0+no-version-set')));
    }

    #[DataProvider('releaseAndNonReleaseVersions')]
    public function testIsReleaseTellsReleasesFromCheckouts(string $version, bool $expected): void
    {
        $this->assertSame($expected, Version::isRelease($version));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function releaseAndNonReleaseVersions(): array
    {
        return [
            'standard three-part release' => ['0.2.0', true],
            'major release' => ['1.0.0', true],
            'pre-release beta' => ['0.2.0-beta.1', true],
            'pre-release rc' => ['1.0.0-RC.2', true],
            'branch' => ['dev-main', false],
            'feature branch' => ['dev-feature-x', false],
            'untagged checkout' => ['1.0.0+no-version-set', false],
            'arbitrary string' => ['nonsense', false],
            'empty string' => ['', false],
        ];
    }

    public function testAPreReleaseOfPigIsStillToldAboutTheRelease(): void
    {
        // The asymmetry, and it is deliberate: a beta is never *offered* to somebody who did not ask
        // for one, and somebody already running one is still told when the release lands. That is
        // `Changelog`'s own note from the other end — the arithmetic it replaced read `0.2.0-beta`
        // as equal to the release it precedes, so a beta user saw nothing after upgrading.
        $check = $this->asking(self::packagist(['0.2.0', '0.1.0']));

        $this->assertSame('0.2.0', Async::run(static fn () => $check->newerThan('0.2.0-beta.1')));
    }

    public function testTheChangelogHasAnEntryForAReleasedVersion(): void
    {
        $version = Version::current();

        if (preg_match('/^\d+\.\d+\.\d+\z/', $version) !== 1) {
            // A checkout has no release to have notes for. This is the case on a developer's machine
            // and the assertion below is the one that matters on a tag, which is where it runs.
            self::markTestSkipped("Not a released build ({$version})");
        }

        // The two are read together at startup — the version decides which entries are new — so a
        // release whose number is in neither place is a release nobody is told about.
        // `Changelog::heading()` accepts both `## [0.2.0]` and `## 0.2.0`.
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/CHANGELOG.md');
        $this->assertTrue(
            str_contains($content, "## [{$version}]") || str_contains($content, "## {$version}"),
            "CHANGELOG.md has an entry for {$version}",
        );
    }
}
