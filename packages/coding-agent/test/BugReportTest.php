<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\BugReport;
use Pig\CodingAgent\Session\AgentSession;

final class BugReportTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-bug-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0700, true);
        putenv("PIG_CODING_AGENT_DIR={$this->home}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_CODING_AGENT_DIR');
    }

    private function assistant(string $text, StopReason $stop, ?string $error = null, ?string $raw = null): AssistantMessage
    {
        return new AssistantMessage(
            $text === '' ? [] : [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-6',
            new Usage(),
            $stop,
            $error,
            null,
            $raw,
        );
    }

    private function session(array $messages): AgentSession
    {
        $session = new AgentSession(new Agent(new AgentOptions(apiKey: 'k')), $this->home);
        $session->restore($messages);

        return $session;
    }

    public function testTheReportCarriesTheEnvironmentTheLastErrorAndNoKey(): void
    {
        $session = $this->session([
            new UserMessage('do the thing'),
            $this->assistant('', StopReason::Error, 'Anthropic returned 400: thinking.type.enabled is not supported'),
        ]);

        $report = BugReport::build($session, Auth::inMemory(), 'it exploded', includeTranscript: false);

        $this->assertStringContainsString('# pig bug report', $report);
        $this->assertStringContainsString('it exploded', $report);
        $this->assertStringContainsString('- PHP: ' . PHP_VERSION, $report);
        $this->assertStringContainsString('thinking.type.enabled is not supported', $report);
        $this->assertStringContainsString('[1. PHP Runtime & Extensions]', $report);
        $this->assertStringNotContainsString('## Transcript', $report);
        $this->assertStringNotContainsString('sk-ant', $report);
    }

    public function testTheLastErrorCarriesTheProvidersRawStopReasonWhenItHasOne(): void
    {
        // Upstream's bug report puts `rawStopReason` in its per-message summary. pig's report has
        // no such summary and the transcript is opt-in, so the reason rides with the error it
        // explains — which is the only place a reader would look for it.
        $session = $this->session([
            new UserMessage('do the thing'),
            $this->assistant('', StopReason::Error, 'Provider stopped with: MALFORMED_FUNCTION_CALL', 'MALFORMED_FUNCTION_CALL'),
        ]);

        $report = BugReport::build($session, Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringContainsString('- Raw stop reason: `MALFORMED_FUNCTION_CALL`', $report);

        // And nothing at all for an error that came with none, rather than an empty line.
        $plain = BugReport::build($this->session([
            $this->assistant('', StopReason::Error, 'Anthropic returned 400: bad request'),
        ]), Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringNotContainsString('Raw stop reason', $plain);
    }

    public function testDiagnosticsOnAnyAssistantMessageAreListedWithoutTheConversation(): void
    {
        // Upstream's `diagnostics.json` collects every assistant message that carries
        // diagnostics — a turn that succeeded included, since a recovery is worth reporting — and
        // none of the text. Here they are a section of the one report.
        $diagnosed = new AssistantMessage(
            [new TextContent('the secret answer')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-6',
            new Usage(),
            StopReason::Stop,
            null,
            null,
            'end_turn',
            diagnostics: [new AssistantMessageDiagnostic(
                'anthropic_input_transformations',
                1_700_000_000_000,
                details: ['transformations' => [['type' => 'drop', 'path' => 'messages.0']]],
            )],
        );

        $report = BugReport::build($this->session([new UserMessage('hi'), $diagnosed]), Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringContainsString('## Provider diagnostics', $report);
        $this->assertStringContainsString('- anthropic/claude-sonnet-4-6 (stop):', $report);
        $this->assertStringContainsString('`anthropic_input_transformations` {"transformations":[{"type":"drop","path":"messages.0"}]}', $report);
        $this->assertStringNotContainsString('the secret answer', $report);

        // And no section at all when nothing carried any.
        $plain = BugReport::build($this->session([$this->assistant('ok', StopReason::Stop)]), Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringNotContainsString('Provider diagnostics', $plain);
    }

    public function testTheTranscriptIsOptInAndIsTheMarkdownExport(): void
    {
        $session = $this->session([
            new UserMessage('hello there'),
            $this->assistant('general kenobi', StopReason::Stop),
        ]);

        $with = BugReport::build($session, Auth::inMemory(), '', includeTranscript: true);
        $without = BugReport::build($session, Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringContainsString('## Transcript', $with);
        $this->assertStringContainsString('hello there', $with);
        $this->assertStringNotContainsString('hello there', $without);
    }

    public function testTheReportIsWrittenUnderPigsHomeAndTheIssueUrlIsPrefilled(): void
    {
        $path = BugReport::write("# pig bug report\n\nbody\n");

        // `PIG_CODING_AGENT_DIR` *is* the agent directory, so there is no `/agent` in between.
        $this->assertStringStartsWith($this->home . '/bug-reports/bug-', $path);
        $this->assertFileExists($path);

        $url = BugReport::issueUrl('footer is crooked', "# pig bug report\n\nbody\n");

        $this->assertStringStartsWith(BugReport::ISSUES_URL . '?title=footer%20is%20crooked&body=', $url);
        $this->assertStringContainsString(rawurlencode('# pig bug report'), $url);
    }

    public function testRecentCrashesAreAttachedAndHandedOverOnceWritten(): void
    {
        \Pig\CodingAgent\CrashLog::record('loop_error', new \RuntimeException('Grapheme split failed'), null, $this->home);

        $session = $this->session([new UserMessage('x'), $this->assistant('y', StopReason::Stop)]);
        $report = BugReport::build($session, Auth::inMemory(), '', includeTranscript: false);

        $this->assertStringContainsString('## Recent crashes', $report);
        $this->assertStringContainsString('loop_error', $report);
        $this->assertStringContainsString('RuntimeException: Grapheme split failed', $report);

        BugReport::write($report);

        // Attached to a report that is out, so they are not attached to the next one as well.
        $this->assertSame([], \Pig\CodingAgent\CrashLog::read());
        $this->assertStringNotContainsString('## Recent crashes', BugReport::build($session, Auth::inMemory(), '', false));
    }

    public function testTheHintFiresForARealErrorAndNotForAQuotaWallOrAnAbort(): void
    {
        $this->assertTrue(BugReport::worthReporting(
            $this->assistant('', StopReason::Error, 'Anthropic returned 400: bad request'),
        ));

        // A 429 is a busy provider, which is upstream's `isRetryableAssistantError` carve-out.
        $this->assertFalse(BugReport::worthReporting(
            $this->assistant('', StopReason::Error, 'Antigravity returned 429: Quota reached. Please wait 24m25s.'),
        ));

        $this->assertFalse(BugReport::worthReporting(
            $this->assistant('', StopReason::Error, 'Operation aborted'),
        ));

        $this->assertFalse(BugReport::worthReporting(
            $this->assistant('fine', StopReason::Stop),
        ));
    }

    public function testUploadSaysWhyWhenTheServerCannotBeReached(): void
    {
        // Not a null the caller has to guess about: the reason is what the "Upload failed" dialog
        // shows before it offers the zip.
        $http = new \Pig\Ai\Http\HttpClient(timeout: 0.05);

        $this->expectException(\RuntimeException::class);
        BugReport::upload('test hint', '# report', $http);
    }

    public function testASummaryIsItsOwnSectionNamingTheModel(): void
    {
        $report = BugReport::build($this->session([new UserMessage('hi')]), Auth::inMemory(), '', false, "## What went wrong\nit broke");

        $this->assertStringContainsString("## Summary written by", $report);
        $this->assertStringContainsString("## What went wrong\nit broke", $report);
        $this->assertStringNotContainsString('## Summary', BugReport::build($this->session([]), Auth::inMemory(), '', false));
    }

    public function testTheZipHoldsTheReportTheTranscriptAndTheSummary(): void
    {
        $files = BugReport::archiveFiles("# pig bug report\n", "{\"type\":\"session\"}\n", 'a summary');
        $this->assertSame(['report.md', 'session.jsonl', 'summary.md'], array_keys($files));
        $this->assertSame("a summary\n", $files['summary.md']);
        $this->assertSame(['report.md'], array_keys(BugReport::archiveFiles('r', null, null)));

        $path = "{$this->home}/" . BugReport::archiveFileName('0199-id');
        $this->assertStringEndsWith('/pig-bug-report-0199-id.zip', $path);
        BugReport::writeArchive($path, $files);

        // Read back by something that is not the writer: `unzip`, when this machine has it.
        $unzip = \Pig\CodingAgent\Cli\SessionList::onPath('unzip');

        if ($unzip === null) {
            $this->assertStringStartsWith("PK\x03\x04", (string) file_get_contents($path));

            return;
        }

        exec(escapeshellarg($unzip) . ' -p ' . escapeshellarg($path) . ' summary.md', $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(['a summary'], $out);
        exec(escapeshellarg($unzip) . ' -t ' . escapeshellarg($path), $test, $code);
        $this->assertSame(0, $code, implode("\n", $test));
    }

    public function testTheSummaryIsAskedOfTheModelWithTheHintAndUpstreamsInstructions(): void
    {
        $asked = [];
        $streamFn = static function (\Pig\Ai\Model $model, \Pig\Ai\TranscriptContext $context, \Pig\Ai\SimpleStreamOptions $options) use (&$asked): \Pig\Ai\Utils\AssistantMessageEventStream {
            $asked[] = [$context, $options];
            $message = new AssistantMessage([new TextContent("  ## What went wrong\nthe footer  ")], Api::AnthropicMessages, 'anthropic', 'm', new Usage(), StopReason::Stop);
            $stream = new \Pig\Ai\Utils\AssistantMessageEventStream();
            \Pig\Async\Async::spawn(static function () use ($stream, $message): void {
                $stream->push(new \Pig\Ai\DoneEvent(StopReason::Stop, $message));
                $stream->end();
            });

            return $stream;
        };
        $model = new \Pig\Ai\Model('m', 'Model M', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000);

        $summary = \Pig\Async\Async::run(static fn (): string => BugReport::generateSummary(
            [new UserMessage('why is the footer crooked')],
            'footer',
            $model,
            'key',
            streamFn: $streamFn,
        ));

        $this->assertSame("## What went wrong\nthe footer", $summary);
        [$context, $options] = $asked[0];
        $said = array_map(static fn (mixed $m): string => is_string($m->content) ? $m->content : \Pig\Ai\Utils\Text::contentText($m->content), $context->messages);
        $this->assertStringContainsString('bug report about pig', $said[0], 'the system prompt is the summariser\'s own');
        $prompt = (string) end($said);
        $this->assertStringContainsString('why is the footer crooked', $prompt);
        $this->assertStringContainsString("<user-report>\nfooter\n</user-report>", $prompt);
        $this->assertStringContainsString('## Steps to reproduce', $prompt);
        $this->assertSame(4096, $options->maxTokens);
    }
}
