<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Throwable;

/**
 * Markdown transformers applied to one surface — upstream's `createMarkdownTransform()` and
 * `applyMarkdownTransformers()`.
 *
 * The surfaces are upstream's `MarkdownMessageType`:
 * - `user`, `assistant`, `assistant-thinking`: transcript messages, the default of
 *   `registerMarkdownTransformer()`
 * - `custom`: a custom message drawn without a renderer of its own, and an expanded skill block's
 *   content (without pig's header)
 * - `summary`: an expanded branch or compaction summary (without pig's header)
 * - `changelog`: What's New, `/changelog`, and the Extension Updates notice
 *
 * The context a transformer is handed is upstream's — `messageType` and `isStreaming` — with `role`
 * beside it, the name pig's transformers were given before there were surfaces beyond two roles.
 */
final class MarkdownTransform
{
    public const array MESSAGE_TYPES = ['user', 'assistant', 'assistant-thinking', 'custom', 'summary', 'changelog'];

    /** What a transformer registered without options runs on. */
    public const array DEFAULT_MESSAGE_TYPES = ['user', 'assistant', 'assistant-thinking'];

    /**
     * Each transformer in turn. One that throws, or answers anything but a string, leaves the
     * Markdown as it was and the next one goes on — a broken extension costs its own change, not
     * the message.
     *
     * @param list<Closure(string, array<string, mixed>): mixed> $transformers
     * @param value-of<self::MESSAGE_TYPES> $messageType
     */
    public static function apply(array $transformers, string $markdown, string $messageType, bool $isStreaming = false): string
    {
        $context = ['messageType' => $messageType, 'role' => $messageType, 'isStreaming' => $isStreaming];

        foreach ($transformers as $transformer) {
            try {
                $transformed = $transformer($markdown, $context);
            } catch (Throwable) {
                // Not re-thrown, on purpose: upstream's documented contract ("If a transformer throws,
                // Pi keeps the Markdown produced so far and continues with the next transformer").
                // The hook is display-only, and a throw here would take the transcript down with it.
                continue;
            }

            if (is_string($transformed)) {
                $markdown = $transformed;
            }
        }

        return $markdown;
    }

    /**
     * `$transformer` on `$messageTypes` only; the Markdown as it is everywhere else.
     *
     * @param Closure(string, array<string, mixed>): mixed $transformer
     * @param list<string> $messageTypes
     * @return Closure(string, array<string, mixed>): mixed
     */
    public static function only(Closure $transformer, array $messageTypes): Closure
    {
        $types = array_fill_keys($messageTypes, true);

        return static fn (string $markdown, array $context): mixed => isset($types[$context['messageType'] ?? '']) ? $transformer($markdown, $context) : $markdown;
    }
}
