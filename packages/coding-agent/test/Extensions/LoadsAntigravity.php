<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

/**
 * The Antigravity extension's classes, for a test of them.
 *
 * They live under `extensions/pig-antigravity/src` and are not autoloaded — an extension is
 * `require`d by `ExtensionLoader`, not by Composer — so a test that uses one directly loads it
 * the way the extension's own entry does: by class, guarded, so a second test file does not
 * redeclare what the first loaded.
 */
trait LoadsAntigravity
{
    private static function loadAntigravity(): void
    {
        $src = dirname(__DIR__, 4) . '/extensions/pig-antigravity/src';

        foreach (['Routing', 'Models', 'AntigravityApi', 'AntigravityOauth', 'LazyAntigravityOauth', 'Accounts', 'Catalog', 'Grouping', 'Discovery', 'QuotaClient', 'ImageGenerator', 'GeneratedImageResult'] as $class) {
            if (!class_exists("PigAntigravity\\{$class}", false)) {
                require $src . "/{$class}.php";
            }
        }
    }

    /**
     * What `index.php` does on load, minus the sign-in and the catalogue: the fallback models on
     * the registry under `Api::Extension`, with this protocol behind them. For a test of the
     * provider that does not go through `ExtensionLoader`.
     */
    private static function registerAntigravity(?\PigAntigravity\AntigravityApi $api = null): void
    {
        self::loadAntigravity();
        \Pig\Ai\Extension\ProviderRegistry::register(new \Pig\Ai\Extension\Provider(
            id: \PigAntigravity\Models::PROVIDER,
            name: 'Antigravity (Gemini 3, Claude, GPT-OSS)',
            models: \PigAntigravity\Models::fallback(),
            api: $api ?? new \PigAntigravity\AntigravityApi(),
            resold: true,
        ));
    }
}
