<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;

/**
 * What a tool result looks like to a model — upstream's `protocol/content.ts`.
 *
 * Text and images pass through, an embedded text resource becomes text, an embedded image
 * resource becomes an image, and everything else (audio, resource links, binary resources) becomes
 * a short placeholder. A result with no content blocks but with `structuredContent` becomes its
 * JSON, since servers should — but do not always — mirror structured results as text.
 */
final class Content
{
    /**
     * @param array<string, mixed> $result a `tools/call` result as the server sent it
     * @return list<TextContent|ImageContent>
     */
    public static function toLlm(array $result): array
    {
        $content = [];

        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block)) {
                $content[] = self::block($block);
            }
        }

        if ($content === [] && array_key_exists('structuredContent', $result)) {
            $content[] = new TextContent((string) json_encode($result['structuredContent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $content;
    }

    /** @param array<string, mixed> $block */
    private static function block(array $block): TextContent|ImageContent
    {
        $type = $block['type'] ?? null;

        switch ($type) {
            case 'text':
                return new TextContent((string) ($block['text'] ?? ''));
            case 'image':
                return new ImageContent((string) ($block['data'] ?? ''), (string) ($block['mimeType'] ?? 'image/png'));
            case 'audio':
                return new TextContent(sprintf('[audio %s omitted]', (string) ($block['mimeType'] ?? '')));
            case 'resource_link':
                return new TextContent(sprintf('%s: %s', (string) ($block['name'] ?? ''), (string) ($block['uri'] ?? '')));
            case 'resource':
                $resource = is_array($block['resource'] ?? null) ? $block['resource'] : [];

                if (array_key_exists('text', $resource)) {
                    return new TextContent((string) $resource['text']);
                }

                $mime = (string) ($resource['mimeType'] ?? '');

                if (str_starts_with($mime, 'image/')) {
                    return new ImageContent((string) ($resource['blob'] ?? ''), $mime);
                }

                return new TextContent(sprintf(
                    '[binary resource %s (%s) omitted]',
                    (string) ($resource['uri'] ?? ''),
                    $mime !== '' ? $mime : 'unknown type',
                ));
            default:
                return new TextContent(sprintf('[unsupported MCP content %s]', is_scalar($type) ? (string) $type : 'unknown'));
        }
    }
}
