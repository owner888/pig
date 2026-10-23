<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\Agent\AgentTool;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Providers\RadiusConfig;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Session\AgentSession;
use RuntimeException;

/**
 * The parts of upstream's `modes/interactive/session-share.ts` that are not screen: where a
 * shared gist is viewed, the trailing entry an exported transcript carries for a viewer, and the
 * Radius upload (`tryShareViaRadius()`) — `/share`'s first road when Radius is signed in, as the
 * `sessionShareDestination` setting says.
 */
final class SessionShare
{
    /**
     * Upstream's `DEFAULT_SHARE_VIEWER_URL`, pig's own: pigagent.dev's `/session/` page fetches
     * the gist in the browser and shows the HTML export in a sandboxed frame.
     */
    public const string DEFAULT_VIEWER_URL = 'https://pigagent.dev/session/';

    /** The custom entry type of the trailing entry — upstream's `pi.share`, renamed. */
    public const string CUSTOM_TYPE = 'pig.share';

    /** Upstream's `getShareViewerUrl()`: `PIG_SHARE_VIEWER_URL` (or the default), then `#<gist id>`. */
    public static function viewerUrl(string $gistId, ?array $env = null): string
    {
        $base = $env === null ? getenv('PIG_SHARE_VIEWER_URL') : ($env['PIG_SHARE_VIEWER_URL'] ?? false);

        return (is_string($base) && $base !== '' ? $base : self::DEFAULT_VIEWER_URL) . '#' . $gistId;
    }

    /**
     * Upstream's `tryShareViaRadius()` request: the session's JSONL export posted to the Radius
     * gateway's `/v1/artifacts` as an organization-visible artifact titled "Pi session", and the
     * artifact's `canonical_url` back.
     *
     * @throws RuntimeException "Failed to upload Radius artifact: …", upstream's sentence
     */
    public static function uploadToRadius(string $jsonl, string $token, ?HttpClient $http = null, ?AbortSignal $signal = null, string $gateway = RadiusConfig::DEFAULT_RADIUS_GATEWAY): string
    {
        $url = rtrim($gateway, '/') . '/v1/artifacts?' . http_build_query(['visibility' => 'organization', 'title' => 'Pi session'], '', '&', PHP_QUERY_RFC3986);
        $request = new Request('POST', $url, [
            'authorization' => "Bearer {$token}",
            'content-type' => 'application/x-ndjson',
            'content-length' => (string) strlen($jsonl),
        ], $jsonl);

        try {
            $response = ($http ?? new HttpClient(timeout: 300.0))->send($request, $signal);
            $body = '';

            foreach ($response->body as $chunk) {
                $body .= $chunk;
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('Failed to upload Radius artifact: ' . $error->getMessage(), 0, $error);
        }

        $json = json_decode($body, true);
        $artifactUrl = is_array($json) && is_array($json['artifact'] ?? null) && is_string($json['artifact']['canonical_url'] ?? null) ? $json['artifact']['canonical_url'] : null;

        if ($response->status < 200 || $response->status >= 300 || $artifactUrl === null) {
            $reason = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : (string) $response->status;

            throw new RuntimeException("Failed to upload Radius artifact: {$reason}");
        }

        return $artifactUrl;
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
}
