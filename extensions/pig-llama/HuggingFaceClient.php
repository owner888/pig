<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use RuntimeException;
use Throwable;

/**
 * Upstream's `extensions/llama/huggingface.ts`: GGUF search on the Hugging Face Hub, a repository's
 * gating and quantizations, and the token the `huggingface_hub` tools leave behind.
 *
 * A search result is upstream's `HuggingFaceModel`, `{id, downloads}`; details are
 * `HuggingFaceModelDetails`, `{id, gated: false|'auto'|'manual', quantizations: list<{name, size?}>}`.
 *
 * @phpstan-type HuggingFaceModel array{id: string, downloads: int|float}
 * @phpstan-type HuggingFaceQuantization array{name: string, size?: int|float}
 * @phpstan-type HuggingFaceModelDetails array{id: string, gated: false|'auto'|'manual', quantizations: list<HuggingFaceQuantization>}
 */
final class HuggingFaceClient
{
    public const string DEFAULT_HUGGING_FACE_URL = 'https://huggingface.co';

    public const string QUANTIZATION_PATTERN = '/(?:^|[-_.])((?:UD-)?(?:IQ\d(?:_[A-Z0-9]+)+|Q\d(?:_[A-Z0-9]+)+|BF16|F16|F32|MXFP\d(?:_[A-Z0-9]+)*))$/iu';

    public const string SHARD_SUFFIX_PATTERN = '/-\d{5}-of-\d{5}$/u';

    private const int REQUEST_TIMEOUT_MS = 15_000;

    private readonly ?string $token;

    private readonly string $baseUrl;

    public function __construct(?string $token = null, string $baseUrl = self::DEFAULT_HUGGING_FACE_URL)
    {
        $this->token = $token;
        $this->baseUrl = (string) preg_replace('#/+$#u', '', $baseUrl);
    }

    /** Upstream's `payloadError()`: the Hub's `error` when it is a non-empty string. */
    private static function payloadError(mixed $payload, string $fallback): string
    {
        if (!is_array($payload)) {
            return $fallback;
        }

        $error = $payload['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : $fallback;
    }

    /** Upstream's `parseRateLimitDelay()`: the `t=` of a `ratelimit` header. */
    private static function parseRateLimitDelay(?string $value): ?int
    {
        return $value !== null && preg_match('/(?:^|;)t=(\d+)/u', $value, $match) === 1 ? (int) $match[1] : null;
    }

    /** Upstream's `readToken()`: a file's trimmed contents, or null when it is missing or empty. */
    private static function readToken(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $token = $contents === false ? '' : trim($contents);

        return $token !== '' ? $token : null;
    }

    /**
     * Upstream's `findHuggingFaceToken()`: `HF_TOKEN`, then `$HF_TOKEN_PATH`, `$HF_HOME/token`,
     * `$XDG_CACHE_HOME/huggingface/token` and `~/.cache/huggingface/token`.
     *
     * @param array<string, string>|null $env the process environment when null
     */
    public static function findHuggingFaceToken(?array $env = null): ?string
    {
        $env ??= getenv();
        $fromEnvironment = trim($env['HF_TOKEN'] ?? '');

        if ($fromEnvironment !== '') {
            return $fromEnvironment;
        }

        $home = $env['HOME'] ?? (getenv('HOME') ?: '');
        $paths = array_filter([
            $env['HF_TOKEN_PATH'] ?? null,
            ($env['HF_HOME'] ?? '') !== '' ? $env['HF_HOME'] . '/token' : null,
            ($env['XDG_CACHE_HOME'] ?? '') !== '' ? $env['XDG_CACHE_HOME'] . '/huggingface/token' : null,
            $home . '/.cache/huggingface/token',
        ], static fn (?string $path): bool => $path !== null && $path !== '');

        foreach (array_unique($paths) as $path) {
            $token = self::readToken($path);

            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Upstream's `request()`: the token as a bearer token, fifteen seconds beside the caller's
     * signal, a 429 as the rate limit with its delay, and any other refusal as the Hub's `error`.
     */
    private function request(string $path, ?AbortSignal $signal = null): mixed
    {
        $headers = [];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        $signal?->throwIfAborted();
        $controller = new AbortController();
        $timedOut = false;
        $timer = Loop::get()->delay(self::REQUEST_TIMEOUT_MS / 1000, static function () use ($controller, &$timedOut): void {
            $timedOut = true;
            $controller->abort('The operation was aborted due to timeout');
        });
        $listener = $signal?->onAbort(static fn (string $reason) => $controller->abort($reason));

        try {
            $response = (new HttpClient())->send(new Request('GET', $this->baseUrl . $path, $headers), $controller->signal);
            $text = $response->body->all();
        } catch (Throwable $error) {
            if ($signal?->aborted() === true) {
                throw $error;
            }

            if ($timedOut) {
                throw new RuntimeException('The operation was aborted due to timeout', previous: $error);
            }

            throw new RuntimeException('fetch failed', previous: $error);
        } finally {
            Loop::get()->cancel($timer);

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }

        $payload = $text === '' ? null : json_decode($text, true);

        if (!$response->isSuccessful()) {
            $fallback = "Hugging Face returned HTTP {$response->status}";

            if ($response->status === 429) {
                // `Number(retry-after) || parseRateLimitDelay(ratelimit)`: a missing or unreadable header is 0.
                $retryAfter = trim((string) $response->header('retry-after'));
                $delay = (is_numeric($retryAfter) ? $retryAfter + 0 : 0) ?: self::parseRateLimitDelay($response->header('ratelimit'));

                throw new RuntimeException($delay ? "Hugging Face rate limit reached; retry in {$delay}s" : 'Hugging Face rate limit reached');
            }

            throw new RuntimeException(self::payloadError($payload, $fallback));
        }

        return $payload;
    }

    /**
     * Upstream's `search()`: twenty GGUF repositories matching $query, most downloaded first.
     *
     * @return list<HuggingFaceModel>
     */
    public function search(string $query, ?AbortSignal $signal = null): array
    {
        $params = http_build_query([
            'search' => $query,
            'filter' => 'gguf',
            'sort' => 'downloads',
            'direction' => '-1',
            'limit' => '20',
        ], '', '&', PHP_QUERY_RFC1738);
        $payload = $this->request("/api/models?{$params}", $signal);

        if (!is_array($payload) || !array_is_list($payload)) {
            throw new RuntimeException('Hugging Face returned invalid search results');
        }

        $models = [];

        foreach ($payload as $value) {
            if (!is_array($value) || !is_string($value['id'] ?? null)) {
                continue;
            }

            $downloads = $value['downloads'] ?? null;
            $models[] = ['id' => $value['id'], 'downloads' => is_int($downloads) || is_float($downloads) ? $downloads : 0];
        }

        return $models;
    }

    /**
     * Upstream's `details()`: the repository's gating and its quantizations — one per GGUF that is
     * not a projector, shards summed, sized when every file is, Q4_K_M first and the rest by size.
     *
     * @return HuggingFaceModelDetails
     */
    public function details(string $id, ?AbortSignal $signal = null): array
    {
        $encodedId = implode('/', array_map(rawurlencode(...), explode('/', $id)));
        $payload = $this->request("/api/models/{$encodedId}?blobs=true", $signal);

        if (!is_array($payload)) {
            throw new RuntimeException('Hugging Face returned invalid model details');
        }

        /** @var array<string, array{total: int|float, complete: bool}> $sizes */
        $sizes = [];

        if (is_array($payload['siblings'] ?? null) && array_is_list($payload['siblings'])) {
            foreach ($payload['siblings'] as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $rfilename = $file['rfilename'] ?? null;

                if (!is_string($rfilename) || !str_ends_with(strtolower($rfilename), '.gguf')) {
                    continue;
                }

                $segments = explode('/', $rfilename);
                $filename = end($segments);

                if (str_starts_with(strtolower($filename), 'mmproj')) {
                    continue;
                }

                $stem = (string) preg_replace(self::SHARD_SUFFIX_PATTERN, '', substr($filename, 0, -5));

                if (preg_match(self::QUANTIZATION_PATTERN, $stem, $match) !== 1 || $match[1] === '') {
                    continue;
                }

                $quantization = strtoupper($match[1]);
                $current = $sizes[$quantization] ?? ['total' => 0, 'complete' => true];
                $size = $file['size'] ?? null;

                if (is_int($size) || is_float($size)) {
                    $current['total'] += $size;
                } else {
                    $current['complete'] = false;
                }

                $sizes[$quantization] = $current;
            }
        }

        $quantizations = [];

        foreach ($sizes as $name => $size) {
            $quantizations[] = ['name' => (string) $name, ...($size['complete'] ? ['size' => $size['total']] : [])];
        }

        usort($quantizations, static function (array $left, array $right): int {
            if ($left['name'] === 'Q4_K_M') {
                return -1;
            }

            if ($right['name'] === 'Q4_K_M') {
                return 1;
            }

            return (($left['size'] ?? PHP_INT_MAX) <=> ($right['size'] ?? PHP_INT_MAX)) ?: strcmp($left['name'], $right['name']);
        });

        $gated = $payload['gated'] ?? null;

        return [
            'id' => is_string($payload['id'] ?? null) ? $payload['id'] : $id,
            'gated' => $gated === 'auto' || $gated === 'manual' ? $gated : false,
            'quantizations' => $quantizations,
        ];
    }
}
