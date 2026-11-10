<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\Agent\AgentTool;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Version;
use RuntimeException;

/**
 * The parts of `/share` that are not screen: where a shared gist is viewed, the trailing entry an
 * exported transcript carries for a viewer, and pig's own road — the HTML export encrypted here and
 * kept on pigagent.dev.
 *
 * **pigagent.dev holds only ciphertext.** The page is gzipped (when zlib is there) and sealed with
 * AES-256-GCM under a key made for this one share; the key goes in the link's fragment
 * (`https://pigagent.dev/s/<id>#<key>`), which a browser never sends to a server, and the viewer
 * page decrypts in the browser. Whoever has the link can read the session, and nobody else — the
 * server, its logs and its backups included. The server keeps it for 1, 7 or 30 days and deletes it
 * then, or sooner when the delete token it answered with (kept in `ShareRecords`) is sent back.
 *
 * Upstream's first road, a Radius artifact, is not here: pig uses no pi service. See "Sharing on
 * pigagent.dev" in `docs/dev/decisions.md`.
 */
final class SessionShare
{
    /**
     * Upstream's `DEFAULT_SHARE_VIEWER_URL`, pig's own: pigagent.dev's `/session/` page fetches
     * the gist in the browser and shows the HTML export in a sandboxed frame.
     */
    public const string DEFAULT_VIEWER_URL = 'https://pigagent.dev/session/';

    /** Where pig's own road keeps shares, unless `PIG_SHARE_SERVER` names another. */
    public const string SERVER = 'https://pigagent.dev';

    /** How long a share may be kept, in days, the default first. */
    public const array EXPIRY_DAYS = [30, 7, 1];

    /** The custom entry type of the trailing entry — upstream's `pi.share`, renamed. */
    public const string CUSTOM_TYPE = 'pig.share';

    private const string CIPHER = 'aes-256-gcm';

    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    /** Upstream's `getShareViewerUrl()`: `PIG_SHARE_VIEWER_URL` (or the default), then `#<gist id>`. */
    public static function viewerUrl(string $gistId, ?array $env = null): string
    {
        $base = $env === null ? getenv('PIG_SHARE_VIEWER_URL') : ($env['PIG_SHARE_VIEWER_URL'] ?? false);

        return (is_string($base) && $base !== '' ? $base : self::DEFAULT_VIEWER_URL) . '#' . $gistId;
    }

    /**
     * Where shares are kept: `PIG_SHARE_SERVER` (a self-hosted copy of pigagent.dev's share API, or
     * a test's), else pigagent.dev. No trailing slash.
     *
     * @param array<string, string>|null $env
     */
    public static function server(?array $env = null): string
    {
        $base = $env === null ? getenv('PIG_SHARE_SERVER') : ($env['PIG_SHARE_SERVER'] ?? false);

        return rtrim(is_string($base) && $base !== '' ? $base : self::SERVER, '/');
    }

    /**
     * The page sealed for upload: `iv ‖ ciphertext ‖ tag`, the layout WebCrypto's AES-GCM reads once
     * the IV is split off, and the key as base64url for the link's fragment.
     *
     * @return array{body: string, key: string}
     */
    public static function seal(string $html): array
    {
        $plain = function_exists('gzencode') ? (string) gzencode($html, 9) : $html;
        $key = random_bytes(32);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the session: ' . (string) openssl_error_string());
        }

        return ['body' => $iv . $cipher . $tag, 'key' => self::base64Url($key)];
    }

    /**
     * What `seal()` sealed, as the viewer page recovers it: decrypted, then gunzipped when it is gzip.
     *
     * @throws RuntimeException when the key does not open it
     */
    public static function open(string $body, string $key): string
    {
        $raw = base64_decode(strtr($key, '-_', '+/'), true);
        $iv = substr($body, 0, self::IV_BYTES);
        $tag = substr($body, -self::TAG_BYTES);
        $cipher = substr($body, self::IV_BYTES, -self::TAG_BYTES);
        $plain = $raw === false ? false : openssl_decrypt($cipher, self::CIPHER, $raw, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new RuntimeException('The key does not open this share.');
        }

        return str_starts_with($plain, "\x1f\x8b") ? (string) gzdecode($plain) : $plain;
    }

    /**
     * The sealed page stored on pigagent.dev for `$days`: the share's id, the token that deletes it
     * and when the server will.
     *
     * @return array{id: string, deleteToken: string, expiresAt: int}
     * @throws RuntimeException with the server's reason, or the transport's
     */
    public static function upload(string $body, int $days, ?HttpClient $http = null, ?AbortSignal $signal = null, ?string $server = null): array
    {
        $server ??= self::server();
        $request = new Request('POST', $server . '/api/shares?days=' . $days, [
            'content-type' => 'application/octet-stream',
            'content-length' => (string) strlen($body),
            'user-agent' => 'pig/' . Version::current(),
        ], $body);
        [$status, $json] = self::send($http ?? new HttpClient(timeout: 300.0), $request, $signal, $server);
        $id = $json['id'] ?? null;
        $token = $json['deleteToken'] ?? null;
        $expires = $json['expiresAt'] ?? null;

        if ($status !== 200 || !is_string($id) || preg_match('/^[0-9a-f]{16,64}$/', $id) !== 1 || !is_string($token) || $token === '' || !is_int($expires)) {
            throw new RuntimeException('pigagent.dev refused the share: ' . self::reason($status, $json));
        }

        return ['id' => $id, 'deleteToken' => $token, 'expiresAt' => $expires];
    }

    /** The link to a share: its page on pigagent.dev, the key after the `#`. */
    public static function url(string $id, string $key, ?string $server = null): string
    {
        return ($server ?? self::server()) . "/s/{$id}#{$key}";
    }

    /**
     * The share deleted from pigagent.dev now. One already gone (expired, deleted) is not an error.
     *
     * @throws RuntimeException with the server's reason, or the transport's
     */
    public static function delete(string $id, string $deleteToken, ?HttpClient $http = null, ?AbortSignal $signal = null, ?string $server = null): void
    {
        $server ??= self::server();
        $request = new Request('DELETE', $server . '/api/shares/' . rawurlencode($id), [
            'authorization' => "Bearer {$deleteToken}",
            'content-length' => '0',
            'user-agent' => 'pig/' . Version::current(),
        ], '');
        [$status, $json] = self::send($http ?? new HttpClient(timeout: 30.0), $request, $signal, $server);

        if ($status !== 200 && $status !== 204 && $status !== 404) {
            throw new RuntimeException('pigagent.dev did not delete the share: ' . self::reason($status, $json));
        }
    }

    /**
     * Upstream's `createShareTrailingEntries()`: one custom entry after the last one, carrying the
     * system prompt and the tool schemas, so a viewer of the transcript sees what the model saw.
     *
     * @return list<array<string, mixed>>
     */
    public static function trailingEntries(AgentSession $session, ?string $parentId, string $timestamp): array
    {
        return [[
            'type' => 'custom',
            'customType' => self::CUSTOM_TYPE,
            'id' => substr(bin2hex(random_bytes(4)), 0, 8),
            'parentId' => $parentId,
            'timestamp' => $timestamp,
            'data' => [
                'systemPrompt' => $session->systemPrompt(),
                'tools' => array_map(static function (AgentTool $tool): array {
                    $definition = $tool->definition();

                    return [
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'parameters' => $definition->parameters,
                    ];
                }, $session->agent->tools()),
            ],
        ]];
    }

    /**
     * The request sent and its answer read: the status and the JSON body (empty when it is not JSON).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function send(HttpClient $http, Request $request, ?AbortSignal $signal, string $server): array
    {
        try {
            $response = $http->send($request, $signal);
            $body = '';

            foreach ($response->body as $chunk) {
                $body .= $chunk;
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('Could not reach ' . (string) parse_url($server, PHP_URL_HOST) . ': ' . $error->getMessage(), 0, $error);
        }

        $json = json_decode($body, true);

        return [$response->status, is_array($json) ? $json : []];
    }

    /**
     * The server's own words: its `error`, or the `error.message` pigagent.dev's framework answers
     * with (a rate limit, say), else the status.
     *
     * @param array<string, mixed> $json
     */
    private static function reason(int $status, array $json): string
    {
        $error = $json['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : $error;

        return is_string($message) && $message !== '' ? $message : "HTTP {$status}";
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
