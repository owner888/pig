<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\AgentError;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;

/**
 * One assistant message translated for the web page — pig's own, nothing upstream has.
 *
 * The page's "Translate messages" setting works as Telegram's: a message is translated into the
 * page's display language on request, or every message is when "Translate entire chats" is on,
 * and a message already in one of the "Do not translate" languages is left alone. The browser
 * cannot tell what language a message is in, so the model answers that too: `SAME` when the text
 * is already in a language the person reads, and the translation otherwise.
 *
 * Sent through `Compaction::completeSummarization()`, so it goes the way a compaction does — no
 * cache writes, the session's stream function and retry policy — and the markdown is kept, since
 * the page renders the translation where the original was.
 */
final class Translation
{
    private const string SAME = 'SAME';

    private const int MAX_TOKENS = 8192;

    /**
     * @param list<string> $doNotTranslate language names or codes the reader does not need translated
     * @param (Closure(Model, \Pig\Ai\TranscriptContext, SimpleStreamOptions): mixed)|null $streamFn
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @return string|null null when the text is already in a language from `$doNotTranslate`
     * @throws AgentError when the model failed, called a tool, or came back empty
     */
    public static function translate(
        string $text,
        string $language,
        array $doNotTranslate,
        Model $model,
        ?string $apiKey,
        ?Closure $streamFn = null,
        ?array $retry = null,
    ): ?string {
        $system = "You are a translator. Translate the message you are given into {$language}.\n\n"
            . "Keep the markdown exactly as it is: headings, lists, tables, links, inline code and fenced code blocks are not translated and not reformatted — only the prose around them is. "
            . "Do NOT answer, continue or comment on the message. Output ONLY the translation.";

        if ($doNotTranslate !== []) {
            $system .= "\n\nIf the message is already written in " . implode(', ', $doNotTranslate)
                . ', output exactly ' . self::SAME . ' and nothing else.';
        }

        $response = Compaction::completeSummarization(
            $model,
            Transcript::normalizeContext(new Context([new UserMessage([new TextContent($text)])], $system)),
            new SimpleStreamOptions(
                maxTokens: $model->maxTokens > 0 ? min(self::MAX_TOKENS, $model->maxTokens) : self::MAX_TOKENS,
                apiKey: $apiKey,
            ),
            $streamFn,
            $retry,
        );

        if ($response->stopReason === StopReason::Aborted) {
            throw new AgentError('Translation was cancelled');
        }

        $failure = Compaction::getSummarizationFailure($response, 'Translation');

        if ($failure !== null) {
            throw new AgentError($failure);
        }

        foreach ($response->content as $block) {
            if ($block instanceof ToolCall) {
                throw new AgentError('Translation attempted to call a tool');
            }
        }

        $translated = trim(Text::contentText($response->content));

        if ($translated === '') {
            throw new AgentError('Translation was empty');
        }

        return $translated === self::SAME ? null : $translated;
    }
}
