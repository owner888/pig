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
 * Ported from upstream's `api/transform-messages.ts`.
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
     * Rewrite what the new model cannot read.
     *
     * Signatures belong to the model that produced them — a thinking signature, a text
     * signature, a tool call's thought signature — and mean nothing to any other model, even
     * one of the same provider behind the same API. So "same" is upstream's `isSameModel`:
     * provider, API **and** model id all match. Only then do signatures travel. Otherwise a
     * thought becomes plain text (upstream adds no tags, so the model does not learn to mimic
     * them), a text block loses its signature, and a tool call loses its thought signature.
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

            $sameModel = $message->provider === $model->provider
                && $message->api === $model->api
                && $message->model === $model->id;

            // One provider, two of its own APIs: Copilot serves both, and the ids its
            // responses API mints are rejected by its own completions API. Upstream does this
            // through the `normalizeToolCallId` callback each provider passes in; pig keeps
            // the one rule here.
            $renameIds = $message->provider === 'github-copilot'
                && $model->provider === 'github-copilot'
                && $message->api !== $model->api;

            $content = [];
            $changed = false;

            foreach ($message->content as $block) {
                $next = match (true) {
                    $block instanceof ThinkingContent => self::thinking($block, $sameModel),
                    $block instanceof TextContent => $sameModel ? $block : new TextContent($block->text),
                    $block instanceof ToolCall => self::toolCall($block, $sameModel, $renameIds, $renamedIds),
                    default => $block,
                };

                $changed = $changed || $next !== $block;

                if ($next !== null) {
                    $content[] = $next;
                }
            }

            $out[] = $changed ? self::withContent($message, $content) : $message;
        }

        return $out;
    }

    /** Upstream's thinking branch, in its order. Null drops the block. */
    private static function thinking(ThinkingContent $block, bool $sameModel): ThinkingContent|TextContent|null
    {
        // Withheld reasoning is encrypted for the one model that wrote it, and has no text to
        // pass on — only a placeholder — so anywhere else it is dropped.
        if ($block->redacted === true) {
            return $sameModel ? $block : null;
        }

        // Signed thinking is kept for its own model even with no text: OpenAI's encrypted
        // reasoning has none, and the signature is what gets replayed.
        if ($sameModel && $block->thinkingSignature !== null && $block->thinkingSignature !== '') {
            return $block;
        }

        if (trim($block->thinking) === '') {
            return null;
        }

        return $sameModel ? $block : new TextContent($block->thinking);
    }

    /** @param array<string, string> $renamedIds */
    private static function toolCall(ToolCall $block, bool $sameModel, bool $renameIds, array &$renamedIds): ToolCall
    {
        $signature = !$sameModel && $block->thoughtSignature !== null && $block->thoughtSignature !== ''
            ? null
            : $block->thoughtSignature;
        $id = $renameIds ? self::copilotId($block->id) : $block->id;

        if ($id !== $block->id) {
            $renamedIds[$block->id] = $id;
        }

        return $id === $block->id && $signature === $block->thoughtSignature
            ? $block
            : new ToolCall($id, $block->name, $block->arguments, $signature);
    }

    /** @param list<\Pig\Ai\AssistantContent> $content */
    private static function withContent(AssistantMessage $message, array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            $message->api,
            $message->provider,
            $message->model,
            $message->usage,
            $message->stopReason,
            $message->errorMessage,
            $message->timestamp,
            $message->rawStopReason,
            $message->responseId,
            $message->responseModel,
            $message->endTurn,
            $message->diagnostics,
        );
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
