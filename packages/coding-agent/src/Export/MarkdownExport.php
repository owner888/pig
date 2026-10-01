<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\Agent\AgentError;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\Compaction;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Tui\Process;
use Throwable;

/**
 * Pure Markdown conversation and PR description exporter.
 */
final class MarkdownExport
{
    /**
     * Render the whole conversation into clean Markdown.
     *
     * @param list<mixed> $messages
     */
    public static function render(array $messages, string $cwd): string
    {
        $lines = [];
        $lines[] = "# Conversation Export";
        $lines[] = "Working Directory: `{$cwd}`";
        $lines[] = "Exported: " . date('Y-m-d H:i:s');
        $lines[] = "";
        $lines[] = "---";
        $lines[] = "";

        foreach ($messages as $m) {
            if ($m instanceof UserMessage) {
                $lines[] = "### 👤 User";
                $lines[] = "";
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $lines[] = trim($c->text);
                    }
                }
                $lines[] = "";
            } elseif ($m instanceof AssistantMessage) {
                $lines[] = "### 🤖 Assistant";
                $lines[] = "";
                foreach ($m->content as $c) {
                    if ($c instanceof ThinkingContent && trim($c->thinking) !== '') {
                        $lines[] = "<details><summary>Thinking Process</summary>\n\n" . trim($c->thinking) . "\n\n</details>";
                        $lines[] = "";
                    } elseif ($c instanceof ToolCall) {
                        $args = json_encode($c->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $lines[] = "**Tool Call:** `{$c->name}`\n```json\n{$args}\n```";
                        $lines[] = "";
                    } elseif ($c instanceof TextContent) {
                        $lines[] = trim($c->text);
                        $lines[] = "";
                    }
                }
            } elseif ($m instanceof ToolResultMessage) {
                $text = '';
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $text .= $c->text;
                    }
                }
                $lines[] = "<details><summary>Tool Result: {$m->toolName}</summary>\n\n```text\n" . trim($text) . "\n```\n\n</details>";
                $lines[] = "";
            }
        }

        return trim(implode("\n", $lines)) . "\n";
    }

    /**
     * Compute a default markdown file path beside the session or in cwd.
     */
    public static function defaultPath(SessionManager $store, string $cwd, string $suffix = '.md'): string
    {
        $sessionFile = $store->path();
        if ($sessionFile !== null && is_file($sessionFile)) {
            $base = preg_replace('/\.jsonl$/i', '', basename($sessionFile));

            return $cwd . '/' . $base . $suffix;
        }

        return $cwd . '/conversation-' . date('Y-m-d-His') . $suffix;
    }

    /**
     * Write conversation to a Markdown file.
     */
    public static function write(SessionManager $session, string $path): string
    {
        $messages = $session->messages();

        if ($messages === []) {
            throw new AgentError('Nothing to export yet — say something first.');
        }

        $md = self::render($messages, $session->cwd);

        if (file_put_contents($path, $md) === false) {
            throw new AgentError("Could not write {$path}");
        }

        return $path;
    }

    /**
     * Generate a professional GitHub Pull Request description based on the session's work.
     *
     * @param (Closure(Context): \Pig\Ai\Utils\AssistantMessageEventStream)|null $streamFn
     */
    public static function generatePrDescription(AgentSession $session, ?\Closure $streamFn = null): string
    {
        $messages = $session->messages();
        if ($messages === []) {
            throw new AgentError('Nothing to describe yet — have a conversation first.');
        }

        $model = $session->model();
        if ($model === null) {
            throw new AgentError('No model configured to generate PR description.');
        }

        $cwd = $session->cwd();
        $fileOps = Compaction::files($messages);
        $modifiedFiles = $fileOps['modified'] ?? [];

        // 1. Collect conversation summary context
        $userRequests = [];
        $assistantSolutions = [];

        foreach ($messages as $m) {
            if ($m instanceof UserMessage) {
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent && trim($c->text) !== '') {
                        $userRequests[] = trim($c->text);
                    }
                }
            } elseif ($m instanceof AssistantMessage) {
                $text = '';
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $text .= $c->text;
                    }
                }
                if (trim($text) !== '') {
                    $assistantSolutions[] = trim($text);
                }
            }
        }

        // Take initial and latest turns
        $contextText = "User Requirements:\n- " . implode("\n- ", array_slice($userRequests, 0, 5)) . "\n\n"
            . "Key Implementation Notes:\n- " . implode("\n- ", array_slice($assistantSolutions, -3));

        if ($modifiedFiles !== []) {
            $contextText .= "\n\nModified Files Detected:\n- " . implode("\n- ", $modifiedFiles);
        }

        // Git diff summary if available
        [$diffCode, $diffOut] = Process::run(['git', 'diff', '--stat', 'HEAD'], cwd: $cwd);
        if ($diffCode === 0 && trim($diffOut) !== '') {
            $contextText .= "\n\nGit Diff Stat:\n" . trim($diffOut);
        }

        $prompt = <<<PROMPT
You are an expert lead software engineer creating a professional, clean GitHub Pull Request description.
Based on the following session context and modified files, generate a comprehensive PR description formatted strictly in GitHub-flavored Markdown.

Include exactly these sections:
## 🎯 Summary
A concise 2-3 sentence overview of the problem solved and core capabilities added.

## 🛠️ Key Changes
A bulleted breakdown of the key architectural, protocol, or implementation changes made.

## 📂 Modified & Added Files
A clear list of the primary modified or newly added files with a brief 1-sentence note for each.

## ✅ Verification & Testing
How these changes were verified (e.g. unit tests, manual checks, protocol validations).

Output ONLY the Markdown description content itself, with no conversational preamble or enclosing triple-backtick fences.

Context:
{$contextText}
PROMPT;

        try {
            $context = new Context([new UserMessage($prompt)], systemPrompt: 'You are an expert lead engineer writing top-tier GitHub Pull Request descriptions.');

            $stream = $streamFn !== null
                ? $streamFn($context)
                : Stream::simple(
                    $model,
                    $context,
                    new SimpleStreamOptions(temperature: 0.2, apiKey: $session->keyFor($model)),
                );

            $assistantMessage = $stream->result()->await();
            if ($assistantMessage->stopReason === StopReason::Error) {
                throw new AgentError('Provider returned error: ' . ($assistantMessage->errorMessage ?? 'Unknown error'));
            }

            $text = '';
            foreach ($assistantMessage->content as $c) {
                if ($c instanceof TextContent) {
                    $text .= $c->text;
                }
            }
            $text = trim($text);

            return trim((string) preg_replace('/^```[a-z]*\n|```$/i', '', $text));
        } catch (Throwable $e) {
            throw new AgentError('Failed to generate PR description: ' . $e->getMessage(), 0, $e);
        }
    }
}
