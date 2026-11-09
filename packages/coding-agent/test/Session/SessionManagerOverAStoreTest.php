<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\ToolProgress;

/**
 * `SessionManager` on a store that has no files at all: what it writes, opens, lists, finds, names,
 * forks and keeps beside a session goes through `SessionStore` and nowhere else — the promise of
 * phase 1 in docs/dev/design/storage.md, which a stray `is_file()` would break.
 */
final class SessionManagerOverAStoreTest extends TestCase
{
    private MemorySessionStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->store = new MemorySessionStore();
        SessionManager::useSessionStore($this->store);
    }

    #[\Override]
    protected function tearDown(): void
    {
        SessionManager::useSessionStore(null);
    }

    private static function answer(string $text = 'here you go'): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(10, 20, 30, 40, 100, new Cost(total: 0.25)),
            StopReason::Stop,
        );
    }

    private function conversation(string $cwd, string $asked): SessionManager
    {
        $session = SessionManager::create($cwd);
        $session->append(new UserMessage($asked));
        $session->append(self::answer());

        return $session;
    }

    public function testASessionIsWrittenToTheStoreAndOpensFromIt(): void
    {
        $session = $this->conversation('/project/a', 'what is in the store');

        $this->assertStringStartsWith('memory:///project/a/', $session->path);
        $this->assertTrue($session->isPersisted());

        $opened = SessionManager::open($session->path);

        $this->assertSame($session->id, $opened->id);
        $this->assertSame('/project/a', $opened->cwd);
        $this->assertCount(2, $opened->messages());
    }

    public function testListingsFindingAndNamesComeFromTheStore(): void
    {
        $first = $this->conversation('/project/a', 'the first thing');
        $second = $this->conversation('/project/a', 'the second thing');
        $this->conversation('/project/b', 'somewhere else');
        $second->setSessionName('Named');

        $this->assertSame(['Named', 'the first thing'], array_map(static fn ($info): string => $info->opening, SessionManager::listFor('/project/a')));
        $this->assertSame($second->path, SessionManager::latestPathFor('/project/a'));
        $this->assertSame($first->path, SessionManager::find('/project/a', substr($first->id, 0, 8)));
        $this->assertSame($first->path, SessionManager::findById('/project/a', $first->id));
        $this->assertSame('Named', SessionManager::glance($second->path)?->name);
        $this->assertSame(3, SessionManager::listAll(10)[1]);

        $elsewhere = SessionManager::locate('/project/b', $first->id);
        $this->assertSame('/project/a', $elsewhere?->elsewhere, "another project's session is found and said to be another's");
    }

    public function testAForkIsANewSessionInTheStore(): void
    {
        $source = $this->conversation('/project/a', 'fork me');

        $fork = SessionManager::forkFrom($source->path, '/project/b');

        $this->assertNotSame($source->path, $fork->path);
        $this->assertSame($source->path, $fork->parentSession);
        $this->assertCount(2, $fork->messages());
        $this->assertTrue($this->store->exists($fork->path));
    }

    public function testARunningToolsOutputIsASidecarInTheStore(): void
    {
        $session = $this->conversation('/project/a', 'run something');
        $this->store->writeSidecar($session->path, ToolProgress::SIDECAR, (string) json_encode(['toolCallId' => 'c1', 'output' => 'half way']));

        $this->assertSame(['toolCallId' => 'c1', 'output' => 'half way'], ToolProgress::read($session->path));
    }
}
