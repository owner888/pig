<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Node\NodeManager;
use Pig\CodingAgent\Web\Node\NodeProfile;

final class NodeManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pig-test-node-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempDir . '/nodes.json')) {
            unlink($this->tempDir . '/nodes.json');
        }
        if (is_file($this->tempDir . '/nodes-secrets.json')) {
            unlink($this->tempDir . '/nodes-secrets.json');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testSaveAndRetrieveNode(): void
    {
        $mgr = new NodeManager($this->tempDir);

        $node = $mgr->saveNode([
            'id' => 'node-1',
            'name' => 'Test Server',
            'group' => 'Prod',
            'host' => '10.0.0.1',
            'port' => 2222,
            'username' => 'admin',
            'auth' => 'key',
            'keyPath' => '~/.ssh/id_ed25519',
        ], secret: 'mypassphrase');

        $this->assertSame('node-1', $node->id);
        $this->assertSame('Test Server', $node->name);
        $this->assertSame(2222, $node->port);

        // Reload fresh from disk
        $mgr2 = new NodeManager($this->tempDir);
        $retrieved = $mgr2->getNode('node-1');
        $this->assertNotNull($retrieved);
        $this->assertSame('Test Server', $retrieved->name);
        $this->assertSame('Prod', $retrieved->group);
        $this->assertSame(2222, $retrieved->port);

        $list = $mgr2->listNodes();
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['hasSecret']);
    }

    public function testTrustAndForgetFingerprint(): void
    {
        $mgr = new NodeManager($this->tempDir);
        $mgr->saveNode([
            'id' => 'node-fp',
            'name' => 'Fingerprint Node',
            'host' => '10.0.0.2',
        ]);

        $this->assertNull($mgr->getNode('node-fp')?->fingerprint);

        $mgr->trustFingerprint('node-fp', 'SHA256:abc123fakefingerprint');
        $this->assertSame('SHA256:abc123fakefingerprint', $mgr->getNode('node-fp')?->fingerprint);

        // Fresh instance retains fingerprint
        $mgr2 = new NodeManager($this->tempDir);
        $this->assertSame('SHA256:abc123fakefingerprint', $mgr2->getNode('node-fp')?->fingerprint);

        $mgr2->forgetFingerprint('node-fp');
        $this->assertNull($mgr2->getNode('node-fp')?->fingerprint);
    }

    public function testBuildSshCommand(): void
    {
        $mgr = new NodeManager($this->tempDir);
        $node = NodeProfile::fromArray([
            'id' => 'node-cmd',
            'name' => 'Cmd Node',
            'host' => 'remote.example.com',
            'port' => 2200,
            'username' => 'deploy',
            'auth' => 'key',
            'keyPath' => '/keys/id_rsa',
            'fingerprint' => 'SHA256:trusted',
        ]);

        $cmd = $mgr->buildSshCommand($node, 'ls -la', interactive: true);

        $this->assertSame('ssh', $cmd[0]);
        $this->assertContains('-p', $cmd);
        $this->assertContains('2200', $cmd);
        $this->assertContains('StrictHostKeyChecking=yes', $cmd);
        $this->assertContains('-i', $cmd);
        $this->assertContains('/keys/id_rsa', $cmd);
        $this->assertContains('-tt', $cmd);
        $this->assertContains('deploy@remote.example.com', $cmd);
        $this->assertContains('ls -la', $cmd);
    }

    public function testFileSizeLimitExceeded(): void
    {
        $mgr = new NodeManager($this->tempDir);
        $node = NodeProfile::fromArray([
            'id' => 'node-limit',
            'name' => 'Limit Node',
            'host' => '127.0.0.1',
        ]);

        $oversizedText = str_repeat('A', NodeManager::MAX_FILE_SIZE + 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('文件超过 512 KiB 限制');

        $mgr->writeRemoteFile($node, '/tmp/huge.txt', $oversizedText);
    }
}
