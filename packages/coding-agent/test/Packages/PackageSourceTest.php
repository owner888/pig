<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Packages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Packages\GitSource;
use Pig\CodingAgent\Packages\LocalSource;
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageSource;

/**
 * Upstream's `git.test.ts` cases for `parseGitUrl()`, and `parseSource()`'s two kinds.
 */
final class PackageSourceTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string|null}> source, repo, host, path, ref */
    public static function gitSources(): iterable
    {
        yield 'git: shorthand' => ['git:github.com/user/repo', 'https://github.com/user/repo', 'github.com', 'user/repo', null];
        yield 'git: shorthand with ref' => ['git:github.com/user/repo@v1.2', 'https://github.com/user/repo', 'github.com', 'user/repo', 'v1.2'];
        yield 'git: shorthand with .git' => ['git:github.com/user/repo.git', 'https://github.com/user/repo.git', 'github.com', 'user/repo', null];
        yield 'git: https' => ['git:https://github.com/user/repo', 'https://github.com/user/repo', 'github.com', 'user/repo', null];
        yield 'git: https with ref' => ['git:https://gitlab.com/group/sub/repo@main', 'https://gitlab.com/group/sub/repo', 'gitlab.com', 'group/sub/repo', 'main'];
        yield 'git: scp-like' => ['git:git@github.com:user/repo.git', 'git@github.com:user/repo.git', 'github.com', 'user/repo', null];
        yield 'git: scp-like with ref' => ['git:git@github.com:user/repo@abc123', 'git@github.com:user/repo', 'github.com', 'user/repo', 'abc123'];
        yield 'git: github shorthand' => ['git:github:user/repo', 'https://github.com/user/repo', 'github.com', 'user/repo', null];
        yield 'git: github shorthand with #ref' => ['git:github:user/repo#v2', 'https://github.com/user/repo', 'github.com', 'user/repo', 'v2'];
        yield 'bare https' => ['https://github.com/user/repo', 'https://github.com/user/repo', 'github.com', 'user/repo', null];
        yield 'bare https with ref' => ['https://github.com/user/repo@v1', 'https://github.com/user/repo', 'github.com', 'user/repo', 'v1'];
        yield 'bare ssh' => ['ssh://git@github.com/user/repo', 'ssh://git@github.com/user/repo', 'github.com', 'user/repo', null];
        yield 'bare scp-like' => ['git@github.com:user/repo', 'git@github.com:user/repo', 'github.com', 'user/repo', null];
        yield 'localhost' => ['git:localhost/user/repo', 'https://localhost/user/repo', 'localhost', 'user/repo', null];
    }

    #[DataProvider('gitSources')]
    public function testAGitSourceIsReadAsUpstreamReadsIt(string $source, string $repo, string $host, string $path, ?string $ref): void
    {
        $parsed = PackageSource::parse($source);

        $this->assertInstanceOf(GitSource::class, $parsed);
        $this->assertSame($repo, $parsed->repo);
        $this->assertSame($host, $parsed->host);
        $this->assertSame($path, $parsed->path);
        $this->assertSame($ref, $parsed->ref);
        $this->assertSame($ref !== null, $parsed->pinned());
    }

    public function testAPathIsLocal(): void
    {
        foreach (['./tools', '../tools', '/abs/tools', 'tools', '~/pig-tools', 'extension.php'] as $path) {
            $this->assertInstanceOf(LocalSource::class, PackageSource::parse($path), $path);
        }
    }

    public function testAHostWithoutADotIsAPathWithoutThePrefix(): void
    {
        // Upstream: without `git:`, only an explicit URL is git. `user/repo` is a directory.
        $this->assertInstanceOf(LocalSource::class, PackageSource::parse('user/repo'));
        $this->assertNull(PackageSource::parseGit('git:user/repo'));
    }

    public function testAPathThatWouldLeaveTheInstallRootIsRefused(): void
    {
        $this->assertNull(PackageSource::parseGit('git:github.com/../etc'));
        $this->assertNull(PackageSource::parseGit('git:github.com/user/..'));
        $this->assertNull(PackageSource::parseGit('git:github.com/user/%2e%2e'));
        $this->assertNull(PackageSource::parseGit('git:github.com/user'), 'at least two segments');
    }

    public function testRegistrySourcesAreRefusedByName(): void
    {
        foreach (['npm:@example/pi-tools', 'composer:vendor/package'] as $source) {
            try {
                PackageSource::parse($source);
                $this->fail("{$source} was accepted");
            } catch (PackageError $error) {
                $this->assertStringContainsString('not supported', $error->getMessage());
            }
        }
    }

    public function testTwoRefsOfOneRepositoryShareHostAndPath(): void
    {
        // What `PackageManager::identity()` reads: the ref is not part of who the package is.
        $a = PackageSource::parse('git:github.com/user/repo@v1');
        $b = PackageSource::parse('git:git@github.com:user/repo.git@v2');

        $this->assertInstanceOf(GitSource::class, $a);
        $this->assertInstanceOf(GitSource::class, $b);
        $this->assertSame([$a->host, $a->path], [$b->host, $b->path]);
        $this->assertNotSame($a->repo, $b->repo, 'two spellings, one package');
    }
}
