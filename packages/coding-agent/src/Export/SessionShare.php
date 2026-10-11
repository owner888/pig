<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\Agent\AgentTool;
use Pig\CodingAgent\Session\AgentSession;

/**
 * The parts of upstream's `modes/interactive/session-share.ts` that are not screen: where a
 * shared gist is viewed, and the trailing entry an exported transcript carries for a viewer.
 *
 * What is not here: upstream's first choice, uploading the JSONL to Radius as an artifact
 * (`tryShareViaRadius()`). pig has no Radius sign-in to upload with, so `/share` always takes
 * upstream's second road — a secret gist of the HTML export, made by `gh`.
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
