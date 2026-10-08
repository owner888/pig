<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;

/**
 * The web terminal's xterm.js is the official npm build, byte for byte: `@xterm/xterm` 6.0.0
 * (`lib/xterm.mjs`, `css/xterm.css`) and `@xterm/addon-fit` 0.11.0 (`lib/addon-fit.mjs`).
 *
 * What was here before was somebody's own re-bundle of 5.5 with FitAddon folded in (exports
 * minified to `D` and `o`), and its minifier broke `requestMode()`: the DECRQM answer assigned an
 * undeclared `i`, threw in strict mode, and vim — which asks for modes as it starts — never got
 * its first screen drawn. Neither official build has that code. So nothing in `vendor/` is edited
 * or rebuilt: an upgrade replaces the files from the package and updates the hashes here.
 */
final class VendoredXtermTest extends TestCase
{
    private const FILES = [
        'js/vendor/xterm.mjs' => 'b336ec65a086c056d4804b3d4c2347da5663d3f23c3f25be866467bd8857ad59',
        'js/vendor/addon-fit.mjs' => '2d87e1bddc73be9111de8beee5370c3bb7aac9c94e18e6f245f02ca741ef1769',
        'css/vendor/xterm.css' => '854a7c0fb70e8b1a083c16797ab827299fb18744f5ad34f227b48337e33293c6',
    ];

    public function testTheVendoredFilesAreTheOfficialBuilds(): void
    {
        foreach (self::FILES as $file => $sha256) {
            $this->assertSame($sha256, hash_file('sha256', self::assets() . $file), "{$file} is not the official build");
        }
    }

    public function testTheTerminalsImportTheOfficialModulesByTheirOwnNames(): void
    {
        foreach (['js/components/WebTerminal.js', 'js/components/NodeWorkbench.js'] as $component) {
            $source = (string) file_get_contents(self::assets() . $component);
            $this->assertStringContainsString('import { Terminal } from "../vendor/xterm.mjs";', $source, $component);
            $this->assertStringContainsString('import { FitAddon } from "../vendor/addon-fit.mjs";', $source, $component);
        }
    }

    private static function assets(): string
    {
        return dirname(__DIR__, 2) . '/src/Web/assets/';
    }
}
