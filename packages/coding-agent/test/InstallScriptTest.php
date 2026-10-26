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

    public function testPigStartedAtTheEndReadsTheTerminalAndNotThePipe(): void
    {
        // Piped to `sh`, standard input is the rest of the script. pig started on it is in print
        // mode (stdin not a terminal) with nothing to say, exits 0 at once, and answering "Start pig
        // now?" with Enter looked like nothing happened.
        $this->assertSame(1, preg_match('/^\s*exec "\$start_pig_path"(.*)$/m', self::script(), $found));
        $this->assertStringContainsString('</dev/tty', $found[1]);
        $this->assertStringContainsString('>/dev/tty', $found[1]);
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

    // ---- pictures ------------------------------------------------------------------------------

    public function testWithoutGdImageMagickIsInstalledWithThisSystemsCommand(): void
    {
        // Termux first: its PHP has no GD, and `pkg` needs no root.
        $this->assertSame('pkg install -y imagemagick', $this->call('imagemagick_install_command', ['TERMUX_VERSION' => '0.118']));

        // As root on a Debian-like system (`fakeBin()` answers `id -u` with 0): no sudo in front.
        $bin = $this->fakeBin(['apt-get']);
        $this->assertSame('apt-get update && apt-get install -y imagemagick', $this->call('imagemagick_install_command', ['PATH' => $bin], $bin));

        $bin = $this->fakeBin(['pacman']);
        $this->assertSame('pacman -S --needed --noconfirm imagemagick', $this->call('imagemagick_install_command', ['PATH' => $bin], $bin));
    }

    public function testNothingIsInstalledWhenThisPhpHasGd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('this PHP has no GD');
        }

        // `pkg` would be the command; it is never reached.
        $this->assertSame('', $this->call('ensure_image_resizer', ['TERMUX_VERSION' => '0.118']));
    }

    public function testOnTermuxItIsPhpGdThatIsInstalled(): void
    {
        // A `php` without GD until `pkg` has installed `php-gd`, and a `pkg` that says what it was asked.
        $bin = $this->fakeBin(['pkg', 'php']);
        file_put_contents("{$bin}/pkg", "#!/bin/sh\necho \"pkg \$*\"\n: > {$bin}/gd-installed\n");
        file_put_contents("{$bin}/php", "#!/bin/sh\n[ -f {$bin}/gd-installed ]\n");

        $out = $this->call('ensure_image_resizer', ['PATH' => $bin, 'TERMUX_VERSION' => '0.118'], $bin);

        $this->assertStringContainsString('pkg install -y php-gd', $out);
        $this->assertStringNotContainsString('imagemagick', $out, 'GD came with it, so nothing else is installed');
    }

    /** What one of the script's functions prints, with everything but the last line defined. */
    private function call(string $function, array $env, ?string $cleanUp = null): string
    {
        $script = (string) preg_replace('/^pig_installer_main "\$@"\s*$/m', '', self::script());
        $file = tempnam(sys_get_temp_dir(), 'pig-install-');
        file_put_contents($file, $script . "\n{$function}\n");

        try {
            $process = proc_open(['/bin/sh', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(), ...$env]);
            $out = (string) stream_get_contents($pipes[1]);
            proc_close($process);
        } finally {
            unlink($file);

            if ($cleanUp !== null) {
                array_map('unlink', glob("{$cleanUp}/*") ?: []);
                rmdir($cleanUp);
            }
        }

        return trim($out);
    }

    /** @param list<string> $names a directory of commands that exist and do nothing */
    private function fakeBin(array $names): string
    {
        $dir = sys_get_temp_dir() . '/pig-fake-bin-' . bin2hex(random_bytes(4));
        mkdir($dir);

        foreach ([...$names, 'uname', 'id'] as $name) {
            $body = match ($name) {
                'uname' => 'echo Linux',
                'id' => 'echo 0',
                default => 'exit 0',
            };
            file_put_contents("{$dir}/{$name}", "#!/bin/sh\n{$body}\n");
            chmod("{$dir}/{$name}", 0o755);
        }

        return $dir;
    }
}
