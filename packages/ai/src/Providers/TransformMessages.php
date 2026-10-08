<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;

/**
 * Making a conversation safe to send to a provider that did not produce it.
 *
 * `/model` can change provider mid-session, and a turn can be cut short, which leaves
 * wreckage in the history. This cleans it up before any provider sees it.
 *
 * Ported from upstream's `api/transform-messages.ts`.
 */
final class TransformMessages
{
    private const string NO_RESULT = 'No result provided';

    /** Upstream's `NON_VISION_USER_IMAGE_PLACEHOLDER`. */
    private const string NON_VISION_USER_IMAGE_PLACEHOLDER = '(image omitted: model does not support images)';

    /** Upstream's `NON_VISION_TOOL_IMAGE_PLACEHOLDER`. */
    private const string NON_VISION_TOOL_IMAGE_PLACEHOLDER = '(tool image omitted: model does not support images)';

    /**
     * Upstream's `transformMessages(messages, model, normalizeToolCallId?)`.
     *
     * **Tool call ids are each provider's business, not this file's.** One API mints ids another
     * refuses — the Responses API's `call_id|item_id` runs past 450 characters with `|`, `+`, `/`
     * and `=` in it, and Anthropic accepts only `^[a-zA-Z0-9_-]+$` up to 64 — so every provider
     * passes its own rule in, and it is applied to the calls of any message that is not from this
     * very model (`isSameModel`). A result addressed to a renamed call follows it.
     *
     * @param list<mixed> $messages
     * @param (\Closure(string, Model, AssistantMessage): string)|null $normalizeToolCallId
     *        the id, the model being sent to, and the message the call came from
     * @return list<mixed>
     */
    public static function apply(array $messages, Model $model, ?\Closure $normalizeToolCallId = null): array
    {
        return self::fillOrphanedCalls(self::retarget(
            self::downgradeUnsupportedImages($messages, $model),
            $model,
            $normalizeToolCallId,
        ));
    }

    /**
     * Upstream's `downgradeUnsupportedImages()`: for a model whose `input` has no `image`, every
     * image in a user message or a tool result becomes a line of text saying it was left out.
     *
     * Before this, the images were simply not sent — each provider skips an `ImageContent` the
     * model cannot read — so the model never learned there had been one, and answered "what is in
     * this screenshot?" as if nothing had been attached. Assistant messages are left alone, as
     * upstream leaves them.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    private static function downgradeUnsupportedImages(array $messages, Model $model): array
    {
        if ($model->acceptsImages()) {
            return $messages;
        }

        return array_map(static function (mixed $message): mixed {
            if ($message instanceof UserMessage) {
                return new UserMessage(
                    self::replaceImagesWithPlaceholder($message->content, self::NON_VISION_USER_IMAGE_PLACEHOLDER),
                    $message->timestamp,
                );
            }

            if ($message instanceof ToolResultMessage) {
                return new ToolResultMessage(
                    $message->toolCallId,
                    $message->toolName,
                    self::replaceImagesWithPlaceholder($message->content, self::NON_VISION_TOOL_IMAGE_PLACEHOLDER),
                    $message->isError,
                    $message->details,
                    $message->timestamp,
                );
            }

            return $message;
        }, $messages);
    }

    /**
     * Upstream's `replaceImagesWithPlaceholder()`, literally: a run of images is **one**
     * placeholder, and so is an image right after a text block that already *is* the placeholder —
     * the second time a history goes through here, or a run split by nothing.
     *
     * @param list<mixed> $content
     * @return list<mixed>
     */
    private static function replaceImagesWithPlaceholder(array $content, string $placeholder): array
    {
        $result = [];
        $previousWasPlaceholder = false;

        foreach ($content as $block) {
            if ($block instanceof ImageContent) {
                if (!$previousWasPlaceholder) {
                    $result[] = new TextContent($placeholder);
                }

                $previousWasPlaceholder = true;

                continue;
            }

            $result[] = $block;
            $previousWasPlaceholder = $block instanceof TextContent && $block->text === $placeholder;
        }

        return $result;
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
     * @param (\Closure(string, Model, AssistantMessage): string)|null $normalizeToolCallId
     * @return list<mixed>
     */
    private static function retarget(array $messages, Model $model, ?\Closure $normalizeToolCallId): array
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

            // Upstream's `!isSameModel && normalizeToolCallId`: a model's own ids go back to it
            // untouched, and anything else goes through the target provider's rule.
            $normalize = !$sameModel ? $normalizeToolCallId : null;

            $content = [];
            $changed = false;

            foreach ($message->content as $block) {
                $next = match (true) {
                    $block instanceof ThinkingContent => self::thinking($block, $sameModel),
                    $block instanceof TextContent => $sameModel ? $block : new TextContent($block->text),
                    $block instanceof ToolCall => self::toolCall($block, $sameModel, $normalize, $message, $model, $renamedIds),
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

    /**
     * @param (\Closure(string, Model, AssistantMessage): string)|null $normalize
     * @param array<string, string> $renamedIds
     */
    private static function toolCall(
        ToolCall $block,
        bool $sameModel,
        ?\Closure $normalize,
        AssistantMessage $source,
        Model $model,
        array &$renamedIds,
    ): ToolCall {
        $signature = !$sameModel && $block->thoughtSignature !== null && $block->thoughtSignature !== ''
            ? null
            : $block->thoughtSignature;
        $id = $normalize !== null ? $normalize($block->id, $model, $source) : $block->id;

        if ($id !== $block->id) {
            $renamedIds[$block->id] = $id;
        }

        return $id === $block->id && $signature === $block->thoughtSignature
            ? $block
            // Upstream spreads the call (`{ ...toolCall, id }`), so everything else on it — its
            // `namespace` included — goes along.
            : new ToolCall($id, $block->name, $block->arguments, $signature, $block->namespace);
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
            $message->providerThinkingLevel,
            $message->deferred,
        );
    }

    /**
     * Upstream's second pass: drop failed turns, and give every tool call a result.
     *
     * **An assistant turn that ended in an error or was aborted is not replayed at all**, calls
     * and all. It is an incomplete turn — reasoning with no message after it, a call whose
     * arguments were cut off — and replaying it is what earns OpenAI's "reasoning without its
     * following item" 400; the model retries from the last turn that finished instead. Any calls
     * still pending from the turn before it are closed first, as upstream closes them before the
     * check.
     *
     * A call with no result is what an interrupted turn leaves behind, and every provider rejects
     * the conversation outright rather than ignoring the dangling call. A stated
     * "No result provided" (`isError: true`) is worse than the truth and far better than a request
     * that cannot be sent at all — and it keeps the assistant message, signatures and all,
     * instead of dropping it. Results are invented before the next assistant or user message
     * **and at the end of the conversation**, which is where an interrupted turn leaves one.
     *
     * Where pig differs: upstream also holds back a `system` message that falls between a call
     * and its results, emitting it after them. pig's message union has no system message — the
     * prompt is `Context::$systemPrompt`, and `convertToLlm` reduces everything else to user,
     * assistant and result messages before this runs — so there is nothing to hold, and every message that is
     * not a result closes the turn, as upstream's user and assistant arms do.
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

            if ($message instanceof AssistantMessage
                && ($message->stopReason === StopReason::Error || $message->stopReason === StopReason::Aborted)) {
                continue;
            }

            $out[] = $message;

            if ($message instanceof AssistantMessage) {
                foreach ($message->content as $block) {
                    if ($block instanceof ToolCall) {
                        $pending[] = $block;
                    }
                }
            }
        }

        // The conversation can end on unanswered calls; they are answered here.
        $flush();

        return $out;
    }
}
