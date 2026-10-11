<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Agent\AgentError;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Retry;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\Compaction;

/**
 * Upstream's `/bug` (`core/bug-report.ts`, `core/bug-report-upload.ts`, `utils/zip.ts`), with pig's
 * own report and its own server.
 *
 * The interactive flow is upstream's (`InteractiveMode::reportBug()`): what went wrong, whether
 * to include the transcript, a summary written by the current model instead when it is not
 * (`generateSummary()`), then **Upload Report** or **Export as Zip** — and when an upload fails,
 * the zip is offered in its place.
 *
 * What differs is the bundle. Upstream uploads `report.json` / `diagnostics.json` to its Radius
 * gateway; pig's report is one Markdown document (`build()`) and goes to the developer's own
 * endpoint (`SERVER_ENDPOINT`). The zip holds that same document as `report.md`, the transcript as
 * `session.jsonl` when it was included, and `summary.md` when one was written.
 *
 * What goes in follows upstream's disclaimer: version, OS, PHP, the model and provider (never a
 * key), the last provider error, the recent crashes `CrashLog` wrote down, and `/doctor`'s
 * findings. The transcript is opt-in, because it holds whatever the model read. The crash log is
 * cleared once a report is out, as upstream clears it: they have been handed over.
 *
 * `write()` and `issueUrl()` are RPC's `bug` (a pig command upstream has not got): a file
 * under `~/.pig/agent/bug-reports/` and a GitHub "new issue" link.
 */
final class BugReport
{
    public const string ISSUES_URL = 'https://github.com/owner888/pig/issues/new';
    public const string SERVER_ENDPOINT = 'https://pigagent.dev/api/bug-reports';

    /** The session entry a sent or exported report leaves behind — upstream's `pi.bug-report`, renamed. */
    public const string CUSTOM_ENTRY_TYPE = 'pig.bug-report';

    /** Upstream's `DISCLAIMER`, about pig and its developers. */
    public const string DISCLAIMER = 'This report goes to the pig developers and is not shared publicly. It includes your pig version, operating system, the current model and provider configuration (without API keys), and provider error diagnostics from this session.';

    /** Upstream's `TRANSCRIPT_NOTE`. */
    public const string TRANSCRIPT_NOTE = 'The transcript contains your messages, model output, tool calls and their results, including file contents and command output read during this session.';

    /** GitHub refuses a URL much past this, so the prefilled body is cut to fit. */
    private const int MAX_URL_BODY = 6000;

    /** Upstream's `BUG_SUMMARY_SYSTEM_PROMPT`. */
    private const string SUMMARY_SYSTEM_PROMPT = <<<'TEXT'
        You are helping a user file a bug report about pig, the coding agent they are talking to. You will be shown the conversation transcript. Write a report for the pig developers describing what the user was doing and what went wrong.

        Do NOT continue the conversation. Do NOT respond to any questions in the conversation. ONLY output the report.
        TEXT;

    /** Upstream's `BUG_SUMMARY_INSTRUCTIONS`. */
    private const string SUMMARY_INSTRUCTIONS = <<<'TEXT'
        Write the bug report in Markdown with these sections:

        ## What the user was doing
        One short paragraph.

        ## What went wrong
        Concrete description of the failure: wrong output, errors, hangs, tool failures, unexpected behavior. Quote error messages and tool output verbatim where they exist.

        ## Steps to reproduce
        Numbered list, as specific as the transcript allows.

        ## Relevant details
        Tool calls involved, files touched, model behavior, anything else that helps a developer reproduce or locate the problem.

        Do not include file contents, secrets, or credentials from the transcript; refer to files by path only. Keep the report factual and concise.
        TEXT;

    /**
     * @param string|null $summary what the current model wrote in place of the transcript, when it was asked to
     */
    public static function build(AgentSession $session, ?Auth $auth, string $hint, bool $includeTranscript, ?string $summary = null): string
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

        if ($summary !== null) {
            $lines[] = '## Summary written by ' . ($model === null ? 'the current model' : $model->name);
            $lines[] = '';
            $lines[] = rtrim($summary);
            $lines[] = '';
        }

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
     * Send the report to the developer's server and answer the report's URL there — upstream's
     * `uploadBugReport()`, to pig's endpoint.
     *
     * @throws \RuntimeException with what went wrong — the server's status, its own `error`, or
     *         the transport's failure — which the caller offers the zip export for
     */
    public static function upload(string $hint, string $report, ?HttpClient $http = null, ?AbortSignal $signal = null): string
    {
        $client = $http ?? new HttpClient(timeout: 30.0);
        $payload = (string) json_encode([
            'title' => $hint !== '' ? mb_substr($hint, 0, 100) : 'Bug Report',
            'report' => $report,
            'version' => Version::current(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $request = new Request(
            'POST',
            self::SERVER_ENDPOINT,
            [
                'content-type' => 'application/json',
                'content-length' => (string) strlen($payload),
                'user-agent' => 'pig/' . Version::current(),
            ],
            $payload,
        );

        try {
            $response = $client->send($request, $signal);
            $body = '';

            foreach ($response->body as $chunk) {
                $body .= $chunk;
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('Could not reach ' . parse_url(self::SERVER_ENDPOINT, PHP_URL_HOST) . ': ' . $error->getMessage(), 0, $error);
        }

        $data = json_decode($body, true);

        if ($response->status !== 200) {
            $reason = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : "HTTP {$response->status}";

            throw new \RuntimeException("The server refused the report: {$reason}");
        }

        if (!is_array($data) || !is_string($data['url'] ?? null) || $data['url'] === '') {
            throw new \RuntimeException('The server accepted the report but did not say where it is.');
        }

        return $data['url'];
    }

    /** Upstream's `bugReportArchiveFileName()`. */
    public static function archiveFileName(string $id): string
    {
        return "pig-bug-report-{$id}.zip";
    }

    /**
     * The files of the zip export, by name — upstream's `bugReportFiles()`, with pig's report.
     *
     * @return array<string, string>
     */
    public static function archiveFiles(string $report, ?string $sessionJsonl, ?string $summary): array
    {
        $files = ['report.md' => $report];

        if ($sessionJsonl !== null) {
            $files['session.jsonl'] = $sessionJsonl;
        }

        if ($summary !== null) {
            $files['summary.md'] = str_ends_with($summary, "\n") ? $summary : "{$summary}\n";
        }

        return $files;
    }

    /**
     * Upstream's `writeZipArchive()`: a small classic ZIP, written by hand.
     *
     * Stored rather than deflated, so it needs no zlib (pig does not require the extension); a bug
     * report is a few hundred kilobytes at most. Names are flagged UTF-8 (bit 11), as upstream's are.
     *
     * @param array<string, string> $files name => contents
     * @throws \RuntimeException when the file cannot be written
     */
    public static function writeArchive(string $path, array $files): void
    {
        $time = (int) date('G') << 11 | (int) date('i') << 5 | intdiv((int) date('s'), 2);
        $day = (max(1980, (int) date('Y')) - 1980) << 9 | (int) date('n') << 5 | (int) date('j');
        $body = '';
        $directory = '';

        foreach ($files as $name => $data) {
            $name = (string) $name;
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($body);

            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $day, $crc, $size, $size, strlen($name), 0) . $name . $data;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $day, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }

        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);

        if (file_put_contents($path, $body . $directory . $end) === false) {
            throw new \RuntimeException("Could not write {$path}");
        }
    }

    /**
     * Ask the model for a report when the person does not share the transcript — upstream's
     * `generateBugReportSummary()`.
     *
     * The newest messages that fit in 60% of the context window (at least one), the hint, and
     * upstream's instructions; `Compaction::completeSummarization()` sends it, so it goes the way a
     * compaction does — no cache writes, the session's stream function and retry policy.
     *
     * @param list<mixed> $messages the session's messages
     * @param (Closure(Model, \Pig\Ai\TranscriptContext, SimpleStreamOptions): mixed)|null $streamFn
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $retry
     * @throws AgentError when it was cancelled, failed, called a tool, or came back empty
     */
    public static function generateSummary(
        array $messages,
        ?string $hint,
        Model $model,
        ?string $apiKey,
        ?AbortSignal $signal = null,
        ?Closure $streamFn = null,
        ?array $retry = null,
        ?string $sessionId = null,
    ): string {
        $contextWindow = $model->contextWindow > 0 ? $model->contextWindow : 128_000;
        $budget = (int) floor($contextWindow * 0.6);
        $selected = [];
        $tokens = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $next = Compaction::estimateTokens($messages[$i]);

            if ($selected !== [] && $tokens + $next > $budget) {
                break;
            }

            array_unshift($selected, $messages[$i]);
            $tokens += $next;
        }

        $hint = $hint === null ? '' : trim($hint);
        $parts = [];

        if (count($selected) < count($messages)) {
            $parts[] = 'Note: only the last ' . count($selected) . ' of ' . count($messages) . ' messages are shown.';
        }

        $parts[] = "<conversation>\n" . Compaction::serializeConversation(CodingAgent::toLlm($selected)) . "\n</conversation>";

        if ($hint !== '') {
            $parts[] = "<user-report>\n{$hint}\n</user-report>";
        }

        $parts[] = self::SUMMARY_INSTRUCTIONS;

        $response = Compaction::completeSummarization(
            $model,
            Transcript::normalizeContext(new Context([new UserMessage([new TextContent(implode("\n\n", $parts))])], self::SUMMARY_SYSTEM_PROMPT)),
            new SimpleStreamOptions(
                maxTokens: $model->maxTokens > 0 ? min(4096, $model->maxTokens) : 4096,
                signal: $signal,
                apiKey: $apiKey,
                sessionId: $sessionId,
            ),
            $streamFn,
            $retry,
        );

        if ($response->stopReason === StopReason::Aborted) {
            throw new AgentError('Bug report summary was cancelled');
        }

        $failure = Compaction::getSummarizationFailure($response, 'Bug report summary');

        if ($failure !== null) {
            throw new AgentError($failure);
        }

        foreach ($response->content as $block) {
            if ($block instanceof ToolCall) {
                throw new AgentError('Bug report summary attempted to call a tool');
            }
        }

        $text = trim(Text::contentText($response->content));

        if ($text === '') {
            throw new AgentError('Bug report summary was empty');
        }

        return $text;
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
     * Upstream's `maybeSuggestBugReport`: not a retryable one (`isRetryableAssistantError()` —
     * busy providers are not bugs), and not an abort (the person did that).
     */
    public static function worthReporting(AssistantMessage $message): bool
    {
        // A quota wall is a limit, not a bug: upstream counts it among the retryable carve-outs,
        // pig classifies it on its own (`isProviderLimitError`) so a fallback model can take over.
        if (
            $message->stopReason !== StopReason::Error
            || Retry::isRetryableAssistantError($message)
            || Retry::isProviderLimitError($message)
        ) {
            return false;
        }

        return preg_match('/\b(?:abort(?:ed)?|cancel(?:l?ed)?)\b/i', $message->errorMessage ?? '') !== 1;
    }
}
