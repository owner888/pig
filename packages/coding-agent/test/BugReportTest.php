<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
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
        putenv("PIG_HOME={$this->home}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
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

        // `PIG_HOME` *is* the agent directory, so there is no `/agent` in between.
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

    public function testUploadAnswersNullWhenServerRejectsOrFails(): void
    {
        $http = new \Pig\Ai\Http\HttpClient(timeout: 0.05);
        $this->assertNull(BugReport::upload('test hint', '# report', $http));
    }
}
