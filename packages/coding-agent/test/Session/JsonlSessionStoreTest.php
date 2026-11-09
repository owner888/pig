<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use Pig\CodingAgent\Session\JsonlSessionStore;
use Pig\CodingAgent\Session\SessionStore;

/** The conformance suite against pi's files, in a home of its own. */
final class JsonlSessionStoreTest extends SessionStoreConformance
{
    private string $home;

    private ?JsonlSessionStore $store = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-jsonl-store-' . bin2hex(random_bytes(4));
        putenv('PIG_CODING_AGENT_DIR=' . $this->home);
        // pi's directory is read beside pig's; pointed somewhere empty so a real one is not.
        putenv('PI_HOME=' . $this->home . '-pi');
        JsonlSessionStore::useSessionDir(null);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_CODING_AGENT_DIR');
        putenv('PI_HOME');
        self::remove($this->home);
        self::remove($this->home . '-pi');
    }

    #[\Override]
    protected function store(): SessionStore
    {
        return $this->store ??= new JsonlSessionStore();
    }

    public function testALocationIsTheFilePiWouldName(): void
    {
        $this->assertSame(
            $this->home . '/sessions/--project-a--/2026-01-01T00-00-00-000Z_x.jsonl',
            $this->store()->locationFor('/project/a', '2026-01-01T00-00-00-000Z_x'),
        );
    }

    public function testASidecarIsTheFileBesideTheSessionReadableByItsOwnerAlone(): void
    {
        $location = $this->store()->locationFor('/project/a', '2026-01-01T00-00-00-000Z_x');
        $this->store()->append($location, "{\"type\":\"session\"}\n");
        $this->store()->writeSidecar($location, 'running.json', '{}');

        $sidecar = substr($location, 0, -6) . '.running.json';
        $this->assertFileExists($sidecar);
        $this->assertSame(0o600, fileperms($sidecar) & 0o777);
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
}
