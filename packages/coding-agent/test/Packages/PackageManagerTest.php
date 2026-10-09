<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Packages;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageFilter;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Packages\ResolvedPaths;
use Pig\CodingAgent\Settings;

/**
 * Upstream's `package-manager.test.ts`, the cases that survive without npm: local packages,
 * the manifest, the filters, the settings in both scopes, and a git package against a bare
 * repository on disk — no network, and `git` on the PATH or the git cases are skipped.
 */
final class PackageManagerTest extends TestCase
{
    private string $root;

    private string $home;

    private string $cwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-packages-' . bin2hex(random_bytes(4));
        $this->home = "{$this->root}/home";
        $this->cwd = "{$this->root}/project";
        mkdir($this->home, 0o700, true);
        mkdir("{$this->cwd}/.pig", 0o700, true);
        putenv("PIG_HOME={$this->home}");
    }

    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('GIT_CONFIG_COUNT');
        putenv('GIT_CONFIG_KEY_0');
        putenv('GIT_CONFIG_VALUE_0');
        self::rm($this->root);
    }

    // ---- local packages ---------------------------------------------------------------------

    public function testAPackageWithTheConventionalDirectoriesIsDiscovered(): void
    {
        $package = $this->package('tools', [
            'extensions/hello.php' => '<?php return static fn () => null;',
            'extensions/sub/index.php' => '<?php return static fn () => null;',
            'skills/review/SKILL.md' => "---\ndescription: review\n---\n",
            'prompts/plan.md' => 'plan',
            'themes/mono.json' => '{}',
            'vendor/dep/thing.php' => '<?php',
            '.hidden/x.php' => '<?php',
        ], inHome: true);

        $resolved = $this->manager(['packages' => ["./tools"]])->resolve();

        $this->assertSame(["{$package}/extensions/hello.php", "{$package}/extensions/sub/index.php"], $resolved->enabled('extensions'));
        $this->assertSame(["{$package}/skills/review/SKILL.md"], $resolved->enabled('skills'));
        $this->assertSame(["{$package}/prompts/plan.md"], $resolved->enabled('prompts'));
        $this->assertSame(["{$package}/themes/mono.json"], $resolved->enabled('themes'));
        $this->assertSame('./tools', $resolved->extensions[0]->metadata->source);
        $this->assertSame('package', $resolved->extensions[0]->metadata->origin);
        $this->assertSame($package, $resolved->extensions[0]->metadata->packageRoot);
    }

    public function testAManifestNamesTheResourcesAndTheDirectoriesAreThenIgnored(): void
    {
        $package = $this->package('manifest', [
            'composer.json' => json_encode(['extra' => ['pig' => [
                'extensions' => ['./src/Ext.php', 'src/more/*.php', '!src/more/skip.php'],
                'skills' => ['resources/skills'],
            ]]]),
            'src/Ext.php' => '<?php',
            'src/more/a.php' => '<?php',
            'src/more/skip.php' => '<?php',
            'resources/skills/one/SKILL.md' => "---\ndescription: one\n---\n",
            'extensions/ignored.php' => '<?php',
            'themes/ignored.json' => '{}',
        ], inHome: true);

        $resolved = $this->manager(['packages' => ['./manifest']])->resolve();

        $this->assertSame(["{$package}/src/Ext.php", "{$package}/src/more/a.php"], $resolved->enabled('extensions'));
        $this->assertSame(["{$package}/resources/skills/one/SKILL.md"], $resolved->enabled('skills'));
        $this->assertSame([], $resolved->enabled('themes'), 'a manifest replaces the conventional directories');
    }

    public function testASettingsFilterNarrowsAPackageAndAnEmptyListTurnsATypeOff(): void
    {
        $package = $this->package('filtered', [
            'extensions/a.php' => '<?php',
            'extensions/b.php' => '<?php',
            'extensions/legacy.php' => '<?php',
            'skills/s/SKILL.md' => "---\ndescription: s\n---\n",
            'prompts/p.md' => 'p',
        ], inHome: true);

        $resolved = $this->manager(['packages' => [[
            'source' => './filtered',
            'extensions' => ['extensions/*.php', '!extensions/legacy.php'],
            'skills' => [],
        ]]])->resolve();

        $this->assertSame(["{$package}/extensions/a.php", "{$package}/extensions/b.php"], $resolved->enabled('extensions'));
        $this->assertCount(3, $resolved->extensions, 'the excluded file is listed, off');
        $this->assertSame([], $resolved->enabled('skills'));
        $this->assertCount(1, $resolved->skills, 'listed and off, so a screen can show it');
        $this->assertSame(["{$package}/prompts/p.md"], $resolved->enabled('prompts'), 'an omitted type loads everything');
    }

    public function testPlusAndMinusAreExactAndWinOverTheGlobs(): void
    {
        $base = '/pkg';
        $all = ['/pkg/extensions/a.php', '/pkg/extensions/b.php', '/pkg/extensions/c.php'];

        $this->assertSame(['/pkg/extensions/b.php'], PackageFilter::apply($all, ['!extensions/*', '+extensions/b.php'], $base));
        $this->assertSame(['/pkg/extensions/a.php', '/pkg/extensions/c.php'], PackageFilter::apply($all, ['-extensions/b.php'], $base));
        $this->assertSame(['/pkg/extensions/a.php'], PackageFilter::apply($all, ['a.php'], $base), 'a bare name matches by basename');
        $this->assertSame(['/pkg/extensions/a.php', '/pkg/extensions/c.php'], PackageFilter::apply($all, ['*', '!b.php'], $base));
    }

    public function testASkillAnswersToItsDirectory(): void
    {
        $this->assertTrue(PackageFilter::matchesAny('/pkg/skills/review/SKILL.md', ['skills/review'], '/pkg'));
        $this->assertTrue(PackageFilter::matchesAnyExact('/pkg/skills/review/SKILL.md', ['skills/review'], '/pkg'));
        $this->assertFalse(PackageFilter::matchesAnyExact('/pkg/skills/review/SKILL.md', ['skills/rev'], '/pkg'));
    }

    public function testAProjectEntryReplacesTheUsersUnlessItIsADelta(): void
    {
        $package = $this->package('shared', [
            'extensions/a.php' => '<?php',
            'extensions/b.php' => '<?php',
        ]);
        // The same package from both files: relative to each file's directory.
        $user = ['packages' => [['source' => '../project/shared', 'extensions' => ['extensions/a.php']]]];

        file_put_contents("{$this->cwd}/.pig/settings.json", json_encode(['packages' => ['../shared']]));
        $resolved = $this->manager($user)->resolve();
        $this->assertSame(["{$package}/extensions/a.php", "{$package}/extensions/b.php"], $resolved->enabled('extensions'), 'the project entry, unfiltered, wins');
        $this->assertSame('project', $resolved->extensions[0]->metadata->scope);

        file_put_contents("{$this->cwd}/.pig/settings.json", json_encode(['packages' => [['source' => '../shared', 'autoload' => false, 'extensions' => ['-extensions/a.php']]]]));
        $resolved = $this->manager($user)->resolve();
        $this->assertSame([], $resolved->enabled('extensions'), "the delta took the user's one file away and added none");
    }

    public function testADirectoryWithNoneOfTheShapesIsOneExtension(): void
    {
        $dir = $this->package('bare', ['index.php' => '<?php return static fn () => null;'], inHome: true);

        $resolved = $this->manager(['packages' => ['./bare']])->resolve();

        $this->assertSame([$dir], $resolved->enabled('extensions'));
    }

    // ---- the settings -------------------------------------------------------------------------

    public function testInstallAndRemoveWriteTheSettingsOfTheScopeAsked(): void
    {
        $this->package('local', ['extensions/a.php' => '<?php']);
        $manager = $this->manager();

        $manager->installAndPersist('./local');
        $this->assertSame(['../project/local'], $this->userPackages(), "relative to the settings file's directory, as upstream writes it");

        $manager->installAndPersist('./local', local: true);
        $this->assertSame(['../local'], json_decode((string) file_get_contents("{$this->cwd}/.pig/settings.json"), true)['packages']);

        $this->assertFalse($manager->addSourceToSettings('./local'), 'already there');
        $this->assertTrue($manager->removeAndPersist('./local'));
        $this->assertSame([], $this->userPackages());
        $this->assertFalse($manager->removeAndPersist('./local'), 'nothing left to remove');
    }

    public function testInstallingAPathThatDoesNotExistIsRefused(): void
    {
        $this->expectException(PackageError::class);
        $this->expectExceptionMessage('Path does not exist');

        $this->manager()->install('./nowhere');
    }

    public function testAnUntrustedProjectCannotBeWrittenTo(): void
    {
        $this->package('local', ['extensions/a.php' => '<?php']);
        $manager = new PackageManager($this->cwd, Settings::load($this->cwd, $this->home, projectTrusted: false), $this->home);

        $this->expectException(PackageError::class);
        $this->expectExceptionMessage('not trusted');

        $manager->install('./local', local: true);
    }

    public function testListNamesBothScopesAndWhetherAnEntryIsFiltered(): void
    {
        $this->package('one', ['extensions/a.php' => '<?php']);
        file_put_contents("{$this->cwd}/.pig/settings.json", json_encode(['packages' => [['source' => '../one', 'skills' => []]]]));

        $listed = $this->manager(['packages' => ['git:github.com/user/repo']])->listConfiguredPackages();

        $this->assertSame('git:github.com/user/repo', $listed[0]['source']);
        $this->assertSame('user', $listed[0]['scope']);
        $this->assertFalse($listed[0]['filtered']);
        $this->assertNull($listed[0]['installedPath'], 'never cloned');
        $this->assertSame('../one', $listed[1]['source']);
        $this->assertSame('project', $listed[1]['scope']);
        $this->assertTrue($listed[1]['filtered']);
        $this->assertSame("{$this->cwd}/one", $listed[1]['installedPath']);
    }

    public function testUpdatingAPackageNobodyConfiguredSaysSoAndSuggestsTheNearest(): void
    {
        $manager = $this->manager(['packages' => ['git:github.com/user/repo@v1']]);

        try {
            $manager->update('github.com/user/repo');
            $this->fail('matched nothing, yet');
        } catch (PackageError $error) {
            $this->assertSame('No matching package found for github.com/user/repo. Did you mean git:github.com/user/repo@v1?', $error->getMessage());
        }

        try {
            $manager->update('github.com/user/other');
            $this->fail('matched nothing');
        } catch (PackageError $error) {
            $this->assertStringStartsWith('No matching package found for github.com/user/other', $error->getMessage());
        }
    }

    // ---- git ----------------------------------------------------------------------------------

    public function testAGitPackageIsClonedUnderTheHostAndPathAndRemovedWithThem(): void
    {
        $repo = $this->bareRepository(['extensions/from-git.php' => '<?php']);
        $manager = $this->manager();

        $manager->installAndPersist("git:{$repo}");

        $installed = "{$this->home}/git/localhost/pig-test/repo";
        $this->assertFileExists("{$installed}/extensions/from-git.php");
        $this->assertFileExists("{$this->home}/git/.gitignore", 'the install root ignores itself');
        $this->assertSame(["git:{$repo}"], $this->userPackages());

        $resolved = $manager->resolve();
        $this->assertSame(["{$installed}/extensions/from-git.php"], $resolved->enabled('extensions'));

        $this->assertTrue($manager->removeAndPersist("git:{$repo}"));
        $this->assertDirectoryDoesNotExist($installed);
        $this->assertDirectoryDoesNotExist("{$this->home}/git/localhost", 'empty parents are pruned');
    }

    public function testResolveClonesAPackageThatIsConfiguredAndNotInstalled(): void
    {
        $repo = $this->bareRepository(['extensions/a.php' => '<?php']);
        $asked = [];

        $resolved = $this->manager(['packages' => ["git:{$repo}"]])->resolve(static function (string $source) use (&$asked): string {
            $asked[] = $source;

            return 'install';
        });

        $this->assertSame(["git:{$repo}"], $asked);
        $this->assertCount(1, $resolved->enabled('extensions'));

        putenv('PIG_OFFLINE=1');
        try {
            $offline = $this->manager(['packages' => ['git:localhost/pig-test/missing']])->resolve();
            $this->assertSame([], $offline->enabled('extensions'), 'offline, a missing package is skipped');
        } finally {
            putenv('PIG_OFFLINE');
        }
    }

    public function testUpdateFollowsTheBranchAndAPinnedRefStaysPut(): void
    {
        $repo = $this->bareRepository(['extensions/a.php' => '<?php // one']);
        $pinned = trim((string) shell_exec('git -C ' . escapeshellarg($this->work) . ' rev-parse HEAD'));
        $installed = "{$this->home}/git/localhost/pig-test/repo";

        $following = $this->manager(['packages' => ["git:{$repo}"]]);
        $following->install("git:{$repo}");
        $this->commit(['extensions/a.php' => '<?php // two']);

        $this->assertTrue($following->hasAvailableUpdate($installed));
        $following->update("git:{$repo}");
        $this->assertSame('<?php // two', file_get_contents("{$installed}/extensions/a.php"));
        $this->assertFalse($following->hasAvailableUpdate($installed));

        // The same repository pinned to the first commit: one identity, one directory, and
        // `update` reconciles the checkout to the configured ref rather than past it.
        $pinnedManager = $this->manager(['packages' => ["git:{$repo}@{$pinned}"]]);
        $pinnedManager->update("git:{$repo}");
        $this->assertSame('<?php // one', file_get_contents("{$installed}/extensions/a.php"));
    }

    // ---- plumbing -----------------------------------------------------------------------------

    private string $work = '';

    /** @param array<string, mixed> $user */
    private function manager(array $user = []): PackageManager
    {
        file_put_contents("{$this->home}/settings.json", json_encode($user));

        return new PackageManager($this->cwd, Settings::load($this->cwd, $this->home), $this->home);
    }

    /** @return list<mixed> */
    private function userPackages(): array
    {
        return json_decode((string) file_get_contents("{$this->home}/settings.json"), true)['packages'] ?? [];
    }

    /** @param array<string, string> $files relative path => content */
    private function package(string $name, array $files, bool $inHome = false): string
    {
        $root = ($inHome ? $this->home : $this->cwd) . "/{$name}";

        foreach ($files as $path => $content) {
            if (!is_dir(dirname("{$root}/{$path}"))) {
                mkdir(dirname("{$root}/{$path}"), 0o700, true);
            }

            file_put_contents("{$root}/{$path}", $content);
        }

        if (!is_dir($root)) {
            mkdir($root, 0o700, true);
        }

        return $root;
    }

    /**
     * A bare repository that `git:localhost/pig-test/repo` reaches without a network: git's
     * `url.<base>.insteadOf` — through the `GIT_CONFIG_*` environment, which every git this
     * process starts reads — rewrites `https://localhost/` to a directory here. So the source
     * string is an ordinary one, the checkout lands under `<home>/git/localhost/pig-test/repo`,
     * and `ls-remote` for the update check goes the same way.
     *
     * @param array<string, string> $files
     */
    private function bareRepository(array $files): string
    {
        if (trim((string) shell_exec('command -v git')) === '') {
            $this->markTestSkipped('git is not on the PATH');
        }

        $remote = "{$this->root}/remote";
        $bare = "{$remote}/pig-test/repo";
        $this->work = "{$this->root}/work";
        mkdir($bare, 0o700, true);
        mkdir($this->work, 0o700, true);
        putenv('GIT_CONFIG_COUNT=1');
        putenv("GIT_CONFIG_KEY_0=url.{$remote}/.insteadOf");
        putenv('GIT_CONFIG_VALUE_0=https://localhost/');
        shell_exec('git init -q --bare -b main ' . escapeshellarg($bare));
        shell_exec('git -C ' . escapeshellarg($this->work) . ' init -q -b main');
        shell_exec('git -C ' . escapeshellarg($this->work) . ' remote add origin ' . escapeshellarg($bare));
        $this->commit($files);

        return 'localhost/pig-test/repo';
    }

    /** @param array<string, string> $files */
    private function commit(array $files): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("{$this->work}/{$path}"))) {
                mkdir(dirname("{$this->work}/{$path}"), 0o700, true);
            }

            file_put_contents("{$this->work}/{$path}", $content);
        }

        $git = 'git -C ' . escapeshellarg($this->work) . ' -c user.name=dev -c user.email=dev@example';
        shell_exec("{$git} add -A && {$git} commit -q -m change && {$git} push -q origin main");
    }

    private static function rm(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rm("{$path}/{$entry}");
            }
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
