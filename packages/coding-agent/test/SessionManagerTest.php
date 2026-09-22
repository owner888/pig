<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Test\AssertsThrows;

/** The conversation on disk, and reading it back. */
final class SessionManagerTest extends TestCase
{
    use AssertsThrows;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-sessions-' . bin2hex(random_bytes(4));
        putenv('PIG_HOME=' . $this->home);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        self::remove($this->home);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function answer(string $text = 'here you go', StopReason $stop = StopReason::Stop): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(10, 20, 30, 40, 100, new Cost(total: 0.25)),
            $stop,
        );
    }

    // ---- when a file appears ----------------------------------------------------------

    public function testNothingIsWrittenUntilSomethingHasBeenAnswered(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('are you there'));

        // Someone who starts pig, reads the banner and quits leaves nothing behind —
        // which is what makes the sessions directory worth opening at all.
        $this->assertFileDoesNotExist($session->path);
    }

    public function testTheQuestionIsWrittenInFrontOfTheAnswerThatTriggeredIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('are you there'));
        $session->append($this->answer());

        $lines = file($session->path, FILE_IGNORE_NEW_LINES);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"type":"session"', $lines[0]);
        $this->assertStringContainsString('are you there', $lines[1]);
        $this->assertStringContainsString('here you go', $lines[2]);
    }

    public function testEachLaterMessageIsAppendedRatherThanRewritten(): void
    {
        // Appended, so a session survives whatever ends the process — a crash, a closed
        // laptop, Ctrl+C twice.
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $before = filesize($session->path);

        $session->append(new UserMessage('two'));

        $this->assertGreaterThan((int) $before, filesize($session->path));
    }

    // ---- what survives the trip ---------------------------------------------------------

    public function testEveryKindOfMessageComesBackAsItWent(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('a question'));
        $session->append(new AssistantMessage(
            [new ThinkingContent('let me look', 'sig'), new TextContent('found it'), new ToolCall('c1', 'read', ['path' => 'a.php'])],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(1, 2, 3, 4, 10, new Cost(0.1, 0.2, 0.3, 0.4, 1.0)),
            StopReason::ToolUse,
        ));
        $session->append(new ToolResultMessage('c1', 'read', [new TextContent('<?php')], false, ['diff' => "-1 a\n+1 b"]));
        $session->append(new BashExecution('ls', "a\nb", 0));

        $back = SessionManager::open($session->path)->messages();

        $this->assertCount(4, $back);

        $assistant = $back[1];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertSame('let me look', $assistant->content[0]->thinking);
        $this->assertSame('sig', $assistant->content[0]->thinkingSignature);
        $this->assertSame('read', $assistant->content[2]->name);
        $this->assertSame(['path' => 'a.php'], $assistant->content[2]->arguments);
        $this->assertSame(1, $assistant->usage->input);
        $this->assertSame(1.0, $assistant->usage->cost->total);
        $this->assertSame(StopReason::ToolUse, $assistant->stopReason);

        // The one thing anything reads out of a tool's details is the edit diff.
        $this->assertSame("-1 a\n+1 b", $back[2]->details['diff']);

        $bash = $back[3];
        $this->assertInstanceOf(BashExecution::class, $bash);
        $this->assertSame('ls', $bash->command);
        $this->assertSame(0, $bash->exitCode);
    }

    public function testAnImageSurvivesToo(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage([new TextContent('look'), new ImageContent('BASE64', 'image/png')]));
        $session->append($this->answer());

        $back = SessionManager::open($session->path)->messages()[0];

        $this->assertInstanceOf(ImageContent::class, $back->content[1]);
        $this->assertSame('image/png', $back->content[1]->mimeType);
    }

    public function testUnicodeIsWrittenAsItselfAndComesBackWhole(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('你好 — 中文注释'));
        $session->append($this->answer('好的'));

        // Readable in the file as well as after decoding: a session log is something
        // someone greps.
        $this->assertStringContainsString('你好 — 中文注释', (string) file_get_contents($session->path));
        $this->assertSame('你好 — 中文注释', SessionManager::open($session->path)->messages()[0]->content[0]->text);
    }

    public function testAnErrorTurnKeepsItsMessage(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('go'));
        $session->append(new AssistantMessage(
            [],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(),
            StopReason::Error,
            'overloaded_error',
        ));

        $back = SessionManager::open($session->path)->messages()[1];

        $this->assertSame(StopReason::Error, $back->stopReason);
        $this->assertSame('overloaded_error', $back->errorMessage);
    }

    // ---- finding one again ----------------------------------------------------------------

    public function testSessionsAreListedNewestFirstAndLabelledByWhatWasAsked(): void
    {
        foreach (['the first thing', 'the second thing'] as $index => $said) {
            $session = SessionManager::create('/some/project', $this->home . "/sessions/some-project/2026-01-0{$index}-000000-x{$index}.jsonl");
            $session->append(new UserMessage($said));
            $session->append($this->answer());
        }

        $listed = SessionManager::listFor('/some/project');

        $this->assertCount(2, $listed);
        // The first thing the person said is the label, because that is how anyone
        // remembers a conversation.
        $this->assertSame('the second thing', $listed[0]->opening);
        $this->assertSame(2, $listed[0]->messages);
    }

    public function testEachProjectHasItsOwnDirectory(): void
    {
        $this->assertNotSame(
            SessionManager::directory('/project/one'),
            SessionManager::directory('/project/two'),
        );

        // Named after the path, so opening the directory says which project it is.
        $this->assertStringContainsString('project-one', SessionManager::directory('/project/one'));
    }

    public function testAnotherProjectsSessionsAreNotListedHere(): void
    {
        $session = SessionManager::create('/project/one');
        $session->append(new UserMessage('theirs'));
        $session->append($this->answer());

        $this->assertSame([], SessionManager::listFor('/project/two'));
        $this->assertNull(SessionManager::latestFor('/project/two'));
    }

    public function testAFileThatIsNotASessionIsRefusedRatherThanHalfRead(): void
    {
        $path = $this->home . '/not-a-session.jsonl';
        mkdir($this->home, 0o700, true);
        file_put_contents($path, "just some text\n");

        $this->assertThrows(
            AgentError::class,
            static fn () => SessionManager::open($path),
            'Not a pig session file',
        );
    }

    public function testAMissingFileSaysSo(): void
    {
        $this->assertThrows(
            AgentError::class,
            fn () => SessionManager::open($this->home . '/nowhere.jsonl'),
            'Could not read',
        );
    }

    public function testALineWrittenBySomethingNewerIsSkippedRatherThanFatal(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());

        file_put_contents($session->path, json_encode(['role' => 'somethingElse', 'x' => 1]) . "\n", FILE_APPEND);

        // A session written by a later pig should still open in this one, minus whatever
        // it did not recognise.
        $this->assertCount(2, SessionManager::open($session->path)->messages());
    }

    public function testTheTimeAgoReadsTheWayPeopleSayIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());

        $info = SessionManager::listFor('/some/project')[0];
        $now = intdiv($info->timestamp, 1000);

        $this->assertSame('just now', $info->when($now));
        $this->assertSame('5m ago', $info->when($now + 300));
        $this->assertSame('3h ago', $info->when($now + 10_800));
        $this->assertSame('2d ago', $info->when($now + 172_800));
    }
}
