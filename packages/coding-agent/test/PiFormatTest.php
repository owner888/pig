<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\CustomEntry;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;

/**
 * The session file, byte for byte as pi writes one.
 *
 * pig used to flatten the message onto the line with `entryId` and `parent` and an integer
 * millisecond timestamp, while calling it `version: 2` — the same number pi uses for a
 * different shape, so pi would agree about the version and then find no `type` on any line.
 * These tests are the fence around that: every assertion here is a field name or a shape
 * taken from upstream's `core/session-manager.ts`, not from what pig happens to do.
 */
final class PiFormatTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-piformat-' . bin2hex(random_bytes(4));
        putenv('PIG_HOME=' . $this->home);
        putenv('PI_HOME=' . $this->home . '-pi');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('PI_HOME');
        self::remove($this->home);
        self::remove($this->home . '-pi');
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

    private function answer(string $text = 'here you go'): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );
    }

    /** @return list<array<string, mixed>> the file, decoded line by line */
    private static function linesOf(string $path): array
    {
        $lines = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, "not JSON: {$line}");
            $lines[] = $decoded;
        }

        return $lines;
    }

    // ---- the shape -----------------------------------------------------------------

    public function testTheHeaderIsPisHeader(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());

        $header = self::linesOf($session->path)[0];

        $this->assertSame('session', $header['type']);
        $this->assertSame(2, $header['version']);
        $this->assertSame('/some/project', $header['cwd']);

        // An ISO string, not milliseconds. pi parses it with `new Date(...)`.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $header['timestamp'],
        );

        // A UUID, as `randomUUID()` gives.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $header['id'],
        );
    }

    public function testAMessageIsWrappedInItsOwnEntry(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());

        [, $first, $second] = self::linesOf($session->path);

        // The envelope is the whole difference: pig used to put `role` at the top level.
        $this->assertSame('message', $first['type']);
        $this->assertSame('user', $first['message']['role']);
        $this->assertSame('hi', $first['message']['content'][0]['text']);

        $this->assertNull($first['parentId']);
        $this->assertSame($first['id'], $second['parentId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first['id']);

        // `id` and `parentId`, not `entryId` and `parent`.
        $this->assertArrayNotHasKey('entryId', $first);
        $this->assertArrayNotHasKey('parent', $first);
        $this->assertArrayNotHasKey('role', $first);
    }

    public function testEveryLineCarriesAnIsoTimestamp(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());

        foreach (self::linesOf($session->path) as $line) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
                $line['timestamp'],
                "not an ISO timestamp on a {$line['type']} line",
            );
        }
    }

    public function testACompactionIsItsOwnEntryTypeWithFirstKeptEntryId(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $session->append(new UserMessage('two'));
        $session->append($this->answer('again'));
        $session->append(new CompactionSummary('what happened', ['a.php'], [], 1_000, $session->entryAt(3)));

        $line = self::linesOf($session->path)[5];

        $this->assertSame('compaction', $line['type']);
        $this->assertSame('what happened', $line['summary']);
        $this->assertSame(1_000, $line['tokensBefore']);
        $this->assertIsString($line['firstKeptEntryId']);

        // pig's file lists ride in `details`, which is where pi puts hook data — a pi that
        // does not know about them carries them along untouched, which is the most a
        // foreign field can ask for.
        $this->assertSame(['a.php'], $line['details']['readFiles']);

        // Never the count: that is derived when the file is read.
        $this->assertArrayNotHasKey('replaced', $line);
    }

    public function testAHooksMessageIsACustomMessageEntry(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());
        $session->append(new HookMessage('build', [new TextContent('broken')], display: false));

        $line = self::linesOf($session->path)[3];

        $this->assertSame('custom_message', $line['type']);
        $this->assertSame('build', $line['customType']);
        $this->assertFalse($line['display']);
        $this->assertSame('broken', $line['content'][0]['text']);
    }

    public function testAHooksNoteIsACustomEntryInTheTree(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());
        $session->appendCustomEntry('permissions', ['level' => 'full']);

        $line = self::linesOf($session->path)[3];

        $this->assertSame('custom', $line['type']);
        $this->assertSame('permissions', $line['customType']);
        $this->assertSame(['level' => 'full'], $line['data']);

        // In the tree like everything else — it has a parent. pig kept these outside it at
        // first, which wrote a line pi could not place.
        $this->assertIsString($line['parentId']);
    }

    public function testABashExecutionIsAMessageLikeAnyOther(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());
        $session->append(new BashExecution('ls', 'a.php', 0));

        $line = self::linesOf($session->path)[3];

        // Upstream's `appendMessage()` takes a `BashExecutionMessage`, so it goes inside
        // the envelope rather than beside it.
        $this->assertSame('message', $line['type']);
        $this->assertSame('bashExecution', $line['message']['role']);
        $this->assertSame('ls', $line['message']['command']);
    }

    // ---- the names ------------------------------------------------------------------

    public function testTheDirectoryIsNamedThePiWay(): void
    {
        // `--` around the flattened path, every separator a dash, the leading one gone.
        $this->assertStringEndsWith(
            '/sessions/--Users-kaka-Development-owner-pig--',
            SessionManager::directory('/Users/kaka/Development/owner/pig'),
        );
    }

    public function testAWindowsPathFlattensToo(): void
    {
        // pi replaces `:` as well as both separators, and does not collapse runs — so
        // `C:\` becomes two dashes, not one. Asserted as pi produces it rather than as it
        // would look tidier, because the whole point is that both tools name it the same.
        $this->assertStringEndsWith(
            '/sessions/--C--Users-kaka-pig--',
            SessionManager::directory('C:\\Users\\kaka\\pig'),
        );
    }

    public function testTheFileIsNamedThePiWay(): void
    {
        $session = SessionManager::create('/some/project');

        // `2026-01-02T21-29-30-123Z_<uuid>.jsonl`: the ISO stamp with its colons and dots
        // turned into dashes, then an underscore, then the session id.
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-\d{3}Z_[0-9a-f-]{36}\.jsonl$/',
            $session->path,
        );
    }

    // ---- reading pi's ---------------------------------------------------------------

    public function testAFileWrittenByPiOpens(): void
    {
        // Written by hand in pi's shape rather than by pig, so this asserts the reader and
        // not the writer's agreement with itself.
        $path = $this->home . '/hand-written.jsonl';
        mkdir(dirname($path), 0o755, true);

        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session', 'version' => 2, 'id' => 'abc', 'timestamp' => '2026-01-02T21:29:30.123Z', 'cwd' => '/p']),
            json_encode(['type' => 'message', 'id' => 'aaaaaaaa', 'parentId' => null, 'timestamp' => '2026-01-02T21:29:31.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'from pi']]]]),
            json_encode(['type' => 'thinking_level_change', 'id' => 'bbbbbbbb', 'parentId' => 'aaaaaaaa', 'timestamp' => '2026-01-02T21:29:32.000Z', 'thinkingLevel' => 'high']),
            json_encode(['type' => 'message', 'id' => 'cccccccc', 'parentId' => 'bbbbbbbb', 'timestamp' => '2026-01-02T21:29:33.000Z', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'answered by pi']], 'api' => 'anthropic-messages', 'provider' => 'anthropic', 'model' => 'claude', 'usage' => [], 'stopReason' => 'stop']]),
        ]) . "\n");

        $session = SessionManager::open($path);
        $messages = $session->messages();

        $this->assertCount(2, $messages);
        $this->assertSame('from pi', $messages[0]->content[0]->text);
        $this->assertSame('answered by pi', $messages[1]->content[0]->text);

        // `thinking_level_change` is pi's and pig has nothing that uses it. Skipped on the
        // way in — and because nothing rewrites a session file, left exactly where it is.
        $this->assertStringContainsString('thinking_level_change', (string) file_get_contents($path));
    }

    public function testAPiCompactionResolvesTheSameWay(): void
    {
        $path = $this->home . '/compacted.jsonl';
        mkdir(dirname($path), 0o755, true);

        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session', 'version' => 2, 'id' => 'abc', 'timestamp' => '2026-01-02T21:29:30.123Z', 'cwd' => '/p']),
            json_encode(['type' => 'message', 'id' => 'a1', 'parentId' => null, 'timestamp' => '2026-01-02T21:29:31.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'old one']]]]),
            json_encode(['type' => 'message', 'id' => 'a2', 'parentId' => 'a1', 'timestamp' => '2026-01-02T21:29:32.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'old two']]]]),
            json_encode(['type' => 'message', 'id' => 'a3', 'parentId' => 'a2', 'timestamp' => '2026-01-02T21:29:33.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'kept']]]]),
            json_encode(['type' => 'compaction', 'id' => 'a4', 'parentId' => 'a3', 'timestamp' => '2026-01-02T21:29:34.000Z', 'summary' => 'what pi summarised', 'firstKeptEntryId' => 'a3', 'tokensBefore' => 5]),
        ]) . "\n");

        $messages = SessionManager::open($path)->messages();

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(CompactionSummary::class, $messages[0]);
        $this->assertSame('what pi summarised', $messages[0]->summary);
        $this->assertSame(2, $messages[0]->replaced, 'two messages before the kept one');
        $this->assertSame('kept', $messages[1]->content[0]->text);
    }

    public function testAFilePigWroteBeforeThisStillOpens(): void
    {
        $path = $this->home . '/old-pig.jsonl';
        mkdir(dirname($path), 0o755, true);

        // The flat shape, with `entryId`/`parent` and millisecond timestamps. A session
        // file is a record of something that happened; old ones have to keep opening.
        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session', 'version' => 2, 'id' => 'abc', 'cwd' => '/p', 'timestamp' => 1_735_849_770_123]),
            json_encode(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'said long ago']], 'timestamp' => 1_735_849_770_200, 'entryId' => 'aaaaaaaaaaaa', 'parent' => null]),
        ]) . "\n");

        $messages = SessionManager::open($path)->messages();

        $this->assertCount(1, $messages);
        $this->assertSame('said long ago', $messages[0]->content[0]->text);
    }

    public function testAFileWithNoIdsAtAllStillReadsAsOneConversation(): void
    {
        $path = $this->home . '/older-pig.jsonl';
        mkdir(dirname($path), 0o755, true);

        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session', 'version' => 1, 'id' => 'abc', 'cwd' => '/p', 'timestamp' => 1_735_849_770_123]),
            json_encode(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'one']], 'timestamp' => 1]),
            json_encode(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'two']], 'timestamp' => 2]),
        ]) . "\n");

        // No parents anywhere, so each line is the child of the one before it — which is
        // the same conversation the flat format described.
        $messages = SessionManager::open($path)->messages();
        $this->assertSame(['one', 'two'], array_map(static fn ($m) => $m->content[0]->text, $messages));
    }

    // ---- both directories ------------------------------------------------------------

    public function testPisSessionsAreListedBesidePigsOwn(): void
    {
        $pig = SessionManager::create('/some/project');
        $pig->append(new UserMessage('said to pig'));
        $pig->append($this->answer());

        $directory = $this->home . '-pi/sessions/' . SessionManager::slug('/some/project');
        mkdir($directory, 0o755, true);

        file_put_contents($directory . '/2030-01-01T00-00-00-000Z_11111111-1111-4111-8111-111111111111.jsonl', implode("\n", [
            json_encode(['type' => 'session', 'version' => 2, 'id' => 'pi-one', 'timestamp' => '2030-01-01T00:00:00.000Z', 'cwd' => '/some/project']),
            json_encode(['type' => 'message', 'id' => 'aaaaaaaa', 'parentId' => null, 'timestamp' => '2030-01-01T00:00:01.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'said to pi']]]]),
        ]) . "\n");

        $listed = SessionManager::listFor('/some/project');

        $this->assertCount(2, $listed);

        // Newest first across both, which the names sort into by themselves: they begin
        // with an ISO timestamp. 2030 beats today.
        $this->assertSame('said to pi', $listed[0]->opening);
        $this->assertSame('said to pig', $listed[1]->opening);
    }

    public function testAPiSessionCanBeOpenedFromTheList(): void
    {
        $directory = $this->home . '-pi/sessions/' . SessionManager::slug('/some/project');
        mkdir($directory, 0o755, true);
        $path = $directory . '/2030-01-01T00-00-00-000Z_22222222-2222-4222-8222-222222222222.jsonl';

        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session', 'version' => 2, 'id' => 'pi-two', 'timestamp' => '2030-01-01T00:00:00.000Z', 'cwd' => '/some/project']),
            json_encode(['type' => 'message', 'id' => 'aaaaaaaa', 'parentId' => null, 'timestamp' => '2030-01-01T00:00:01.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'started in pi']]]]),
        ]) . "\n");

        $session = SessionManager::open(SessionManager::listFor('/some/project')[0]->path);
        $session->append($this->answer('carried on in pig'));

        // Appended to pi's own file, in pi's own format, so the conversation is one
        // conversation and either tool can open it next.
        $lines = self::linesOf($path);
        $this->assertCount(3, $lines);
        $this->assertSame('message', $lines[2]['type']);
        $this->assertSame('aaaaaaaa', $lines[2]['parentId']);
        $this->assertSame('carried on in pig', $lines[2]['message']['content'][0]['text']);
    }

    public function testACustomEntryIsNotPartOfTheConversation(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('hi'));
        $session->append($this->answer());
        $session->appendCustomEntry('permissions', ['level' => 'full']);
        $session->append(new UserMessage('and again'));

        $back = SessionManager::open($session->path);

        // Three messages, not four: the note is walked past. And the message after it still
        // hangs off it, which is why it stays in the tree rather than being dropped.
        $this->assertCount(3, $back->messages());
        $this->assertSame('and again', $back->messages()[2]->content[0]->text);

        $notes = $back->customEntries('permissions');
        $this->assertCount(1, $notes);
        $this->assertInstanceOf(CustomEntry::class, $notes[0]);
        $this->assertSame(['level' => 'full'], $notes[0]->data);
    }
}
