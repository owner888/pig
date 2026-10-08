<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

/**
 * AWS Signature Version 4, header-signed — `@smithy/signature-v4`'s `SignatureV4::signRequest()`,
 * which is what `@aws-sdk/client-bedrock-runtime` signs every ConverseStream request with.
 *
 * Step for step as smithy does it:
 *
 * - `authorization`, `x-amz-date` and `date` already on the request are dropped first
 *   (`prepareRequest()`), then `x-amz-date` is set, and `x-amz-security-token` when the credentials
 *   carry a session token;
 * - the payload hash is an `x-amz-content-sha256` header already there, else SHA-256 of the body
 *   (of the empty string when there is none), and the header is **added** with it when
 *   `applyChecksum` is on — smithy's default, and why every Bedrock request carries one;
 * - every header is signed except smithy's `ALWAYS_UNSIGNABLE_HEADERS` (`user-agent`,
 *   `authorization`, `connection`, …) and `proxy-*` / `sec-*`; a value has CR/LF turned into a space,
 *   runs of spaces and tabs collapsed and one leading and one trailing space cut;
 * - the canonical path is the path normalised (empty and `.` segments dropped, `..` popping one) and
 *   then URI-escaped again — so an already-escaped `%3A` becomes `%253A` — unless `uriEscapePath` is
 *   off (S3's rule), when it is the path as given;
 * - the query is each key and value escaped with smithy's `escapeUri()` — `rawurlencode()`, the
 *   RFC 3986 unreserved set — sorted by key, a list's values sorted inside it, a null value left out.
 *
 * Checked against AWS's published SigV4 test suite (`aws-c-auth/tests/aws-signing-test-suite/v4`),
 * in `SignatureV4Test`.
 */
final class SignatureV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /** smithy's `ALWAYS_UNSIGNABLE_HEADERS`. */
    private const array UNSIGNABLE = [
        'authorization', 'cache-control', 'connection', 'expect', 'from', 'keep-alive', 'max-forwards',
        'pragma', 'referer', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'user-agent', 'x-amzn-trace-id',
    ];

    /** SHA-256 of nothing: smithy's hash for a request without a body. */
    private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @param array<string, string> $headers as they will be sent; names keep their case here and
     *        are compared without it
     * @param array<string, string|list<string>|null> $query decoded keys and values
     * @return array<string, string> the headers with `x-amz-date`, the session token, the payload
     *         hash and `authorization` added
     */
    public static function sign(
        string $method,
        string $path,
        array $query,
        array $headers,
        ?string $body,
        Credentials $credentials,
        string $region,
        string $service,
        int $nowMs,
        bool $applyChecksum = true,
        bool $uriEscapePath = true,
    ): array {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), ['authorization', 'x-amz-date', 'date'], true)) {
                unset($headers[$name]);
            }
        }

        $longDate = gmdate('Ymd\THis\Z', intdiv($nowMs, 1000));
        $shortDate = substr($longDate, 0, 8);
        $scope = "{$shortDate}/{$region}/{$service}/aws4_request";
        $headers['x-amz-date'] = $longDate;

        if ($credentials->sessionToken !== null && $credentials->sessionToken !== '') {
            $headers['x-amz-security-token'] = $credentials->sessionToken;
        }

        $payloadHash = self::payloadHash($headers, $body);

        if ($applyChecksum && self::header($headers, 'x-amz-content-sha256') === null) {
            $headers['x-amz-content-sha256'] = $payloadHash;
        }

        $canonicalHeaders = self::canonicalHeaders($headers);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonicalRequest = self::canonicalRequest($method, $path, $query, $canonicalHeaders, $payloadHash, $uriEscapePath);
        $stringToSign = self::ALGORITHM . "\n{$longDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($credentials->secretAccessKey, $shortDate, $region, $service));

        $headers['authorization'] = self::ALGORITHM . " Credential={$credentials->accessKeyId}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    /**
     * smithy's `createCanonicalRequest()`: method, path, query, the headers, a blank line, the signed
     * header list, the payload hash.
     *
     * @param array<string, string|list<string>|null> $query
     * @param array<string, string> $canonicalHeaders lowercased names, sorted
     */
    public static function canonicalRequest(
        string $method,
        string $path,
        array $query,
        array $canonicalHeaders,
        string $payloadHash,
        bool $uriEscapePath = true,
    ): string {
        $lines = [];

        foreach ($canonicalHeaders as $name => $value) {
            $lines[] = "{$name}:{$value}";
        }

        return $method . "\n"
            . self::canonicalPath($path, $uriEscapePath) . "\n"
            . self::canonicalQuery($query) . "\n"
            . implode("\n", $lines) . "\n\n"
            . implode(';', array_keys($canonicalHeaders)) . "\n"
            . $payloadHash;
    }

    /**
     * smithy's `getCanonicalHeaders()`.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function canonicalHeaders(array $headers): array
    {
        $canonical = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);

            if (in_array($lower, self::UNSIGNABLE, true) || str_starts_with($lower, 'proxy-') || str_starts_with($lower, 'sec-')) {
                continue;
            }

            $value = (string) preg_replace('/[\r\n]/', ' ', $value);
            $value = (string) preg_replace('/[ \t]+/', ' ', $value);
            $canonical[$lower] = (string) preg_replace('/^ | $/', '', $value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /** smithy's `getSigningKey()`: `AWS4<secret>`, then the date, the region, the service and `aws4_request`. */
    public static function signingKey(string $secretAccessKey, string $shortDate, string $region, string $service): string
    {
        $key = 'AWS4' . $secretAccessKey;

        foreach ([$shortDate, $region, $service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return $key;
    }

    /** smithy's `getCanonicalPath()`. */
    private static function canonicalPath(string $path, bool $uriEscapePath): string
    {
        if (!$uriEscapePath) {
            return $path;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        $normalized = (str_starts_with($path, '/') ? '/' : '')
            . implode('/', $segments)
            . ($segments !== [] && str_ends_with($path, '/') ? '/' : '');

        return str_replace('%2F', '/', rawurlencode($normalized));
    }

    /**
     * smithy's `getCanonicalQuery()`.
     *
     * @param array<string, string|list<string>|null> $query
     */
    private static function canonicalQuery(array $query): string
    {
        $serialized = [];

        foreach ($query as $key => $value) {
            if (strtolower((string) $key) === 'x-amz-signature') {
                continue;
            }

            $encodedKey = rawurlencode((string) $key);

            if (is_string($value)) {
                $serialized[$encodedKey] = "{$encodedKey}=" . rawurlencode($value);
            } elseif (is_array($value)) {
                $pairs = array_map(static fn (string $item): string => "{$encodedKey}=" . rawurlencode($item), $value);
                sort($pairs, SORT_STRING);
                $serialized[$encodedKey] = implode('&', $pairs);
            }
        }

        ksort($serialized, SORT_STRING);

        return implode('&', array_filter($serialized, static fn (string $pair): bool => $pair !== ''));
    }

    /** @param array<string, string> $headers */
    private static function payloadHash(array $headers, ?string $body): string
    {
        $given = self::header($headers, 'x-amz-content-sha256');

        if ($given !== null) {
            return $given;
        }

        return $body === null ? self::EMPTY_SHA256 : hash('sha256', $body);
    }

    /** @param array<string, string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return $value;
            }
        }

        return null;
    }
}
