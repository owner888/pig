<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Packages;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\PackageCommands;

/** Upstream's `package-manager-cli.test.ts`: the parser, rule for rule. */
final class PackageCommandsTest extends TestCase
{
    public function testUninstallIsRemove(): void
    {
        $this->assertSame('remove', PackageCommands::parse(['uninstall', 'x'])['command']);
        $this->assertNull(PackageCommands::parse(['frobnicate']));
    }

    public function testLocalOnlyMeansSomethingToInstallAndRemove(): void
    {
        $this->assertTrue(PackageCommands::parse(['install', 'x', '-l'])['local']);
        $this->assertSame('-l', PackageCommands::parse(['list', '-l'])['invalidOption']);
        $this->assertSame('--force', PackageCommands::parse(['install', 'x', '--force'])['invalidOption']);
    }

    public function testUpdateTargets(): void
    {
        $self = PackageCommands::parse(['update']);
        $this->assertSame(['type' => 'self'], $self['updateTarget']);
        $this->assertTrue($self['showExtensionsSkippedNote']);

        $this->assertSame(['type' => 'all'], PackageCommands::parse(['update', '--all'])['updateTarget']);
        $this->assertSame(['type' => 'all'], PackageCommands::parse(['update', '--self', '--extensions'])['updateTarget']);
        $this->assertSame(['type' => 'extensions'], PackageCommands::parse(['update', '--extensions'])['updateTarget']);
        $this->assertSame(['type' => 'models'], PackageCommands::parse(['update', '--models'])['updateTarget']);
        $this->assertSame(['type' => 'self'], PackageCommands::parse(['update', 'pig'])['updateTarget']);
        $this->assertSame(['type' => 'all'], PackageCommands::parse(['update', 'self', '--extensions'])['updateTarget']);
        $this->assertSame(
            ['type' => 'extensions', 'source' => 'git:github.com/user/repo'],
            PackageCommands::parse(['update', 'git:github.com/user/repo'])['updateTarget'],
        );
        $this->assertSame(
            ['type' => 'extensions', 'source' => 'git:github.com/user/repo'],
            PackageCommands::parse(['update', '--extension', 'git:github.com/user/repo'])['updateTarget'],
        );
    }

    public function testTheRefusalsAreUpstreams(): void
    {
        $this->assertSame(
            '--all cannot be combined with --self, --extensions, --models, or --extension',
            PackageCommands::parse(['update', '--all', '--self'])['conflictingOptions'],
        );
        $this->assertSame('--all cannot be combined with a positional source', PackageCommands::parse(['update', '--all', 'x'])['conflictingOptions']);
        $this->assertSame(
            '--models cannot be combined with --self, --extensions, --all, or --extension',
            PackageCommands::parse(['update', '--models', '--self'])['conflictingOptions'],
        );
        $this->assertSame(
            '--extension cannot be combined with --self, --extensions, or --all',
            PackageCommands::parse(['update', '--extension', 'x', '--self'])['conflictingOptions'],
        );
        $this->assertSame('--extension can only be provided once', PackageCommands::parse(['update', '--extension', 'x', '--extension', 'y'])['conflictingOptions']);
        $this->assertSame('--extension', PackageCommands::parse(['update', '--extension'])['missingOptionValue']);
        $this->assertSame('--extension', PackageCommands::parse(['update', '--extension', '--self'])['missingOptionValue']);
        $this->assertSame(
            'positional update targets cannot be combined with --self, --extensions, or --all',
            PackageCommands::parse(['update', 'x', '--self'])['conflictingOptions'],
        );
        $this->assertSame('y', PackageCommands::parse(['install', 'x', 'y'])['invalidArgument']);
        $this->assertSame('--bogus', PackageCommands::parse(['install', 'x', '--bogus'])['invalidOption']);
    }

    public function testApproveAndNoApproveOverrideTrust(): void
    {
        $this->assertTrue(PackageCommands::parse(['list', '--approve'])['projectTrustOverride']);
        $this->assertFalse(PackageCommands::parse(['list', '-na'])['projectTrustOverride']);
        $this->assertNull(PackageCommands::parse(['list'])['projectTrustOverride']);
    }

    public function testHelpPrintsTheUsage(): void
    {
        ob_start();
        $code = PackageCommands::main(['install', '--help'], sys_get_temp_dir());
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('pig install <source> [-l] [--approve|--no-approve]', $out);
        $this->assertStringContainsString('git:github.com/user/repo', $out);
    }
}
