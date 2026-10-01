<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;

final class WebSearchExtensionTest extends TestCase
{
    public function testExtensionLoadsAndRegistersToolsAndCommands(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-web-search/index.php';
        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath]);
        $this->assertSame([], $errors);

        $searchExt = null;
        foreach ($loaded as $ext) {
            if ($ext->name === 'pig-web-search') {
                $searchExt = $ext;
                break;
            }
        }

        $this->assertNotNull($searchExt);
        $this->assertSame('pig-web-search', $searchExt->name);

        // Check registered custom tools
        $toolNames = array_map(static fn ($t) => $t->name, $searchExt->api->tools());
        $this->assertContains('web_search', $toolNames);
        $this->assertContains('fetch_web_page', $toolNames);

        // Check registered command
        $this->assertArrayHasKey('search', $searchExt->api->commands());
    }
}
