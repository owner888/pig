<?php

declare(strict_types=1);

namespace Pig\Ai;

use InvalidArgumentException;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Async\AbortSignal;
use RuntimeException;

/**
 * A built-in provider's catalog as the catalog server publishes it — upstream's
 * `remote-catalog-provider.ts`, against pig's server: `GET <base>/api/models/providers/<id>?types=chat,image,classifier`
 * answers a JSON array of models in pi's format (`ModelJson`), with `Last-Modified` saying when it
 * was generated and an `ETag` to revalidate with.
 *
 * Upstream points at pi.dev; pig at pigagent.dev, which serves the same shape from pig's own
 * generator, so a catalog newer than the one pig shipped with reaches a pig nobody updated.
 * `PIG_MODEL_CATALOG_URL` names another server, and `off` (or empty) turns the catalog off.
 *
 * This is the protocol half; `Pig\CodingAgent\ModelRefresh` keeps the store, the refresh interval
 * and the "newer than the built-in data" rule, as upstream's `withRemoteCatalog()` does.
 */
final class RemoteCatalog
{
    public const string DEFAULT_BASE_URL = 'https://pigagent.dev';

    /** Upstream's `REMOTE_CATALOG_REFRESH_INTERVAL_MS`: four hours between checks of one provider. */
    public const int REFRESH_INTERVAL_MS = 4 * 60 * 60 * 1000;

    /**
     * Upstream's `REMOTE_CATALOG_MODEL_TYPES`, sent as `?types=` "so the catalog server returns the
     * full-type shard instead of the chat-only one served to clients that predate model types".
     */
    public const array MODEL_TYPES = ['chat', 'image', 'classifier'];

    /** Upstream's `REMOTE_CATALOG_ATTEMPT_TIMEOUT_MS`. */
    private const float TIMEOUT_SECONDS = 4.0;

    private function __construct()
    {
    }

    /** The catalog server, or null when `PIG_MODEL_CATALOG_URL` turns it off. */
    public static function baseUrl(): ?string
    {
        $configured = getenv('PIG_MODEL_CATALOG_URL');

        if ($configured === false) {
            return self::DEFAULT_BASE_URL;
        }

        $configured = trim($configured);

        return $configured === '' || strtolower($configured) === 'off' ? null : $configured;
    }

    /**
     * Upstream's `requestCatalog()`: the provider's catalog, revalidated with `$etag` when there is
     * a cached body behind it. The body is read whatever the status, so the connection is done with.
     *
     * @return array{0: Response, 1: string} the response and its body
     */
    public static function request(string $provider, string $baseUrl, ?string $etag = null, ?HttpClient $http = null, ?AbortSignal $signal = null): array
    {
        $url = rtrim($baseUrl, '/') . '/api/models/providers/' . rawurlencode($provider) . '?types=' . implode(',', self::MODEL_TYPES);
        $response = ($http ?? new HttpClient(timeout: self::TIMEOUT_SECONDS))->send(new Request('GET', $url, [
            'accept' => 'application/json',
            'user-agent' => PigUserAgent::get(),
            ...($etag !== null ? ['if-none-match' => $etag] : []),
        ]), $signal);

        return [$response, $response->body->all()];
    }

    /**
     * Upstream's `readCatalog()`: a successful response's models, each stamped with `$provider`,
     * `Last-Modified` in milliseconds (0 when missing or unreadable) and the `ETag`.
     *
     * @return array{models: list<array<string, mixed>>, lastModified: int, etag?: string}
     * @throws RuntimeException when the body is not a catalog
     */
    public static function read(string $provider, Response $response, string $body): array
    {
        $lastModified = strtotime((string) $response->header('last-modified'));
        $etag = $response->header('etag');

        return [
            'models' => self::parse($provider, json_decode($body, true)),
            'lastModified' => $lastModified === false ? 0 : $lastModified * 1000,
            ...($etag !== null ? ['etag' => $etag] : []),
        ];
    }

    /**
     * Upstream's `parseCatalog()`: an array of models, `{models: [...]}`, or an object of them; an
     * entry with no `id`, or of a type this does not read, left out.
     *
     * @return list<array<string, mixed>>
     * @throws RuntimeException when there is no list of models in it
     */
    public static function parse(string $provider, mixed $value): array
    {
        $entries = match (true) {
            is_array($value) && array_is_list($value) => $value,
            is_array($value) && is_array($value['models'] ?? null) => $value['models'],
            is_array($value) => array_values($value),
            default => throw new RuntimeException("Invalid model catalog for provider \"{$provider}\""),
        };
        $models = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !array_key_exists('id', $entry)) {
                continue;
            }

            if (array_key_exists('type', $entry) && !in_array($entry['type'], self::MODEL_TYPES, true)) {
                continue;
            }

            $models[] = [...$entry, 'provider' => $provider];
        }

        return $models;
    }

    /**
     * The stored entries as models; one pig cannot read (an api it does not speak, a field it
     * needs missing) is left out rather than costing the provider its whole catalog.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<Model|ClassifierModel|ImageModel>
     */
    public static function models(string $provider, array $entries): array
    {
        $models = [];

        foreach ($entries as $entry) {
            try {
                $models[] = ModelJson::decode([...$entry, 'provider' => $provider]);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $models;
    }

    /**
     * Upstream's `isNewer()`: a catalog counts only when it was generated after the data pig
     * shipped with, or when pig does not know when that was.
     */
    public static function isNewer(?int $lastModified, ?int $builtinGeneratedAt): bool
    {
        return $builtinGeneratedAt === null || ($lastModified !== null && $lastModified > $builtinGeneratedAt);
    }
}
