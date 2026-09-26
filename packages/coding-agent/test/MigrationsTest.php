<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Migrations;

/**
 * Two tidies of pi's directory.
 *
 * Everything here is about **somebody else's files**, which is why the tests lean on what is
 * left behind as much as on what is produced: nothing may be deleted, and nothing pig did not
 * put there may be touched.
 */
final class MigrationsTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pig-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    private function remove(string $path): void
    {
        // `scandir`, not `glob('*')`, for exactly the reason the code under test uses one: a glob
        // does not match a leading dot, so the dotfile case left its file behind and three
        // `rmdir`s failed. The bug was fixed in `Migrations` and then written again here.
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $entry = $path . '/' . $name;
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }

        rmdir($path);
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($path = $this->directory . '/' . $name, $contents);

        return $path;
    }

    /** @return array<string, mixed> */
    private function json(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->directory . '/' . $name), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ---- credentials -----------------------------------------------------------------------

    public function testOauthEntriesBecomeTaggedAuthEntries(): void
    {
        $this->write('oauth.json', '{"anthropic":{"refresh":"1//r","access":"sk-ant-oat-x","expires":99}}');

        $moved = Migrations::authToAuthJson($this->directory);

        $this->assertSame(['anthropic'], $moved);
        $this->assertSame(
            ['type' => 'oauth', 'refresh' => '1//r', 'access' => 'sk-ant-oat-x', 'expires' => 99],
            $this->json('auth.json')['anthropic'],
        );
    }

    public function testApiKeysInPisSettingsMoveAcrossAndLeaveTheRestAlone(): void
    {
        $this->write('settings.json', '{"theme":"light","apiKeys":{"openai":"sk-openai"}}');

        $this->assertSame(['openai'], Migrations::authToAuthJson($this->directory));
        $this->assertSame(['type' => 'api_key', 'key' => 'sk-openai'], $this->json('auth.json')['openai']);

        // Rewritten, not replaced: everything else pi keeps in there is still there.
        $this->assertSame(['theme' => 'light'], $this->json('settings.json'));
    }

    public function testAnOauthCredentialBeatsAnApiKeyForTheSameProvider(): void
    {
        $this->write('oauth.json', '{"anthropic":{"refresh":"1//r"}}');
        $this->write('settings.json', '{"apiKeys":{"anthropic":"sk-ant-old"}}');

        $this->assertSame(['anthropic'], Migrations::authToAuthJson($this->directory), 'named once');

        // The newer way in wins, which is upstream's rule and the one that matters: signing in
        // is what replaced pasting a key, so the key is the stale half.
        $this->assertSame('oauth', $this->json('auth.json')['anthropic']['type']);
    }

    public function testTheOldOauthFileIsRenamedAndNotDeleted(): void
    {
        $path = $this->write('oauth.json', '{"anthropic":{"refresh":"1//r"}}');

        Migrations::authToAuthJson($this->directory);

        // Until the new file is written this is the only copy of somebody's refresh tokens, and
        // a rename is something they can undo by hand.
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($path . '.migrated');
    }

    public function testNothingHappensWhenThereIsAlreadyAnAuthFile(): void
    {
        $this->write('auth.json', '{"anthropic":{"type":"api_key","key":"the-current-one"}}');
        $this->write('oauth.json', '{"anthropic":{"refresh":"1//old"}}');

        // The first line of upstream's, and the whole safety of this: a file that exists is the
        // current shape, and what is in the old ones was superseded by it.
        $this->assertSame([], Migrations::authToAuthJson($this->directory));
        $this->assertSame('the-current-one', $this->json('auth.json')['anthropic']['key']);
        $this->assertFileExists($this->directory . '/oauth.json', 'and the old one is left where it is');
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $this->write('oauth.json', '{"anthropic":{"refresh":"1//r"}}');

        $this->assertSame(['anthropic'], Migrations::authToAuthJson($this->directory));
        $this->assertSame([], Migrations::authToAuthJson($this->directory));
    }

    public function testTheNewFileIsWrittenPrivateAndPisSettingsKeepTheirOwnMode(): void
    {
        $this->write('settings.json', '{"apiKeys":{"openai":"sk"}}');
        chmod($this->directory . '/settings.json', 0o644);

        Migrations::authToAuthJson($this->directory);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->directory . '/auth.json')), -4));
        // Not pig's to tighten: it stops holding keys, which is the point, and what it stops
        // holding them at is pi's business.
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->directory . '/settings.json')), -4));
    }

    public function testAFileThatIsNotJsonIsLeftAloneRatherThanRewritten(): void
    {
        $this->write('settings.json', 'this is not json');

        $this->assertSame([], Migrations::authToAuthJson($this->directory));
        $this->assertSame('this is not json', file_get_contents($this->directory . '/settings.json'));
        $this->assertFileDoesNotExist($this->directory . '/auth.json', 'nothing to write');
    }

    public function testNothingAtAllIsNotAProblem(): void
    {
        $this->assertSame([], Migrations::authToAuthJson($this->directory));
        $this->assertFileDoesNotExist($this->directory . '/auth.json');
    }

    // ---- stray sessions --------------------------------------------------------------------

    public function testASessionLeftAtTheRootIsFiledUnderItsOwnCwd(): void
    {
        $this->write(
            'a-session.jsonl',
            "{\"type\":\"session\",\"cwd\":\"/Users/dev/Dev/pig\",\"version\":2}\n{\"type\":\"message\"}\n",
        );

        Migrations::sessionsFromAgentRoot($this->directory);

        // The same slug `SessionManager` builds, which is the only reason this helps: filing it
        // anywhere else would move it out of pi's sight without bringing it into pig's.
        $moved = $this->directory . '/sessions/--Users-dev-Dev-pig--/a-session.jsonl';

        $this->assertFileExists($moved);
        $this->assertFileDoesNotExist($this->directory . '/a-session.jsonl');
    }

    public function testAJsonlThatIsNotASessionIsLeftWhereItIs(): void
    {
        $this->write('something-else.jsonl', "{\"type\":\"notes\"}\n");
        $this->write('empty.jsonl', '');
        $this->write('broken.jsonl', "not json at all\n");

        Migrations::sessionsFromAgentRoot($this->directory);

        // Something else put these there.
        foreach (['something-else.jsonl', 'empty.jsonl', 'broken.jsonl'] as $name) {
            $this->assertFileExists($this->directory . '/' . $name, $name);
        }
    }

    public function testASessionIsNeverMovedOverOneAlreadyFiled(): void
    {
        $header = "{\"type\":\"session\",\"cwd\":\"/Users/dev/Dev/pig\",\"version\":2}\n";
        $this->write('clash.jsonl', $header . "{\"from\":\"the root\"}\n");

        mkdir($this->directory . '/sessions/--Users-dev-Dev-pig--', 0o700, true);
        file_put_contents(
            $this->directory . '/sessions/--Users-dev-Dev-pig--/clash.jsonl',
            $header . "{\"from\":\"already filed\"}\n",
        );

        Migrations::sessionsFromAgentRoot($this->directory);

        // Two files with the same name are two conversations, and the one already filed is the
        // one that was filed on purpose.
        $this->assertStringContainsString(
            'already filed',
            (string) file_get_contents($this->directory . '/sessions/--Users-dev-Dev-pig--/clash.jsonl'),
        );
        $this->assertFileExists($this->directory . '/clash.jsonl', 'and the other is not thrown away');
    }

    public function testAStraySessionWhoseNameStartsWithADotIsFiledToo(): void
    {
        $this->write(
            '.hidden-session.jsonl',
            "{\"type\":\"session\",\"cwd\":\"/Users/dev/Dev/pig\",\"version\":2}\n",
        );

        Migrations::sessionsFromAgentRoot($this->directory);

        // `glob('*.jsonl')` does not match a leading dot and upstream's `readdirSync` does, so
        // this one was invisible until the scan replaced the glob.
        $this->assertFileExists($this->directory . '/sessions/--Users-dev-Dev-pig--/.hidden-session.jsonl');
    }

    public function testTheSessionDirectoryIsCreatedTheWayPigCreatesThem(): void
    {
        $this->write('s.jsonl', "{\"type\":\"session\",\"cwd\":\"/Users/dev/Dev/pig\",\"version\":2}\n");

        Migrations::sessionsFromAgentRoot($this->directory);

        // `0700`, like `SessionManager` and `Auth` — not upstream's default umask. pig creates
        // this exact directory itself in ordinary use.
        $mode = fileperms($this->directory . '/sessions/--Users-dev-Dev-pig--');

        $this->assertSame('0700', substr(sprintf('%o', $mode), -4));
    }

    public function testADirectoryWithNothingStrayInItIsUntouched(): void
    {
        Migrations::sessionsFromAgentRoot($this->directory);

        $this->assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testAMissingDirectoryIsNotAProblem(): void
    {
        // Nobody has ever run pi on this machine, which is most machines.
        Migrations::sessionsFromAgentRoot($this->directory . '/never-existed');

        $this->assertSame([], Migrations::authToAuthJson($this->directory . '/never-existed'));
    }
}
