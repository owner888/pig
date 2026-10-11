<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\TerminalColors;

/**
 * Isolation for the theme module's tests: `Themes` keeps upstream's module state in statics, and reads
 * custom themes from pig's agent directory, so each test gets an empty temporary one and a reset module.
 */
trait ThemeTestEnvironment
{
    private string $themeTestRoot;

    /** @var array<string, string|false> */
    private array $themeTestPreviousEnv = [];

    private function setUpThemeEnvironment(): void
    {
        Loop::reset();
        $this->themeTestRoot = sys_get_temp_dir() . '/pig-theme-test-' . bin2hex(random_bytes(6));
        mkdir($this->themeTestRoot . '/pig/themes', 0o777, true);
        mkdir($this->themeTestRoot . '/agent/themes', 0o777, true);
        $this->setThemeTestEnv('PIG_CODING_AGENT_DIR', $this->themeTestRoot . '/pig');
        $this->setThemeTestEnv('PI_CODING_AGENT_DIR', $this->themeTestRoot . '/agent');
        $this->setThemeTestEnv('COLORFGBG', null);
        self::resetThemeModule();
    }

    private function tearDownThemeEnvironment(): void
    {
        self::resetThemeModule();
        Themes::initTheme('dark');
        foreach ($this->themeTestPreviousEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        $this->themeTestPreviousEnv = [];
        self::removeThemeTestDir($this->themeTestRoot);
        Loop::reset();
    }

    private function setThemeTestEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->themeTestPreviousEnv)) {
            $this->themeTestPreviousEnv[$name] = getenv($name);
        }
        putenv($value === null ? $name : "{$name}={$value}");
    }

    /** `$this->themeTestRoot/pig/themes`, upstream's `getCustomThemesDir()` under `PIG_CODING_AGENT_DIR`. */
    private function customThemesDir(): string
    {
        return $this->themeTestRoot . '/pig/themes';
    }

    /** @return array<string, mixed> one of the builtin theme files, decoded */
    private static function builtinThemeJson(string $name): array
    {
        $json = json_decode((string) file_get_contents(Themes::getThemesDir() . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    private static function resetThemeModule(): void
    {
        Themes::stopThemeWatcher();
        Themes::setTerminalColors(new TerminalColors());
        Themes::setTerminalColorScheme(null);
        Themes::setRegisteredThemes([]);
        Themes::setThemeJsonValidator(null);
        Themes::setResolvedThemeFiles([]);
        Themes::setCliThemePaths([]);
        Themes::useThemeDiscovery(true);
        Themes::onThemeChange(null);
        TerminalImage::setCapabilityOverrides([]);
        TerminalImage::resetCapabilitiesCache();
    }

    private static function removeThemeTestDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
