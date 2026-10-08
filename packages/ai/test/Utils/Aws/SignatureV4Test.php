<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils\Aws;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Aws\Credentials;
use Pig\Ai\Utils\Aws\SignatureV4;

/**
 * `SignatureV4` against AWS's own SigV4 test suite.
 *
 * The vectors in `fixtures/sigv4` are `aws-c-auth`'s `tests/aws-signing-test-suite/v4`
 * (commit 8915397a, Apache-2.0, its `LICENSE` beside them): for each case the request, the signing
 * context, and the canonical request and signature AWS publishes for it.
 *
 * **The bar is `@smithy/signature-v4`, not the suite.** The signer pig emulates is the one
 * `@aws-sdk/client-bedrock-runtime` uses, and smithy itself parts from the suite on three cases —
 * run against the same files, it signs a folded header, an unnormalised path with a space in it and
 * a session token the suite asks to leave out differently from what the suite publishes. Those three
 * are pinned to smithy's signature (measured with `@smithy/signature-v4` from upstream's lockfile),
 * so a change towards the suite there is a change away from what upstream sends.
 */
final class SignatureV4Test extends TestCase
{
    /** smithy's signatures where it parts from the suite. */
    private const array SMITHY = [
        // smithy folds a continuation line into the header with a comma, as Node's parser hands it.
        'get-header-value-multiline' => 'ba17b383a53190154eb5fa66a1b836cc297cc0a3d70a5d00705980573d8ff790',
        // `uriEscapePath: false` is the path as given, space and all.
        'get-space-unnormalized' => '50a81b210beaf02d17a4b7bc5eaf06b8c261772289002b50ab0bc3ddd5c8ea93',
        // smithy has no `omit_session_token`: the token is signed.
        'post-sts-header-after' => '85d96828115b5dc0cfc3bd16ad9e210dd772bbebba041836c64533a82be05ead',
    ];

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        foreach (scandir(self::dir()) ?: [] as $name) {
            if (is_dir(self::dir() . '/' . $name) && $name[0] !== '.') {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('cases')]
    public function testTheSignatureIsTheOneAwsPublishesOrSmithys(string $name): void
    {
        [$context, $method, $path, $query, $headers, $body] = self::load($name);
        $credentials = new Credentials(
            $context['credentials']['access_key_id'],
            $context['credentials']['secret_access_key'],
            $context['credentials']['token'] ?? null,
        );

        $signed = SignatureV4::sign(
            $method,
            $path,
            $query,
            $headers,
            $body === '' ? null : $body,
            $credentials,
            $context['region'],
            $context['service'],
            (int) (strtotime($context['timestamp']) * 1000),
            applyChecksum: $context['sign_body'],
            uriEscapePath: $context['normalize'],
        );

        self::assertSame(1, preg_match('/Signature=([0-9a-f]{64})$/', $signed['authorization'], $matches));
        $expected = self::SMITHY[$name] ?? trim((string) file_get_contents(self::dir() . "/{$name}/header-signature.txt"));

        self::assertSame($expected, $matches[1]);
    }

    #[DataProvider('cases')]
    public function testTheCanonicalRequestIsTheOneAwsPublishes(string $name): void
    {
        if (isset(self::SMITHY[$name])) {
            // Where smithy signs something else, the canonical request is something else too.
            $this->expectNotToPerformAssertions();

            return;
        }

        [$context, $method, $path, $query, $headers, $body] = self::load($name);
        $headers['X-Amz-Date'] = gmdate('Ymd\THis\Z', (int) strtotime($context['timestamp']));

        if (isset($context['credentials']['token'])) {
            $headers['X-Amz-Security-Token'] = $context['credentials']['token'];
        }

        $hash = hash('sha256', $body);

        if ($context['sign_body']) {
            $headers['X-Amz-Content-Sha256'] = $hash;
        }

        $canonical = SignatureV4::canonicalRequest($method, $path, $query, SignatureV4::canonicalHeaders($headers), $hash, $context['normalize']);

        self::assertSame(
            rtrim((string) file_get_contents(self::dir() . "/{$name}/header-canonical-request.txt"), "\n"),
            $canonical,
        );
    }

    public function testTheSigningKeyIsAwsDocumentedExample(): void
    {
        // AWS's "Examples of how to derive a signing key for Signature Version 4" (`kSigning`).
        self::assertSame(
            'f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d',
            bin2hex(SignatureV4::signingKey('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20120215', 'us-east-1', 'iam')),
        );
    }

    public function testAnAuthorizationAlreadyThereIsReplacedAndTheChecksumAdded(): void
    {
        $signed = SignatureV4::sign(
            'POST',
            '/model/a%3Ab/converse-stream',
            [],
            ['host' => 'bedrock-runtime.us-east-1.amazonaws.com', 'Authorization' => 'stale', 'user-agent' => 'x'],
            '{}',
            new Credentials('AKID', 'SECRET', 'TOKEN'),
            'us-east-1',
            'bedrock',
            1_440_938_160_000,
        );

        self::assertArrayNotHasKey('Authorization', $signed);
        self::assertSame(hash('sha256', '{}'), $signed['x-amz-content-sha256']);
        self::assertSame('TOKEN', $signed['x-amz-security-token']);
        self::assertSame('20150830T123600Z', $signed['x-amz-date']);
        // `user-agent` is one of smithy's unsignable headers.
        self::assertStringContainsString('SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token,', $signed['authorization']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/20150830/us-east-1/bedrock/aws4_request, ', $signed['authorization']);
    }

    private static function dir(): string
    {
        return __DIR__ . '/../../fixtures/sigv4';
    }

    /**
     * A case's request, read the way Node's HTTP parser and the checker that measured smithy read it:
     * a continuation line joins the header before it with a comma, a repeated header joins with a
     * comma, and the query is decoded into keys and values.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string, 3: array<string, string|list<string>|null>, 4: array<string, string>, 5: string}
     */
    private static function load(string $name): array
    {
        $context = json_decode((string) file_get_contents(self::dir() . "/{$name}/context.json"), true, flags: JSON_THROW_ON_ERROR);
        $raw = (string) file_get_contents(self::dir() . "/{$name}/request.txt");
        $parts = explode("\n\n", $raw);
        $head = array_shift($parts);
        $body = implode("\n\n", $parts);
        $lines = explode("\n", $head);
        self::assertSame(1, preg_match('/^(\S+) (.*) HTTP\/1\.1$/', array_shift($lines), $request));
        [, $method, $target] = $request;

        $headers = [];
        $last = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if ($last !== null && preg_match('/^\s/', $line) === 1) {
                $headers[$last] .= ',' . trim($line);

                continue;
            }

            $colon = (int) strpos($line, ':');
            $key = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            $headers[$key] = isset($headers[$key]) ? $headers[$key] . ',' . $value : $value;
            $last = $key;
        }

        $mark = strpos($target, '?');
        $path = $mark === false ? $target : substr($target, 0, $mark);
        $query = [];

        if ($mark !== false) {
            foreach (explode('&', substr($target, $mark + 1)) as $pair) {
                if ($pair === '') {
                    continue;
                }

                $equals = strpos($pair, '=');
                $key = rawurldecode($equals === false ? $pair : substr($pair, 0, $equals));
                $value = $equals === false ? null : rawurldecode(substr($pair, $equals + 1));

                if (!array_key_exists($key, $query)) {
                    $query[$key] = $value;
                } else {
                    $query[$key] = [...(array) $query[$key], $value];
                }
            }
        }

        return [$context, $method, $path, $query, $headers, $body];
    }
}
