<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\TrustedUrl;

/**
 * Upstream's `trustedHttpUrl()`. Every expected value is what `new URL(value).href` answers for the
 * same input in Node, or null where upstream answers null.
 */
final class TrustedUrlTest extends TestCase
{
    #[DataProvider('urls')]
    public function testAWebUrlComesBackNormalizedAndAnythingElseIsNull(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, TrustedUrl::trustedHttpUrl($value));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function urls(): array
    {
        return [
            'https' => ['https://github.com/login/device', 'https://github.com/login/device'],
            'http with a port' => ['http://127.0.0.1:1456/callback?a=b#c', 'http://127.0.0.1:1456/callback?a=b#c'],
            'an upper-case scheme' => ['HTTPS://example.com/x', 'https://example.com/x'],
            'no path' => ['https://example.com', 'https://example.com/'],
            'a query and no path' => ['https://example.com?a=b', 'https://example.com/?a=b'],
            // The case that was found: an OSC sequence riding on a verification URI. The last BEL is
            // a control at the end, which the parser strips rather than encodes.
            'a BEL and an OSC in the path' => ["https://auth.kimi.com/x\x07\x1b]0;pwned\x07", 'https://auth.kimi.com/x%07%1B]0;pwned'],
            'a DEL and a non-ASCII letter' => ["https://example.com/a\x7fb/\u{e9}", 'https://example.com/a%7Fb/%C3%A9'],
            'a C1 control in the query' => ["https://example.com/?a=\u{9b}31m", 'https://example.com/?a=%C2%9B31m'],
            'a space in the path' => ['https://example.com/a b', 'https://example.com/a%20b'],
            'controls around it' => ["\x00 https://example.com/x \x1f", 'https://example.com/x'],
            'a newline inside' => ["https://exa\nmple.com/x", 'https://example.com/x'],
            'a file URL' => ['file:///Users/victim/Downloads/payload.app', null],
            'a custom protocol' => ['vscode://extension/x', null],
            'javascript' => ['javascript:alert(1)', null],
            'not a URL' => ['x', null],
            'empty' => ['', null],
            // Node answers `https://x/` (extra slashes are skipped); refused here, see the docblock.
            'three slashes' => ['https:///x', null],
            'a control in the host' => ["https://exa\x01mple.com/", null],
            'a space in the host' => ['https://exa mple.com/', null],
            'not a string' => [42, null],
            'null' => [null, null],
        ];
    }
}
