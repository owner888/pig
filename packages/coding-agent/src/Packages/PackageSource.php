<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * What a `packages` entry's source string means — upstream's `parseSource()` and `parseGitUrl()`.
 *
 * Two kinds here where upstream has three: `npm:` has no PHP counterpart, and `composer:` is
 * reserved for the day it does — both are refused by name rather than read as a local path,
 * because a path called `npm:foo` that does not exist is a worse answer than "not supported".
 *
 * Git is recognised the way upstream recognises it:
 * - with a `git:` prefix, every shorthand: `git:github.com/user/repo@v1`, `git:github:user/repo`,
 *   `git:git@github.com:user/repo.git`, `git:https://…`;
 * - without one, only an explicit URL: `https://`, `http://`, `ssh://`, `git://`, or `git@host:`.
 * A ref follows the path after `@` (or `#`, as the hosted-git shorthand writes it). Anything
 * else is a local path.
 *
 * `hosted-git-info` is not here; the shorthand table below is the part of it a source string
 * can use, and the generic parser covers every real URL.
 */
final class PackageSource
{
    /** The `github:user/repo` style upstream's `hosted-git-info` reads. */
    private const array SHORTHAND_HOSTS = [
        'github' => 'github.com',
        'gitlab' => 'gitlab.com',
        'bitbucket' => 'bitbucket.org',
        'gist' => 'gist.github.com',
        'sourcehut' => 'git.sr.ht',
    ];

    /** Prefixes that are not a local path, whatever the file system says — upstream's `isLocalPath()`. */
    private const array NOT_LOCAL = ['npm:', 'composer:', 'git:', 'github:', 'gitlab:', 'bitbucket:', 'http:', 'https:', 'ssh:', 'builtin:'];

    /** @throws PackageError for a kind pig does not have */
    public static function parse(string $source): GitSource|LocalSource
    {
        $trimmed = trim($source);

        foreach (['npm:', 'composer:'] as $registry) {
            if (str_starts_with($trimmed, $registry)) {
                throw new PackageError(sprintf(
                    '%s sources are not supported; a package is a git repository (git:github.com/user/repo) or a local path.',
                    rtrim($registry, ':'),
                ));
            }
        }

        if (self::isLocalPath($trimmed)) {
            return new LocalSource($trimmed);
        }

        return self::parseGit($trimmed) ?? new LocalSource($trimmed);
    }

    /** Upstream's `isLocalPath()`: anything not carrying a known non-local prefix. */
    public static function isLocalPath(string $value): bool
    {
        $trimmed = trim($value);

        foreach (self::NOT_LOCAL as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return false;
            }
        }

        // `git@host:path` is a git URL with no scheme.
        return preg_match('/^git@[^:]+:/', $trimmed) !== 1;
    }

    /** Upstream's `parseGitUrl()`: null when the string is not a git source. */
    public static function parseGit(string $source): ?GitSource
    {
        $trimmed = trim($source);
        $hasPrefix = str_starts_with($trimmed, 'git:');
        $url = $hasPrefix ? trim(substr($trimmed, 4)) : $trimmed;

        if (!$hasPrefix && preg_match('#^(https?|ssh|git)://#i', $url) !== 1 && preg_match('/^git@[^:]+:/', $url) !== 1) {
            return null;
        }

        // `github:user/repo@ref` and its siblings.
        foreach (self::SHORTHAND_HOSTS as $shorthand => $host) {
            if (str_starts_with($url, "{$shorthand}:")) {
                [$path, $ref] = self::splitRef(substr($url, strlen($shorthand) + 1));

                return self::build("https://{$host}/{$path}", $host, $path, $ref);
            }
        }

        [$repo, $ref] = self::splitRef($url);

        if (preg_match('/^git@([^:]+):(.+)$/', $repo, $scp) === 1) {
            return self::build($repo, $scp[1], $scp[2], $ref);
        }

        if (preg_match('#^(https?|ssh|git)://#i', $repo) === 1) {
            $parts = parse_url($repo);

            if ($parts === false || !isset($parts['host'])) {
                return null;
            }

            return self::build($repo, $parts['host'], ltrim($parts['path'] ?? '', '/'), $ref);
        }

        // `host/user/repo`: a host is something with a dot in it, or localhost.
        $slash = strpos($repo, '/');

        if ($slash === false) {
            return null;
        }

        $host = substr($repo, 0, $slash);
        $path = substr($repo, $slash + 1);

        if (!str_contains($host, '.') && $host !== 'localhost') {
            return null;
        }

        return self::build("https://{$repo}", $host, $path, $ref);
    }

    /**
     * The ref off the end: `user/repo@v1` → `user/repo`, `v1`. Only after the path, so the `@` in
     * `git@github.com:` and in a URL's userinfo is left alone. `#` is the hosted-git spelling.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitRef(string $url): array
    {
        if (preg_match('/^git@([^:]+):(.+)$/', $url, $scp) === 1) {
            [$path, $ref] = self::splitRefOff($scp[2]);

            return ["git@{$scp[1]}:{$path}", $ref];
        }

        if (str_contains($url, '://')) {
            $parts = parse_url($url);

            if ($parts === false || !isset($parts['host'])) {
                return [$url, null];
            }

            [$path, $ref] = self::splitRefOff(ltrim($parts['path'] ?? '', '/') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : ''));

            if ($ref === null) {
                return [$url, null];
            }

            $rebuilt = $parts['scheme'] . '://'
                . (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '')
                . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . '/' . $path;

            return [rtrim($rebuilt, '/'), $ref];
        }

        $slash = strpos($url, '/');

        if ($slash === false) {
            return [$url, null];
        }

        [$path, $ref] = self::splitRefOff(substr($url, $slash + 1));

        return [substr($url, 0, $slash + 1) . $path, $ref];
    }

    /** @return array{0: string, 1: string|null} */
    private static function splitRefOff(string $pathWithRef): array
    {
        $at = strpos($pathWithRef, '@');
        $hash = strpos($pathWithRef, '#');
        $separator = $at === false ? $hash : ($hash === false ? $at : min($at, $hash));

        if ($separator === false) {
            return [$pathWithRef, null];
        }

        $path = substr($pathWithRef, 0, $separator);
        $ref = substr($pathWithRef, $separator + 1);

        return $path === '' || $ref === '' ? [$pathWithRef, null] : [$path, $ref];
    }

    /** Upstream's `buildGitSource()`: the path normalised and checked against escaping the install root. */
    private static function build(string $repo, string $host, string $path, ?string $ref): ?GitSource
    {
        if (str_starts_with($path, '/')) {
            return null;
        }

        $normalized = ltrim((string) preg_replace('/\.git$/', '', $path), '/');

        if ($host === '' || $normalized === '' || count(explode('/', $normalized)) < 2) {
            return null;
        }

        if (self::unsafeInstallPart($host, false) || self::unsafeInstallPart($normalized, true)) {
            return null;
        }

        return new GitSource($repo, $host, $normalized, $ref);
    }

    /** Upstream's `hasUnsafeGitInstallPart()`: nothing that could leave `<root>/<host>/<path>`. */
    private static function unsafeInstallPart(string $value, bool $allowSlash): bool
    {
        $decoded = rawurldecode($value);

        foreach ([$value, $decoded] as $candidate) {
            if (str_contains($candidate, "\0") || str_contains($candidate, '\\') || str_starts_with($candidate, '/')) {
                return true;
            }

            if (!$allowSlash && str_contains($candidate, '/')) {
                return true;
            }

            if (in_array('..', explode('/', $candidate), true)) {
                return true;
            }
        }

        return false;
    }
}
