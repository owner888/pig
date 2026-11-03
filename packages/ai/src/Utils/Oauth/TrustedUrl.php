<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

/**
 * Upstream's `auth/oauth/trusted-url.ts`.
 */
final class TrustedUrl
{
    /**
     * Upstream's `trustedHttpUrl()`: "The `http:` or `https:` URL `value` names, normalized, or null.
     * Login flows open server-supplied URLs with the platform URL handler, which would also launch a
     * `file:` URL or a custom protocol handler. Normalizing percent-encodes control characters, so the
     * URL can also be embedded in terminal hyperlinks."
     *
     * PHP has no WHATWG parser below 8.5, so this does the parts of `new URL(value).href` that the
     * sentence above depends on, in the parser's order: C0 controls and spaces are stripped off both
     * ends, every tab, newline and carriage return is removed, and every byte left after the host
     * that is a control, a space, DEL or not ASCII is percent-encoded — which is what the parser does
     * to a C0 control, a space and to each UTF-8 byte of a C1 control in a path, query or fragment.
     * A control, a space or a C1 control in the host is a parse failure there and null here.
     *
     * Stricter than upstream in one place: the scheme has to be followed by exactly `//` and a host.
     * WHATWG also accepts `https:host`, `https:\\host` and `https:///host`; no authorization server
     * sends any of them. Not done, because nothing here depends on it: the host is not lowercased and
     * a backslash is not turned into a slash — the browser does both when it opens the URL.
     */
    public static function trustedHttpUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $url = str_replace(["\t", "\n", "\r"], '', trim($value, " \x00..\x1f"));

        if (preg_match('~^(https?)://([^/\\\\?#]*)(.*)$~is', $url, $match) !== 1) {
            return null;
        }

        [, $scheme, $authority, $rest] = $match;

        // Asked before `parse_url()`, which hands a control inside a host back as an underscore.
        if (preg_match('/[\x00-\x20\x7f]|\xc2[\x80-\x9f]/', $authority) === 1) {
            return null;
        }

        $scheme = strtolower($scheme);
        $host = parse_url("{$scheme}://{$authority}", PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        // `new URL("https://host").href` is `https://host/`: a special URL always has a path.
        if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
            $rest = '/' . $rest;
        }

        $rest = preg_replace_callback(
            '/[\x00-\x20\x7f-\xff]/',
            static fn (array $byte): string => sprintf('%%%02X', ord($byte[0])),
            $rest,
        ) ?? throw new \RuntimeException('Could not percent-encode a URL: ' . preg_last_error_msg());

        return "{$scheme}://{$authority}{$rest}";
    }
}
