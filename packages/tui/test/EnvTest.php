<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Clipboard\SystemClipboard;
use Pig\Tui\Env;

/**
 * The environment, read the way JavaScript reads it.
 *
 * `TerminalImageTest` covers the four readers this rule was found in; what is here is the rule
 * itself, `home()`, and the four that were still reading it the wrong way.
 */
final class EnvTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    /** @param list<string> $names */
    private function clear(array $names): void
    {
        foreach ($names as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv("{$name}={$value}");
        }
    }

    public function testAVariableWithAValueIsSet(): void
    {
        $this->clear(['PIG_TEST_PRESENCE']);
        putenv('PIG_TEST_PRESENCE=1');

        $this->assertTrue(Env::isSet('PIG_TEST_PRESENCE'));
    }

    public function testAVariableExportedWithoutAValueIsNotSet(): void
    {
        $this->clear(['PIG_TEST_PRESENCE']);
        putenv('PIG_TEST_PRESENCE=');

        // `getenv()` answers '' here and `false` for absent, so the obvious `!== false` calls this
        // present; upstream reads it through JavaScript's truthiness, where '' is falsy. A bare
        // `export`, a `docker run -e NAME`, and an ssh or tmux environment forwarding a name
        // without a value all arrive this way.
        $this->assertFalse(Env::isSet('PIG_TEST_PRESENCE'));
    }

    public function testAnAbsentVariableIsNotSet(): void
    {
        $this->clear(['PIG_TEST_PRESENCE']);

        $this->assertFalse(Env::isSet('PIG_TEST_PRESENCE'));
    }

    public function testTheHomeDirectoryComesBackWithoutItsTrailingSlash(): void
    {
        $this->clear(['HOME']);
        putenv('HOME=/Users/dev/');

        // Or `~/notes` resolves to `/Users/dev//notes`, and a footer asking whether a path starts
        // with the home directory compares against a prefix that is one character too long.
        $this->assertSame('/Users/dev', Env::home());
    }

    public function testNoHomeIsNullRatherThanAGuess(): void
    {
        $this->clear(['HOME']);

        // Null, so each caller answers for itself: `Paths::expand()` leaves a `~` alone and
        // `CodingAgent\Config` falls back to the temp directory because it has to write
        // somewhere. A `sys_get_temp_dir()` in here would resolve `~/notes` into `/tmp`, which
        // is the silent fallback two hook loaders were caught doing.
        $this->assertNull(Env::home());
    }

    public function testAnEmptyHomeIsNoHome(): void
    {
        $this->clear(['HOME']);
        putenv('HOME=');

        $this->assertNull(Env::home());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function clipboardVariables(): array
    {
        return [
            'wayland' => ['WAYLAND_DISPLAY', 'isWayland'],
            'WSL by its distro name' => ['WSL_DISTRO_NAME', 'isWsl'],
            'WSL by its interop socket' => ['WSL_INTEROP', 'isWsl'],
        ];
    }

    /**
     * Three of the four that were still reading `!== false`, through the method that reads each.
     *
     * Milder than the picture one and still worth one answer: an empty `WAYLAND_DISPLAY` chose
     * `wl-copy` on a machine running X11, `Process::capture()` answered null for it, and what the
     * person got was no clipboard with nothing on screen about it.
     *
     * The fourth — `linuxWrite()`'s own command order — has no seam that does not involve running
     * `wl-copy` for real, so it is covered by `Env::isSet()` above rather than through its caller.
     */
    #[DataProvider('clipboardVariables')]
    public function testAnEmptyClipboardVariableIsNotThatPlatform(string $name, string $method): void
    {
        $this->clear(['WAYLAND_DISPLAY', 'XDG_SESSION_TYPE', 'WSL_DISTRO_NAME', 'WSL_INTEROP']);

        $answer = new \ReflectionMethod(SystemClipboard::class, $method);

        $this->assertFalse($answer->invoke(null), 'nothing set at all');

        putenv("{$name}=");
        $this->assertFalse($answer->invoke(null), 'exported without a value');

        putenv("{$name}=something");
        $this->assertTrue($answer->invoke(null), 'exported with a value');
    }

    public function testTermuxWithAnEmptyVersionIsNotTermux(): void
    {
        $this->clear(['TERMUX_VERSION']);
        putenv('TERMUX_VERSION=');

        // Termux has a clipboard and no image on it, so this decides whether `image()` answers
        // null before asking anything. Told an empty variable is presence, a Linux desktop with
        // the name forwarded into it could never paste a picture.
        $this->assertFalse(Env::isSet('TERMUX_VERSION'));
    }
}
