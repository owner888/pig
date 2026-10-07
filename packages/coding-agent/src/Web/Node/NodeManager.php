<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Node;

use Pig\CodingAgent\Config;
use Pig\Tui\Process;
use RuntimeException;

/**
 * NodeManager — Manages SSH Nodes, Host Key Fingerprints, and SFTP File Operations.
 *
 * Implements:
 * - Persistent node inventory in ~/.pig/agent/nodes.json
 * - Secure credential storage in ~/.pig/agent/nodes-secrets.json (0600)
 * - SHA-256 host key fingerprint detection and verification
 * - SSH PTY terminal command builder
 * - SFTP remote file listing, reading, and writing (up to 512 KiB)
 * - OpenSSH ~/.ssh/config host discovery
 */
final class NodeManager
{
    public const MAX_FILE_SIZE = 524288; // 512 KiB matching upstream pi-web-ui

    private readonly string $nodesFile;
    private readonly string $secretsFile;

    /** @var array<string, NodeProfile> id => NodeProfile */
    private array $nodes = [];

    /** @var array<string, string> id => secret (password or passphrase) */
    private array $secrets = [];

    public function __construct(?string $dataDir = null)
    {
        $dir = $dataDir ?? Config::home();
        $this->nodesFile = $dir . '/nodes.json';
        $this->secretsFile = $dir . '/nodes-secrets.json';

        $this->load();
    }

    public function load(): void
    {
        $this->nodes = [];
        $this->secrets = [];

        if (is_file($this->nodesFile) && is_readable($this->nodesFile)) {
            $raw = file_get_contents($this->nodesFile);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json) && isset($json['nodes']) && is_array($json['nodes'])) {
                foreach ($json['nodes'] as $nodeData) {
                    if (is_array($nodeData) && isset($nodeData['id'])) {
                        $profile = NodeProfile::fromArray($nodeData);
                        $this->nodes[$profile->id] = $profile;
                    }
                }
            }
        }

        if (is_file($this->secretsFile) && is_readable($this->secretsFile)) {
            $raw = file_get_contents($this->secretsFile);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)) {
                foreach ($json as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $this->secrets[$k] = $v;
                    }
                }
            }
        }
    }

    public function save(): void
    {
        $dir = dirname($this->nodesFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $nodesData = [
            'nodes' => array_values(array_map(static fn (NodeProfile $n) => $n->toArray(), $this->nodes)),
        ];

        file_put_contents($this->nodesFile, (string) json_encode($nodesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->secrets !== []) {
            file_put_contents($this->secretsFile, (string) json_encode($this->secrets, JSON_PRETTY_PRINT));
            if (is_file($this->secretsFile)) {
                chmod($this->secretsFile, 0600);
            }
        } elseif (is_file($this->secretsFile)) {
            unlink($this->secretsFile);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNodes(): array
    {
        return array_values(array_map(function (NodeProfile $n): array {
            $arr = $n->toArray();
            $arr['hasSecret'] = isset($this->secrets[$n->id]);
            return $arr;
        }, $this->nodes));
    }

    public function getNode(string $id): ?NodeProfile
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveNode(array $data, ?string $secret = null): NodeProfile
    {
        $node = NodeProfile::fromArray($data);
        $this->nodes[$node->id] = $node;

        if ($secret !== null && $secret !== '') {
            $this->secrets[$node->id] = $secret;
        } elseif ($secret === '') {
            unset($this->secrets[$node->id]);
        }

        $this->save();

        return $node;
    }

    public function deleteNode(string $id): bool
    {
        if (!isset($this->nodes[$id])) {
            return false;
        }

        unset($this->nodes[$id], $this->secrets[$id]);
        $this->save();

        return true;
    }

    public function trustFingerprint(string $id, string $fingerprint): void
    {
        $node = $this->getNode($id);
        if ($node === null) {
            throw new RuntimeException("Node '{$id}' not found.");
        }

        $node->fingerprint = $fingerprint;
        $this->save();
    }

    public function forgetFingerprint(string $id): void
    {
        $node = $this->getNode($id);
        if ($node !== null) {
            $node->fingerprint = null;
            $this->save();
        }
    }

    /**
     * Scans remote host for SSH public key SHA-256 fingerprint.
     */
    public function scanFingerprint(string $host, int $port = 22): ?string
    {
        $safeHost = escapeshellarg($host);
        $safePort = (int) $port;

        $cmd = sprintf('ssh-keyscan -p %d -t ed25519,ecdsa,rsa %s 2>/dev/null | ssh-keygen -lf - 2>/dev/null', $safePort, $safeHost);
        $out = (string) shell_exec($cmd);

        if ($out && preg_match('/SHA256:([a-zA-Z0-9+\/=]+)/', $out, $m)) {
            return 'SHA256:' . $m[1];
        }

        return null;
    }

    /**
     * Builds command arguments for launching an SSH session.
     *
     * @return list<string>
     */
    public function buildSshCommand(NodeProfile $node, ?string $remoteCmd = null, bool $interactive = false): array
    {
        $cmd = ['ssh'];

        $cmd[] = '-p';
        $cmd[] = (string) $node->port;

        $cmd[] = '-o';
        $cmd[] = 'ConnectTimeout=10';

        $cmd[] = '-o';
        $cmd[] = 'ServerAliveInterval=15';

        $cmd[] = '-o';
        $cmd[] = 'ServerAliveCountMax=3';

        if ($node->fingerprint !== null && $node->fingerprint !== '') {
            $cmd[] = '-o';
            $cmd[] = 'StrictHostKeyChecking=yes';
        } else {
            $cmd[] = '-o';
            $cmd[] = 'StrictHostKeyChecking=accept-new';
        }

        if ($node->auth === 'key' && $node->keyPath !== null && $node->keyPath !== '') {
            $expanded = $this->expandPath($node->keyPath);
            $cmd[] = '-i';
            $cmd[] = $expanded;
        } elseif ($node->auth === 'password') {
            // Interactive password prompt
            $cmd[] = '-o';
            $cmd[] = 'PreferredAuthentications=password,keyboard-interactive';
        }

        if ($interactive) {
            $cmd[] = '-tt';
        }

        $cmd[] = sprintf('%s@%s', $node->username, $node->host);

        if ($remoteCmd !== null && $remoteCmd !== '') {
            $cmd[] = $remoteCmd;
        }

        return $cmd;
    }

    /**
     * Lists remote directory files via SSH.
     *
     * @return list<array{name: string, type: string, size: int}>
     */
    public function listRemoteDir(NodeProfile $node, string $path): array
    {
        $safePath = escapeshellarg($path);
        $sshCmd = $this->buildSshCommand($node, sprintf('ls -la -- %s', $safePath));

        [$exitCode, $stdout, $stderr] = Process::runAsync($sshCmd, timeout: 15.0);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($stderr) ?: "SSH command failed with exit code {$exitCode}");
        }

        $entries = [];
        $lines = explode("\n", trim($stdout));

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'total ')) {
                continue;
            }

            if (preg_match('/^([drwxl-]{10})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\d+\s+[\d:]+\s+(.+)$/', $trimmed, $m)) {
                $rawName = $m[3];
                $name = explode(' -> ', $rawName)[0];
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $entries[] = [
                    'name' => $name,
                    'type' => str_starts_with($m[1], 'd') ? 'dir' : 'file',
                    'size' => (int) $m[2],
                ];
            }
        }

        return $entries;
    }

    /**
     * Reads remote text file via SSH (capped at 512 KiB).
     */
    public function readRemoteFile(NodeProfile $node, string $path): string
    {
        $safePath = escapeshellarg($path);
        $sshCmd = $this->buildSshCommand($node, sprintf('cat -- %s', $safePath));

        [$exitCode, $stdout, $stderr] = Process::runAsync($sshCmd, timeout: 15.0);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($stderr) ?: "SSH read failed with exit code {$exitCode}");
        }

        if (strlen($stdout) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('文件超过 512 KiB 限制');
        }

        return $stdout;
    }

    /**
     * Writes remote text file via SSH (capped at 512 KiB).
     */
    public function writeRemoteFile(NodeProfile $node, string $path, string $text): void
    {
        if (strlen($text) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('文件超过 512 KiB 限制');
        }

        $safePath = escapeshellarg($path);
        $sshCmd = $this->buildSshCommand($node, sprintf('cat > %s', $safePath));

        [$exitCode, $stdout, $stderr] = Process::feed($sshCmd, $text, timeout: 15.0);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($stderr) ?: "SSH write failed with exit code {$exitCode}");
        }
    }

    /**
     * Discover hosts from ~/.ssh/config.
     *
     * @return list<array{name: string, host: string, port: int, username: string, keyPath: ?string}>
     */
    public function detectSshConfig(): array
    {
        $configFile = (getenv('HOME') ?: '/') . '/.ssh/config';
        if (!is_file($configFile) || !is_readable($configFile)) {
            return [];
        }

        $content = file_get_contents($configFile);
        if (!is_string($content)) {
            return [];
        }

        $hosts = [];
        $currentHost = null;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^Host\s+(.+)$/i', $line, $m)) {
                $aliases = preg_split('/\s+/', trim($m[1]));
                foreach ($aliases as $alias) {
                    if ($alias !== '*' && !str_contains($alias, '?') && !str_contains($alias, '*')) {
                        $currentHost = [
                            'name' => $alias,
                            'host' => $alias,
                            'port' => 22,
                            'username' => getenv('USER') ?: 'root',
                            'keyPath' => null,
                        ];
                        $hosts[$alias] = &$currentHost;
                        break;
                    }
                }
                continue;
            }

            if ($currentHost !== null) {
                if (preg_match('/^HostName\s+(.+)$/i', $line, $m)) {
                    $currentHost['host'] = trim($m[1]);
                } elseif (preg_match('/^Port\s+(\d+)$/i', $line, $m)) {
                    $currentHost['port'] = (int) $m[1];
                } elseif (preg_match('/^User\s+(.+)$/i', $line, $m)) {
                    $currentHost['username'] = trim($m[1]);
                } elseif (preg_match('/^IdentityFile\s+(.+)$/i', $line, $m)) {
                    $currentHost['keyPath'] = trim($m[1]);
                }
            }
        }

        return array_values($hosts);
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return (getenv('HOME') ?: '/') . '/' . substr($path, 2);
        }

        return $path;
    }
}
