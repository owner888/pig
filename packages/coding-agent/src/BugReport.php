<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\CodingAgent\Session\AgentSession;

/**
 * Upstream's `/bug`, with the one difference pig cannot avoid: there is no server to upload to.
 *
 * Upstream posts the bundle to its own endpoint and falls back to a zip. pig writes a Markdown
 * report under `~/.pig/agent/bug-reports/`, puts it on the clipboard, and builds a GitHub
 * "new issue" URL with the body prefilled — which is where a report for an open-source project
 * belongs anyway. Nothing leaves the machine unless the person opens that URL.
 *
 * What goes in follows upstream's disclaimer: version, OS, PHP, the model and provider (never a
 * key), loaded extensions, the last provider error, and `/doctor`'s findings. The transcript is
 * opt-in, because it holds whatever the model read.
 */
final class BugReport
{
    public const string ISSUES_URL = 'https://github.com/owner888/pig/issues/new';

    /** GitHub refuses a URL much past this, so the prefilled body is cut to fit. */
    private const int MAX_URL_BODY = 6000;

    public static function build(AgentSession $session, ?Auth $auth, string $hint, bool $includeTranscript): string
    {
        $model = $session->model();
        $lines = [];

        $lines[] = '# pig bug report';
        $lines[] = '';
        $lines[] = '## What went wrong';
        $lines[] = '';
        $lines[] = $hint !== '' ? $hint : '_(no description given)_';
        $lines[] = '';

        $lines[] = '## Environment';
        $lines[] = '';
        $lines[] = '- pig: ' . Version::current();
        $lines[] = '- PHP: ' . PHP_VERSION;
        $lines[] = '- OS: ' . PHP_OS_FAMILY . ' ' . php_uname('r') . ' (' . php_uname('m') . ')';
        $lines[] = '- Model: ' . ($model === null ? 'none' : "{$model->provider}/{$model->id}");
        $lines[] = '- Thinking: ' . $session->thinkingLevel()->value;
        $lines[] = '- Working directory: ' . $session->cwd();
        $lines[] = '';

        $lastError = self::lastError($session);

        if ($lastError !== null) {
            $lines[] = '## Last provider error';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $lastError;
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '## Doctor';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = Doctor::renderPlain(Doctor::inspect($session, $auth));
        $lines[] = '```';
        $lines[] = '';

        if ($includeTranscript) {
            $lines[] = '## Transcript';
            $lines[] = '';
            $lines[] = MarkdownExport::render($session->messages(), $session->cwd());
        }

        return implode("\n", $lines) . "\n";
    }

    /** Write the report down and answer where it went. */
    public static function write(string $report): string
    {
        $directory = Config::home() . '/bug-reports';

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}");
        }

        $path = $directory . '/bug-' . date('Y-m-d-His') . '.md';

        if (file_put_contents($path, $report) === false) {
            throw new \RuntimeException("Could not write {$path}");
        }

        return $path;
    }

    /** A GitHub "new issue" link with the title and the start of the report prefilled. */
    public static function issueUrl(string $hint, string $report): string
    {
        $title = $hint !== '' ? mb_substr($hint, 0, 100) : 'Bug report from /bug';
        $body = strlen($report) > self::MAX_URL_BODY
            ? substr($report, 0, self::MAX_URL_BODY) . "\n\n_(report truncated — the full file is attached below)_"
            : $report;

        return self::ISSUES_URL . '?title=' . rawurlencode($title) . '&body=' . rawurlencode($body);
    }

    /** The newest assistant turn that ended in an error, as the provider worded it. */
    public static function lastError(AgentSession $session): ?string
    {
        foreach (array_reverse($session->messages()) as $message) {
            if ($message instanceof AssistantMessage && $message->stopReason === StopReason::Error) {
                return $message->errorMessage ?? 'Error';
            }
        }

        return null;
    }

    /**
     * Whether an error is worth suggesting `/bug` for.
     *
     * Upstream's `maybeSuggestBugReport`: not a retryable one (busy providers are not bugs),
     * and not an abort (the person did that). A quota wall is retryable and so is left out.
     */
    public static function worthReporting(AssistantMessage $message, ?int $contextWindow = null): bool
    {
        if ($message->stopReason !== StopReason::Error) {
            return false;
        }

        if (Session\Retry::worthRetrying($message, $contextWindow)) {
            return false;
        }

        return preg_match('/\b(?:abort(?:ed)?|cancel(?:l?ed)?)\b/i', $message->errorMessage ?? '') !== 1;
    }
}
