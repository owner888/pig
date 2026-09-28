<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Version;

/**
 * `install.sh` states things the manifest already states, and this is what keeps them in step.
 *
 * The installer checks PHP's version and extensions **before** Composer is asked to install
 * anything, which is the point of it — a missing `ext-pcntl` otherwise surfaces half way through as
 * a platform error that names no fix. But a preflight can only check what it was told to check, so
 * the list lives in two files, and the failure mode is the quiet one: an extension added to
 * `composer.json` and not to the installer leaves a check that passes and an install that then
 * fails, which is worse than having had no check.
 *
 * So the second copy is allowed and is pinned here. **A shell script cannot read `composer.json`
 * before PHP is known to exist**, which is exactly the situation the preflight is for, so deriving
 * it at run time is not available.
 */
final class InstallScriptTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/install.sh');
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testTheExtensionsItChecksForAreTheOnesTheManifestRequires(): void
    {
        $required = [];

        foreach (array_keys(self::manifest()['require'] ?? []) as $name) {
            if (str_starts_with((string) $name, 'ext-')) {
                $required[] = substr((string) $name, 4);
            }
        }

        sort($required);

        $this->assertNotSame([], $required, 'the manifest declares extensions at all');

        $this->assertSame(1, preg_match('/^PIG_EXTENSIONS="([^"]*)"$/m', self::script(), $found));

        $checked = explode(' ', $found[1]);
        sort($checked);

        $this->assertSame($required, $checked, 'install.sh checks for exactly what composer.json requires');
    }

    public function testTheMinimumPhpItChecksForIsTheOneTheManifestRequires(): void
    {
        $declared = (string) (self::manifest()['require']['php'] ?? '');

        $this->assertSame(1, preg_match('/^>=(\d+)\.(\d+)$/', $declared, $parts), "require.php is '{$declared}'");

        $script = self::script();

        $this->assertSame(1, preg_match('/^PIG_MIN_PHP_ID=(\d+)$/m', $script, $id));
        $this->assertSame(1, preg_match('/^PIG_MIN_PHP="([^"]*)"$/m', $script, $pretty));

        // `PHP_VERSION_ID` is major * 10000 + minor * 100 + patch, which is what the script compares
        // against — a number nobody should be deriving by hand twice.
        $this->assertSame(
            (int) $parts[1] * 10_000 + (int) $parts[2] * 100,
            (int) $id[1],
            'the version id the script compares against',
        );
        $this->assertSame("{$parts[1]}.{$parts[2]}", $pretty[1], 'and the one it prints');
    }

    public function testItInstallsThePackageThisRepositoryPublishes(): void
    {
        $this->assertSame(1, preg_match('/^PIG_PACKAGE="\$\{PIG_PACKAGE:-([^}]*)\}"$/m', self::script(), $found));
        $this->assertSame(Version::PACKAGE, $found[1]);
        $this->assertSame(Version::PACKAGE, self::manifest()['name'] ?? null);
    }

    public function testNothingRunsUntilTheLastLine(): void
    {
        // **The whole reason the script is a pile of functions.** Piped to `sh`, a download cut off
        // half way still executes whatever it received — so if anything ran before the end, a
        // truncated installer would perform half an install. With the only call on the last line,
        // a truncated one defines some functions and exits having done nothing.
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", self::script())),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));

        $this->assertSame('pig_installer_main "$@"', end($lines));
        $this->assertSame(1, substr_count(self::script(), "\npig_installer_main \"\$@\""));
    }
}
