<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\TerminalColors;

/**
 * For tests of components that draw in the global theme (`Themes::theme()`): a known builtin theme in
 * truecolor, whatever terminal the suite runs in, and the module put back afterwards. Tests of the theme
 * module itself use `ThemeTestEnvironment`, which also isolates the custom theme directories.
 */
trait GlobalThemeFixture
{
    private function setUpGlobalTheme(string $name = 'dark'): void
    {
        Themes::stopThemeWatcher();
        Themes::setTerminalColors(new TerminalColors());
        Themes::setTerminalColorScheme(null);
        Themes::setThemeJsonValidator(null);
        Themes::onThemeChange(null);
        TerminalImage::setCapabilityOverrides(['trueColor' => true]);
        $error = Themes::initTheme($name);
        if ($error !== null) {
            throw new \RuntimeException("The test theme '{$name}' did not load: {$error}");
        }
    }

    private function tearDownGlobalTheme(): void
    {
        Themes::stopThemeWatcher();
        Themes::setThemeJsonValidator(null);
        Themes::onThemeChange(null);
        Themes::setTerminalColors(new TerminalColors());
        Themes::setTerminalColorScheme(null);
        TerminalImage::setCapabilityOverrides([]);
        TerminalImage::resetCapabilitiesCache();
        Themes::initTheme('dark');
    }
}
