<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\Async\Async;
use Pig\CodingAgent\Logger;
use Throwable;

/**
 * Telling a person's browsers something through their push services, so a phone whose page is
 * frozen — iOS freezes one seconds after it is left — still shows it. `HttpServer` decides when
 * (nobody of theirs is looking at a page); this sends: each subscription a request of its own on
 * the loop, encrypted for that browser (`Encryption`) and signed as this server (`Vapid`).
 *
 * A subscription the push service says is gone (404, 410) is forgotten; any other failure is
 * logged, and the message is not tried again past `Retrying`'s attempts.
 */
final class WebPush
{
    /** How long a push service keeps a message for a browser that is offline, in seconds. */
    public const int TTL = 24 * 3600;

    /** The longest body sent; the whole message must fit one 4 KB record. */
    public const int MAX_BODY = 1000;

    /** @var Closure(): int epoch seconds */
    private readonly Closure $now;

    public function __construct(
        public readonly PushStore $store,
        private readonly HttpTransport $http = new HttpClient(15.0),
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Send `$message` to every browser of `$user`'s, without waiting.
     *
     * @param array{title: string, body: string, tag: string, cwd?: string|null, sessionFile?: string|null} $message
     * @param (Closure(array<string, string>): string)|null $body the body in each browser's own words
     *        (`PushStore::add()`'s labels), in place of `$message`'s
     */
    public function toUser(string $user, array $message, ?Closure $body = null): void
    {
        $subscriptions = $this->store->of($user);

        if ($subscriptions === []) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $text = $body !== null ? $body($subscription['labels'] ?? []) : $message['body'];
            $payload = (string) json_encode([...$message, 'body' => mb_substr($text, 0, self::MAX_BODY)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            Async::spawn(function () use ($subscription, $payload): void {
                try {
                    $this->deliver($subscription, $payload);
                } catch (Throwable $e) {
                    Logger::error('[WebPush] ' . self::host($subscription['endpoint']) . ': ' . $e->getMessage());
                }
            });
        }
    }

    /**
     * One message to one browser; in a fiber.
     *
     * @param array{user: string, endpoint: string, p256dh: string, auth: string, labels?: array<string, string>} $subscription
     * @return int the push service's status
     */
    public function deliver(array $subscription, string $payload): int
    {
        $vapid = $this->store->vapid();
        $body = Encryption::encrypt($payload, Base64Url::decode($subscription['p256dh']), Base64Url::decode($subscription['auth']));

        [$response] = Retrying::fetch($this->http, new Request('POST', $subscription['endpoint'], [
            'Authorization' => $vapid->authorization($subscription['endpoint'], ($this->now)()),
            'TTL' => (string) self::TTL,
            'Urgency' => 'high',
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'Content-Length' => (string) strlen($body),
        ], $body));

        if ($response->status === 404 || $response->status === 410) {
            $this->store->remove($subscription['endpoint']);
        } elseif (!$response->isSuccessful()) {
            Logger::error('[WebPush] ' . self::host($subscription['endpoint']) . " answered {$response->status} {$response->reason}");
        }

        return $response->status;
    }

    /** The push service, not the endpoint: the rest of it is the browser's address there. */
    private static function host(string $endpoint): string
    {
        return (string) parse_url($endpoint, PHP_URL_HOST);
    }
}
