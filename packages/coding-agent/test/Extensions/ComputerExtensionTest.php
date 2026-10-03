<?php

declare(strict_types=1);

namespace Pig\Tests\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;

final class ComputerExtensionTest extends TestCase
{
    public function testComputerExtensionRegistersToolAndSlashCommand(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-computer/index.php';
        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath]);
        $this->assertSame([], $errors);

        $computerExt = null;
        foreach ($loaded as $ext) {
            if ($ext->name === 'pig-computer') {
                $computerExt = $ext;
                break;
            }
        }

        $this->assertNotNull($computerExt);
        $this->assertSame('pig-computer', $computerExt->name);

        // Tool registered
        $toolNames = array_map(static fn ($t) => $t->name, $computerExt->api->tools());
        $this->assertContains('computer', $toolNames);

        // Command registered
        $commands = $computerExt->api->commands();
        $this->assertArrayHasKey('computer', $commands);
    }
}
