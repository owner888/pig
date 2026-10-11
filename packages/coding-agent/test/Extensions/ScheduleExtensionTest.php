<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;
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
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testItRegistersTheScheduleTool(): void
    {
        [$loaded, $errors] = ExtensionLoader::load(
            $this->root . '/project',
            cliPaths: [dirname(__DIR__, 4) . '/extensions/pig-schedule/index.php'],
            home: $this->root . '/home',
        );

        $this->assertSame([], $errors);
        $tools = $loaded[0]->api->tools();
        $this->assertSame([ScheduleTool::NAME], array_map(static fn ($t): string => $t->name, $tools));
        $this->assertSame(ScheduleTool::promptSnippet(), $tools[0]->promptSnippet);
        $this->assertNotSame([], $tools[0]->promptGuidelines);
        $this->assertSame(ScheduleTool::ACTIONS, $tools[0]->parameters['properties']['action']['enum']);
    }

    public function testWithoutAServerItSaysHowToStartOne(): void
    {
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('pig web start -d');
        (new ScheduleTool())->execute(['action' => 'list'], new HookContext(cwd: $this->root . '/project'));
    }

    public function testAnActionThatIsNotOneIsRefusedNamingThem(): void
    {
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('list, runs, create, update, delete, enable, disable, run');
        (new ScheduleTool())->execute(['action' => 'schedule'], new HookContext(cwd: $this->root . '/project'));
    }

    public function testThisMachinesTimezoneIsOneDateTimeKnows(): void
    {
        $this->assertContains(ScheduleTool::localTimezone(), [...\DateTimeZone::listIdentifiers(), date_default_timezone_get()]);
    }
}
