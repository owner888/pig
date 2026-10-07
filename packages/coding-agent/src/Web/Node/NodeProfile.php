<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Node;

/**
 * NodeProfile — Remote SSH node configuration profile.
 *
 * Matches upstream pi-web-ui node schema:
 * - Connection properties (host, port, username)
 * - Authentication (password, private key path, or ssh-agent)
 * - Host key SHA-256 fingerprint verification
 * - Default remote working directory
 */
final class NodeProfile
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $group = 'Default',
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 22,
        public readonly string $username = 'root',
        public readonly string $auth = 'key', // 'password' | 'key' | 'agent'
        public readonly ?string $keyPath = null,
        public readonly string $defaultDir = '/',
        public ?string $fingerprint = null,
        public ?int $lastConnected = null,
        public readonly ?string $sourceId = null,
        public readonly ?string $sourceKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? bin2hex(random_bytes(16))),
            name: (string) ($data['name'] ?? 'Remote Node'),
            group: (string) ($data['group'] ?? 'Default'),
            host: (string) ($data['host'] ?? '127.0.0.1'),
            port: max(1, min(65535, (int) ($data['port'] ?? 22))),
            username: (string) ($data['username'] ?? 'root'),
            auth: in_array($data['auth'] ?? '', ['password', 'key', 'agent'], true) ? (string) $data['auth'] : 'key',
            keyPath: isset($data['keyPath']) && is_string($data['keyPath']) && $data['keyPath'] !== '' ? $data['keyPath'] : null,
            defaultDir: isset($data['defaultDir']) && is_string($data['defaultDir']) && str_starts_with($data['defaultDir'], '/') ? $data['defaultDir'] : '/',
            fingerprint: isset($data['fingerprint']) && is_string($data['fingerprint']) ? $data['fingerprint'] : null,
            lastConnected: isset($data['lastConnected']) ? (int) $data['lastConnected'] : null,
            sourceId: isset($data['sourceId']) && is_string($data['sourceId']) ? $data['sourceId'] : null,
            sourceKey: isset($data['sourceKey']) && is_string($data['sourceKey']) ? $data['sourceKey'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeFingerprint = true): array
    {
        $arr = [
            'id' => $this->id,
            'name' => $this->name,
            'group' => $this->group,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth' => $this->auth,
            'keyPath' => $this->keyPath,
            'defaultDir' => $this->defaultDir,
            'lastConnected' => $this->lastConnected,
            'sourceId' => $this->sourceId,
            'sourceKey' => $this->sourceKey,
        ];

        if ($includeFingerprint) {
            $arr['fingerprint'] = $this->fingerprint;
        }

        return $arr;
    }
}
