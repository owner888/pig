<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\UserMessage;

/**
 * What GitHub Copilot wants on a request that the OpenAI API does not.
 *
 * Copilot serves both OpenAI APIs and sends these three headers on either, so upstream has the
 * same block in `openai-completions.ts` and `openai-responses.ts`. **pig had it in the completions
 * provider only — and Copilot's models are `openai-responses` in the registry**, so the provider
 * that actually talks to Copilot sent none of them: every agent follow-up was billed and
 * rate-limited as though a person had just typed it, and an image was refused outright.
 *
 * Here rather than copied into both for the reason this audit keeps finding: the same rule in two
 * places is the same rule about to go missing from one of them.
 */
final class Copilot
{
    /**
     * The headers, or nothing at all for a model that is not Copilot's.
     *
     * @return array<string, string>
     */
    public static function headers(Model $model, Context $context): array
    {
        if ($model->provider !== 'github-copilot') {
            return [];
        }

        $last = $context->messages[count($context->messages) - 1] ?? null;

        // Who asked: a turn that follows a tool result or an answer is the agent carrying on, and
        // Copilot bills and rate-limits that differently from something a person typed. Nothing
        // said yet counts as the person, which is upstream's default.
        $headers = [
            'X-Initiator' => $last !== null && !$last instanceof UserMessage ? 'agent' : 'user',
            'Openai-Intent' => 'conversation-edits',
        ];

        foreach ($context->messages as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof ImageContent) {
                    $headers['Copilot-Vision-Request'] = 'true';

                    return $headers;
                }
            }
        }

        return $headers;
    }
}
