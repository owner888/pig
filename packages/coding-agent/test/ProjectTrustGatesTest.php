<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Settings;

/**
 * One rule, five loaders: an untrusted project's `.pig/` is not read, and the person's own
 * `~/.pig/agent/` always is. One case per loader, because a gate on four of five is the shape
 * CLAUDE.md's traps keep finding, and a single assertion over all five cannot say which is open.
 */
final class ProjectTrustGatesTest extends TestCase
{
    private string $root;
    private string $home;
    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-gates-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home, 0o700, true);
        mkdir($this->cwd . '/.pig', 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function put(string $relative, string $body): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $body);
    }

    private const string HOOK = <<<'PHP'
        <?php
        return function ($pi): void { $pi->on('tool_call', fn () => null); };
        PHP;

    public function testHooksUnderTheProjectAreLeftOutAndTheOwnOnesStay(): void
    {
        $this->put('home/hooks/mine.php', self::HOOK);
        $this->put('project/.pig/hooks/theirs.php', self::HOOK);

        [$trusted] = HookLoader::load($this->cwd, home: $this->home);
        [$untrusted] = HookLoader::load($this->cwd, home: $this->home, projectTrusted: false);

        $this->assertCount(2, $trusted);
        $this->assertCount(1, $untrusted);
        $this->assertStringEndsWith('/home/hooks/mine.php', $untrusted[0]->path);
    }

    public function testToolsUnderTheProjectAreLeftOut(): void
    {
        $tool = <<<'PHP'
            <?php
            use Pig\CodingAgent\CustomTools\CustomTool;
            use Pig\Agent\AgentToolResult;
            use Pig\Ai\TextContent;
            return fn ($pi) => new CustomTool(
                name: 'NAME', label: 'x', description: 'x',
                parameters: ['type' => 'object', 'properties' => []],
                execute: fn () => new AgentToolResult([new TextContent('ok')]),
            );
            PHP;
        $this->put('home/tools/mine/index.php', str_replace('NAME', 'mine', $tool));
        $this->put('project/.pig/tools/theirs/index.php', str_replace('NAME', 'theirs', $tool));

        [$trusted] = CustomToolLoader::load($this->cwd, home: $this->home);
        [$untrusted] = CustomToolLoader::load($this->cwd, home: $this->home, projectTrusted: false);

        $names = static fn (array $loaded): array => array_map(static fn ($t) => $t->tool->name, $loaded);
        $this->assertSame(['mine', 'theirs'], $names($trusted));
        $this->assertSame(['mine'], $names($untrusted));
    }

    public function testBothProjectExtensionRootsAreLeftOut(): void
    {
        $extension = <<<'PHP'
            <?php
            return function ($pi): void { $pi->registerCommand('NAME', fn () => null, 'x'); };
            PHP;
        $this->put('home/extensions/mine.php', str_replace('NAME', 'mine', $extension));
        $this->put('project/.pig/extensions/dot.php', str_replace('NAME', 'dot', $extension));
        $this->put('project/extensions/plain.php', str_replace('NAME', 'plain', $extension));

        [$trusted] = ExtensionLoader::load($this->cwd, home: $this->home);
        [$untrusted] = ExtensionLoader::load($this->cwd, home: $this->home, projectTrusted: false);

        $this->assertCount(3, $trusted);
        $this->assertCount(1, $untrusted);
        $this->assertStringEndsWith('/home/extensions/mine.php', $untrusted[0]->path);
    }

    public function testTheTrustQuestionIsAskedOfThePersonsExtensionsBeforeTheProjectsLoad(): void
    {
        $extension = <<<'PHP'
            <?php
            return function ($pi): void { $pi->registerCommand('NAME', fn () => null, 'x'); };
            PHP;
        $this->put('home/extensions/mine.php', str_replace('NAME', 'mine', $extension));
        $this->put('project/.pig/extensions/dot.php', str_replace('NAME', 'dot', $extension));
        $this->put('elsewhere/listed.php', str_replace('NAME', 'listed', $extension));

        $askedWith = null;
        $decide = static function (array $loaded) use (&$askedWith): bool {
            $askedWith = array_map(static fn ($ext) => $ext->name, $loaded);

            return true;
        };

        [$trusted] = ExtensionLoader::load(
            $this->cwd,
            home: $this->home,
            projectTrusted: $decide,
            projectConfigured: fn (): array => [$this->root . '/elsewhere/listed.php'],
        );

        $this->assertSame(['mine'], $askedWith, 'the question is put to what loaded before the project');
        $this->assertSame(['mine', 'dot', 'listed'], array_map(static fn ($ext) => $ext->name, $trusted));

        [$untrusted] = ExtensionLoader::load($this->cwd, home: $this->home, projectTrusted: static fn (): bool => false);
        $this->assertSame(['mine'], array_map(static fn ($ext) => $ext->name, $untrusted));
    }

    public function testProjectCommandsAreLeftOut(): void
    {
        $this->put('home/commands/mine.md', "mine\n");
        $this->put('project/.pig/commands/theirs.md', "theirs\n");

        $names = static fn (array $commands): array => array_map(static fn ($c) => $c->name, $commands);
        $this->assertSame(['mine', 'theirs'], $names(SlashCommands::load($this->cwd, $this->home)));
        $this->assertSame(['mine'], $names(SlashCommands::load($this->cwd, $this->home, projectTrusted: false)));
    }

    public function testTheProjectsSettingsFileIsNotEvenRead(): void
    {
        $this->put('home/settings.json', '{"theme":"dark"}');
        // Not JSON on purpose: read and ignored would still complain about it; not read says nothing.
        $this->put('project/.pig/settings.json', '{"shellPath": "/tmp/evil", broken');

        $trusted = Settings::load($this->cwd, $this->home);
        $untrusted = Settings::load($this->cwd, $this->home, projectTrusted: false);

        $this->assertNotSame([], $trusted->problems(), 'trusted, the broken project file is complained about');
        $this->assertSame([], $untrusted->problems(), 'untrusted, it is not opened at all');
        $this->assertSame('dark', $untrusted->theme());
    }

    public function testProjectSkillsGoThroughTheRootSwitchAndNothingElseDoes(): void
    {
        $skill = "---\nname: NAME\ndescription: d\n---\n\nDo it.\n";
        $this->put('home/skills/mine/SKILL.md', str_replace('NAME', 'mine', $skill));
        $this->put('project/.pig/skills/theirs/SKILL.md', str_replace('NAME', 'theirs', $skill));

        $names = static fn (array $skills): array => array_map(static fn ($s) => $s->name, $skills);

        [$all] = Skills::load($this->cwd, home: $this->home);
        [$gated] = Skills::load($this->cwd, home: $this->home, roots: ['project' => false]);

        $this->assertContains('theirs', $names($all));
        $this->assertNotContains('theirs', $names($gated));
        $this->assertContains('mine', $names($gated));
    }
}
