<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Packages;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Packages\PathMetadata;
use Pig\CodingAgent\Packages\ResolvedPaths;
use Pig\CodingAgent\Packages\ResolvedResource;

final class ResolvedPathsTest extends TestCase
{
    private static function resource(string $path, string $source, string $origin = 'package'): ResolvedResource
    {
        return new ResolvedResource($path, true, new PathMetadata($source, 'user', $origin));
    }

    /**
     * `pig web`'s custom session: an unticked extension of a package leaves the whole package out,
     * its skills, prompts and themes too; other packages and top-level resources stay.
     */
    public function testAPackageLeftOutByItsExtensionTakesEverythingItBroughtWithIt(): void
    {
        $resolved = new ResolvedPaths(
            [self::resource('/p/pig-quant/index.php', 'git:pig-quant'), self::resource('/p/pig-android-use/index.php', 'git:pig-android-use')],
            [self::resource('/p/pig-quant/skills/quant/SKILL.md', 'git:pig-quant'), self::resource('/p/pig-android-use/skills/messaging/SKILL.md', 'git:pig-android-use'), self::resource('/home/skills/apkx/SKILL.md', 'auto', 'top-level')],
            [self::resource('/p/pig-quant/prompts/trade.md', 'git:pig-quant')],
            [self::resource('/p/pig-quant/themes/q.json', 'git:pig-quant')],
        );

        $without = $resolved->withoutPackagesOf(['pig-quant', 'not-a-package']);

        $this->assertSame(['/p/pig-android-use/index.php'], $without->enabled('extensions'));
        $this->assertSame(['/p/pig-android-use/skills/messaging/SKILL.md', '/home/skills/apkx/SKILL.md'], $without->enabled('skills'));
        $this->assertSame([], $without->enabled('prompts'));
        $this->assertSame([], $without->enabled('themes'));
        $this->assertSame($resolved, $resolved->withoutPackagesOf([]));
    }
}
