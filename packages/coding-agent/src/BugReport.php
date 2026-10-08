<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
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
 * key), the last provider error, the recent crashes `CrashLog` wrote down, and `/doctor`'s
 * findings. The transcript is opt-in, because it holds whatever the model read. `write()` clears
 * the crash log, as upstream does once a report is out: they have been handed over.
 */
final class BugReport
{
    public const string ISSUES_URL = 'https://github.com/owner888/pig/issues/new';
    public const string SERVER_ENDPOINT = 'https://pigagent.dev/api/bug-reports';

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

        $failed = self::lastFailure($session);

        if ($failed !== null) {
            $lines[] = '## Last provider error';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $failed->errorMessage ?? 'Error';
            $lines[] = '```';
            $lines[] = '';

            // Upstream's per-message summary carries `rawStopReason` when the message has one; this
            // report has no per-message summary, so it goes with the one message it does describe.
            // It is what tells a Gemini `MALFORMED_FUNCTION_CALL` from a `SAFETY` without the
            // transcript, which is opt-in.
            if ($failed->rawStopReason !== null) {
                $lines[] = "- Raw stop reason: `{$failed->rawStopReason}`";
                $lines[] = '';
            }
        }

        $diagnosed = self::diagnosed($session);

        if ($diagnosed !== []) {
            // Upstream's `diagnostics.json` lists every assistant message that carries any, failed
            // or not, with the diagnostics whole and none of the conversation. Here it is the same
            // list, as Markdown, because the report is one Markdown file.
            $lines[] = '## Provider diagnostics';
            $lines[] = '';

            foreach ($diagnosed as $message) {
                $lines[] = "- {$message->provider}/{$message->model} ({$message->stopReason->value}):";

                foreach ($message->diagnostics ?? [] as $diagnostic) {
                    $error = $diagnostic->error === null ? '' : ': ' . $diagnostic->error->message;
                    $details = $diagnostic->details === null
                        ? ''
                        : ' ' . json_encode($diagnostic->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $lines[] = "  - `{$diagnostic->type}`{$error}{$details}";
                }
            }

            $lines[] = '';
        }

        $crashes = CrashLog::read();

        if ($crashes !== []) {
            $lines[] = '## Recent crashes';
            $lines[] = '';

            foreach (array_reverse($crashes) as $crash) {
                $lines[] = "### {$crash['timestamp']} — {$crash['kind']} (pig {$crash['version']})";
                $lines[] = '';
                $lines[] = '```';
                $lines[] = $crash['stack'] ?? $crash['message'];
                $lines[] = '```';
                $lines[] = '';
            }
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

        CrashLog::clear();

        return $path;
    }

    /**
     * Upload the bug report to pigagent.dev server.
     *
     * Returns the web report URL (e.g. `https://pigagent.dev/bug-report/xxxx`) if successful,
     * or null if the server was unreachable or rejected the submission.
     */
    public static function upload(string $hint, string $report, ?HttpClient $http = null): ?string
    {
        try {
            $client = $http ?? new HttpClient(timeout: 10.0);
            $payload = json_encode([
                'title' => $hint !== '' ? mb_substr($hint, 0, 100) : 'Bug Report',
                'report' => $report,
                'version' => Version::current(),
            ], JSON_UNESCAPED_SLASHES);

            $req = new Request(
                'POST',
                self::SERVER_ENDPOINT,
                [
                    'content-type' => 'application/json',
                    'content-length' => (string) strlen($payload),
                    'user-agent' => 'pig/' . Version::current(),
                ],
                $payload,
            );

            $resp = $client->send($req);
            if ($resp->status !== 200) {
                return null;
            }

            $chunks = [];
            foreach ($resp->body as $chunk) {
                $chunks[] = $chunk;
            }
            $data = json_decode(implode('', $chunks), true);

            return is_array($data) && !empty($data['url']) ? (string) $data['url'] : null;
        } catch (\Throwable) {
            return null;
        }
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
        $failed = self::lastFailure($session);

        return $failed === null ? null : ($failed->errorMessage ?? 'Error');
    }

    private static function lastFailure(AgentSession $session): ?AssistantMessage
    {
        foreach (array_reverse($session->messages()) as $message) {
            if ($message instanceof AssistantMessage && $message->stopReason === StopReason::Error) {
                return $message;
            }
        }

        return null;
    }

    /** @return list<AssistantMessage> the assistant messages that carry diagnostics, oldest first */
    private static function diagnosed(AgentSession $session): array
    {
        return array_values(array_filter(
            $session->messages(),
            static fn (mixed $message): bool => $message instanceof AssistantMessage
                && ($message->diagnostics ?? []) !== [],
        ));
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
