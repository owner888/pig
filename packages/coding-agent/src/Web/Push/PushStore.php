<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

use InvalidArgumentException;
use RuntimeException;

/**
 * What Web Push keeps: this server's VAPID key, made the first time it is asked for, and the
 * browsers that said yes — a subscription each, under the account it was made by ('' with the
 * one password). One file in the agent directory, `web-push.json`, readable by its owner only:
 * the private key signs for this server.
 *
 * A subscription's endpoint is a URL the server will POST to, given by whoever is logged in, so
 * only the push services browsers use are taken (`SERVICES`): anything else would let a page make
 * this server send requests where it likes.
 */
final class PushStore
{
    /** The hosts of the push services, by suffix: Chrome's, Firefox's, Safari's, Edge's. */
    public const array SERVICES = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public function __construct(private readonly string $file)
    {
    }

    public function vapid(): Vapid
    {
        $data = $this->read();

        if (is_string($data['vapid']['public'] ?? null) && is_string($data['vapid']['private'] ?? null)) {
            return new Vapid(Base64Url::decode($data['vapid']['private']), Base64Url::decode($data['vapid']['public']));
        }

        $vapid = Vapid::generate();
        $data['vapid'] = ['public' => Base64Url::encode($vapid->public), 'private' => Base64Url::encode($vapid->private)];
        $this->write($data);

        return $vapid;
    }

    /**
     * Keep the browser's subscription (`PushSubscription.toJSON()`) for `$user`, with the words
     * its page reads a scheduled run's status in. The same endpoint again replaces the one kept,
     * whoever it was for: a browser is one person's at a time.
     *
     * @param array<mixed> $subscription
     * @param array<string, string> $labels
     * @throws InvalidArgumentException when it is not a subscription to a push service
     */
    public function add(string $user, array $subscription, array $labels = []): void
    {
        $endpoint = is_string($subscription['endpoint'] ?? null) ? $subscription['endpoint'] : '';
        $p256dh = is_string($subscription['keys']['p256dh'] ?? null) ? $subscription['keys']['p256dh'] : '';
        $auth = is_string($subscription['keys']['auth'] ?? null) ? $subscription['keys']['auth'] : '';

        if (!self::isPushService($endpoint)) {
            throw new InvalidArgumentException('Not the endpoint of a push service.');
        }

        if (strlen(self::decoded($p256dh)) !== 65 || strlen(self::decoded($auth)) !== 16) {
            throw new InvalidArgumentException('A subscription has a 65-byte p256dh key and a 16-byte auth secret.');
        }

        $data = $this->read();
        $kept = array_values(array_filter($data['subscriptions'] ?? [], static fn (array $s): bool => $s['endpoint'] !== $endpoint));
        $kept[] = ['user' => $user, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'labels' => $labels];
        $data['subscriptions'] = $kept;
        $this->write($data);
    }

    /** Forget `$endpoint`: for `$user` only when given, for anybody when the push service said it is gone. */
    public function remove(string $endpoint, ?string $user = null): void
    {
        $data = $this->read();
        $before = $data['subscriptions'] ?? [];
        $data['subscriptions'] = array_values(array_filter(
            $before,
            static fn (array $s): bool => $s['endpoint'] !== $endpoint || ($user !== null && $s['user'] !== $user),
        ));

        if ($data['subscriptions'] !== $before) {
            $this->write($data);
        }
    }

    /** @return list<array{user: string, endpoint: string, p256dh: string, auth: string, labels?: array<string, string>}> */
    public function of(string $user): array
    {
        return array_values(array_filter($this->read()['subscriptions'] ?? [], static fn (array $s): bool => $s['user'] === $user));
    }

    public static function isPushService(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        $host = strtolower($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        foreach (self::SERVICES as $service) {
            if ($host === $service || str_ends_with($host, '.' . $service)) {
                return true;
            }
        }

        return false;
    }

    private static function decoded(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? '' : $bytes;
    }

    /** @return array{vapid?: array{public: string, private: string}, subscriptions?: list<array{user: string, endpoint: string, p256dh: string, auth: string, labels?: array<string, string>}>} */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $text = file_get_contents($this->file);
        $data = $text === false ? null : json_decode($text, true);

        if (!is_array($data)) {
            throw new RuntimeException("{$this->file} is not JSON; move it aside and the browsers subscribe again.");
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not make {$dir}.");
        }

        // Owner-only before the key is in it, not after.
        if (!is_file($this->file) && file_put_contents($this->file, '') === false) {
            throw new RuntimeException("Could not write {$this->file}.");
        }

        chmod($this->file, 0600);

        if (file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Could not write {$this->file}.");
        }
    }
}
