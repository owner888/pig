<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Web\Schedule\ScheduleTool;

/**
 * `builtin:schedule` on its own: it registers the `schedule` tool, with its line for the system
 * prompt and its guidelines, and the tool says what to do when no `pig web` runs the schedule.
 * The tool against a server is `WebModeTest::testTheScheduleToolAsksTheServerThatRunsTheScheduleWithTheLocalToken()`.
 */
final class ScheduleExtensionTest extends TestCase
{
    private string $root;

    private string|false $previousHome = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-schedule-ext-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home', 0o700, true);
        mkdir($this->root . '/project', 0o755, true);
        $this->previousHome = getenv('PIG_CODING_AGENT_DIR');
        putenv('PIG_CODING_AGENT_DIR=' . $this->root . '/home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->previousHome === false ? 'PIG_CODING_AGENT_DIR' : 'PIG_CODING_AGENT_DIR=' . $this->previousHome);
        putenv(ScheduleTool::WEB_CONVERSATION);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return list<\Pig\CodingAgent\Extensions\ToolDefinition> */
    private function load(): array
    {
        [$loaded, $errors] = ExtensionLoader::load(
            $this->root . '/project',
            cliPaths: [dirname(__DIR__, 4) . '/extensions/pig-schedule/index.php'],
            home: $this->root . '/home',
        );
        $this->assertSame([], $errors);

        return $loaded[0]->api->tools();
    }

    public function testItRegistersTheScheduleToolInAWebConversation(): void
    {
        putenv(ScheduleTool::WEB_CONVERSATION . '=1');
        $tools = $this->load();

        $this->assertSame([ScheduleTool::NAME], array_map(static fn ($t): string => $t->name, $tools));
        $this->assertSame(ScheduleTool::promptSnippet(), $tools[0]->promptSnippet);
        // The prompt writes `- schedule: <snippet>`, so a snippet naming the tool says it twice.
        $this->assertStringStartsNotWith(ScheduleTool::NAME . ':', $tools[0]->promptSnippet);
        $this->assertNotSame([], $tools[0]->promptGuidelines);
        // It only creates: no action to choose, no task to name.
        $this->assertSame(['name', 'prompt'], $tools[0]->parameters['required']);
        $this->assertArrayNotHasKey('action', $tools[0]->parameters['properties']);
        $this->assertArrayNotHasKey('id', $tools[0]->parameters['properties']);
    }

    public function testThePagesConversationsAreStartedMarkedAsSuch(): void
    {
        $this->assertSame('1', \Pig\CodingAgent\Web\HttpServer::childEnvironment([], null, null)[ScheduleTool::WEB_CONVERSATION]);
        $this->assertSame(['PIG_SANDBOX_CONTAINER' => 'c', 'PIG_WEB_THEME' => 'dark', 'PIG_SESSION_OWNER' => 'ann', ScheduleTool::WEB_CONVERSATION => '1'], \Pig\CodingAgent\Web\HttpServer::childEnvironment(['PIG_SANDBOX_CONTAINER' => 'c'], 'dark', 'ann'));
    }

    public function testOutsideAWebConversationThereIsNoTool(): void
    {
        $this->assertSame([], $this->load(), 'the terminal, print and an RPC client of their own are not sent its schema');
    }

    public function testWithoutAServerItSaysHowToStartOne(): void
    {
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('pig web start -d');
        (new ScheduleTool())->execute(['name' => 'x', 'prompt' => 'y'], new ExtensionContext(cwd: $this->root . '/project', ui: $this->createStub(\Pig\CodingAgent\Extensions\ExtensionUi::class), hasUi: true));
    }

    public function testWithNobodyToConfirmItRefuses(): void
    {
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('nobody is here to confirm');
        (new ScheduleTool())->execute(['name' => 'x', 'prompt' => 'y'], new ExtensionContext(cwd: $this->root . '/project'));
    }

    public function testThisMachinesTimezoneIsOneDateTimeKnows(): void
    {
        $this->assertContains(ScheduleTool::localTimezone(), [...\DateTimeZone::listIdentifiers(), date_default_timezone_get()]);
    }
}
