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
        return ['packages' => [UpdateCheck::PACKAGE => array_map(
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
            'no versions in it' => [['packages' => [UpdateCheck::PACKAGE => []]], 200],
            'a release with no version field' => [['packages' => [UpdateCheck::PACKAGE => [['ref' => 'x']]]], 200],
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

    public function testPigsOwnVersionComesFromComposerJsonAndNowhereElse(): void
    {
        // One source, because the update check compares against Packagist: a second copy that
        // said 0.1.0 while the tag said 0.2.0 would report itself out of date for ever.
        $manifest = json_decode((string) file_get_contents(Version::path()), true);

        $this->assertIsArray($manifest);
        $this->assertSame($manifest['version'], Version::current());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Version::current());
    }

    public function testTheChangelogHasAnEntryForTheVersionThisIs(): void
    {
        // The two are read together at startup — the version decides which entries are new — so a
        // release whose number is in neither file is a release nobody is told about.
        $this->assertStringContainsString(
            '## ' . Version::current(),
            (string) file_get_contents(dirname(__DIR__, 3) . '/CHANGELOG.md'),
        );
    }
}
