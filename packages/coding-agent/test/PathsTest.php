<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Tools\Paths;

final class PathsTest extends TestCase
{
    public function testRelativeShowsPathInsideCwdWithoutThePrefix(): void
    {
        $cwd = '/home/user/project';
        $path = '/home/user/project/src/main.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('src/main.php', $result);
    }

    public function testRelativeShowsNestedPathInsideCwd(): void
    {
        $cwd = '/home/user/project';
        $path = '/home/user/project/src/deeply/nested/main.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('src/deeply/nested/main.php', $result);
    }

    public function testRelativeReturnsPathUnchangedWhenItIsNotInCwd(): void
    {
        $cwd = '/home/user/project';
        $path = '/home/other/file.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('/home/other/file.php', $result);
    }

    public function testRelativeReturnsPathUnchangedWhenItIsAParentOfCwd(): void
    {
        $cwd = '/home/user/project';
        $path = '/home/user';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('/home/user', $result);
    }

    public function testRelativeHandlesCwdWithTrailingSlash(): void
    {
        $cwd = '/home/user/project/';
        $path = '/home/user/project/src/main.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('src/main.php', $result);
    }

    public function testRelativeDoesNotMatchPartialPathSegments(): void
    {
        // /home/user/project-old is NOT a subpath of /home/user/project
        $cwd = '/home/user/project';
        $path = '/home/user/project-old/src/main.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('/home/user/project-old/src/main.php', $result);
    }

    public function testRelativeWorksWithDirectPathsInCwd(): void
    {
        $cwd = '/home/user/project';
        $path = '/home/user/project';

        $result = Paths::relative($path, $cwd);

        // When path equals cwd exactly (without trailing slash), it doesn't match the prefix
        // check because the prefix includes a trailing slash: "/home/user/project/"
        $this->assertSame('/home/user/project', $result);
    }

    public function testRelativeWorksWithCwdHavingMultipleTrailingSlashes(): void
    {
        $cwd = '/home/user/project///';
        $path = '/home/user/project/src/main.php';

        $result = Paths::relative($path, $cwd);

        // rtrim removes all trailing slashes, then adds one
        $this->assertSame('src/main.php', $result);
    }

    public function testRelativeWithFileAtRootCwd(): void
    {
        $cwd = '/';
        $path = '/home/user/file.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('home/user/file.php', $result);
    }

    public function testRelativeWithAbsolutePathArgument(): void
    {
        $cwd = '/var/www/app';
        $path = '/var/www/app/config.php';

        $result = Paths::relative($path, $cwd);

        $this->assertSame('config.php', $result);
    }
}
