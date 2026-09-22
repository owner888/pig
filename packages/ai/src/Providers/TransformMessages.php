<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;

/**
 * Making a conversation safe to send to a provider that did not produce it.
 *
 * `/model` can change provider mid-session, which leaves two kinds of wreckage in the
 * history. This cleans up both, before any provider sees it.
 *
 * Ported from upstream's `providers/transorm-messages.ts` — the misspelling is theirs.
 */
final class TransformMessages
{
    /** Copilot's own ids come back 450 characters long; other APIs cap at 40. */
    private const int COPILOT_ID_LENGTH = 40;

    private const string NO_RESULT = 'No result provided';

    /**
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    public static function apply(array $messages, Model $model): array
    {
        return self::fillOrphanedCalls(self::retarget($messages, $model));
    }

    /**
     * Rewrite what the new provider cannot read.
     *
     * A thinking block belongs to the model that produced it — it is signed, and the
     * signature means nothing anywhere else — so crossing providers it becomes text in
     * `<thinking>` tags. The reasoning is not lost; it stops claiming to be reasoning
     * this model did.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    private static function retarget(array $messages, Model $model): array
    {
        $renamedIds = [];
        $out = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $out[] = isset($renamedIds[$message->toolCallId])
                    ? new ToolResultMessage(
                        $renamedIds[$message->toolCallId],
                        $message->toolName,
                        $message->content,
                        $message->isError,
                        $message->details,
                        $message->timestamp,
                    )
                    : $message;

                continue;
            }

            if (!$message instanceof AssistantMessage) {
                $out[] = $message;

                continue;
            }

            // Same provider and same API: it produced this, so it can read it back.
            if ($message->provider === $model->provider && $message->api === $model->api) {
                $out[] = $message;

                continue;
            }

            // One provider, two of its own APIs: Copilot serves both, and the ids its
            // responses API mints are rejected by its own completions API.
            $renameIds = $message->provider === 'github-copilot'
                && $model->provider === 'github-copilot'
                && $message->api !== $model->api;

            $content = [];

            foreach ($message->content as $block) {
                if ($block instanceof ThinkingContent) {
                    $content[] = new TextContent("<thinking>\n{$block->thinking}\n</thinking>");

                    continue;
                }

                if ($block instanceof ToolCall && $renameIds) {
                    $id = self::copilotId($block->id);

                    if ($id !== $block->id) {
                        $renamedIds[$block->id] = $id;
                        $content[] = new ToolCall($id, $block->name, $block->arguments, $block->thoughtSignature);

                        continue;
                    }
                }

                $content[] = $block;
            }

            $out[] = new AssistantMessage(
                $content,
                $message->api,
                $message->provider,
                $message->model,
                $message->usage,
                $message->stopReason,
                $message->errorMessage,
                $message->timestamp,
            );
        }

        return $out;
    }

    /**
     * Give every tool call a result, inventing the missing ones.
     *
     * A call with no result is what an interrupted turn leaves behind, and every
     * provider rejects the conversation outright rather than ignoring the dangling call.
     * A stated "No result provided" is worse than the truth and far better than a request
     * that cannot be sent at all — and it keeps the assistant message, signatures and
     * all, instead of dropping it.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    private static function fillOrphanedCalls(array $messages): array
    {
        $out = [];
        $pending = [];
        $answered = [];

        $flush = static function () use (&$out, &$pending, &$answered): void {
            foreach ($pending as $call) {
                if (!isset($answered[$call->id])) {
                    $out[] = new ToolResultMessage(
                        $call->id,
                        $call->name,
                        [new TextContent(self::NO_RESULT)],
                        true,
                    );
                }
            }

            $pending = [];
            $answered = [];
        };

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $answered[$message->toolCallId] = true;
                $out[] = $message;

                continue;
            }

            // Anything that is not a result closes the turn the calls were made in.
            $flush();
            $out[] = $message;

            if ($message instanceof AssistantMessage) {
                foreach ($message->content as $block) {
                    if ($block instanceof ToolCall) {
                        $pending[] = $block;
                    }
                }
            }
        }

        $flush();

        return $out;
    }

    private static function copilotId(string $id): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '', $id), 0, self::COPILOT_ID_LENGTH);
    }
}
