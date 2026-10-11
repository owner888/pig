<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Export\SessionShare;

/** Upstream's `getShareViewerUrl()`: the override, or pigagent.dev's viewer, then `#<gist id>`. */
final class SessionShareTest extends TestCase
{
    public function testTheViewerIsPigagentDevUnlessTheEnvironmentNamesAnother(): void
    {
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', []));
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => '']));
        $this->assertSame('https://v.test/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => 'https://v.test/']));
    }
}
