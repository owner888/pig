<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Export\ShareRecords;
use Pig\Test\AssertsThrows;

/** `shares.json`: what `/unshare` offers. */
final class ShareRecordsTest extends TestCase
{
    use AssertsThrows;

    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/pig-shares-' . bin2hex(random_bytes(6)) . '.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSharesAreKeptNewestFirstPrivatelyAndTheExpiredDropOut(): void
    {
        $records = new ShareRecords($this->path);
        $this->assertSame([], $records->active());

        $old = ['id' => 'aa11', 'url' => 'https://pigagent.dev/s/aa11#k1', 'deleteToken' => 't1', 'expiresAt' => time() + 60, 'title' => 'first'];
        $new = ['id' => 'bb22', 'url' => 'https://pigagent.dev/s/bb22#k2', 'deleteToken' => 't2', 'expiresAt' => time() + 3600, 'title' => 'second'];
        $records->add($old);
        $records->add($new);

        $this->assertSame([$new, $old], $records->active());
        $this->assertSame(0o600, fileperms($this->path) & 0o777, 'the links carry keys and the tokens delete');
        $this->assertSame([$new], $records->active(time() + 120), 'past its expiry the server has it no more');

        $records->remove('bb22');
        $this->assertSame([$old], $records->active());
    }

    public function testAFileThatIsNotJsonIsSaidSoAndNotOverwritten(): void
    {
        file_put_contents($this->path, '{oops');

        $this->assertThrows(\RuntimeException::class, fn () => (new ShareRecords($this->path))->add(['id' => 'cc33', 'url' => 'u', 'deleteToken' => 't', 'expiresAt' => time() + 60, 'title' => '']));
        $this->assertSame('{oops', file_get_contents($this->path));
    }
}
