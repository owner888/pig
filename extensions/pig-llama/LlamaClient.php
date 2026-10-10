<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use RuntimeException;
use Throwable;

/**
 * Upstream's `extensions/llama/client.ts`: the llama.cpp router's own endpoints — the model catalog
 * (`GET /models`), `/props`, load, unload and download, and the `/models/sse` event stream that
 * reports their progress.
 *
 * A model is the router's JSON object as it came, decoded into an array — upstream's
 * `LlamaModelInfo` is an interface over the same object and `list()` returns the objects unchanged:
 *
 * ```
 * {id, aliases?, status: {value, args?, failed?, exit_code?, progress?}, architecture?: {input_modalities?,
 *  output_modalities?}, source?, meta?: {n_ctx?, n_ctx_train?, size?, ftype?}}
 * ```
 *
 * Progress is upstream's `LlamaProgress`, `{message, ratio?, detail?}`.
 *
 * `fetch()` is pig's `HttpClient`, one request per connection. A transport failure is worded as
 * Node's fetch words it — `fetch failed` — and upstream's `AbortSignal.timeout(15_000)` as its
 * `TimeoutError`, "The operation was aborted due to timeout", so `isConnectionError()` reads both as
 * upstream's does.
 *
 * @phpstan-type LlamaModelInfo array{id: string, status: array{value: string, args?: list<string>, failed?: bool, exit_code?: int, progress?: array<string, array{done: int|float, total: int|float}>}, aliases?: list<string>, architecture?: array{input_modalities?: list<string>, output_modalities?: list<string>}, source?: string, meta?: array{n_ctx?: int, n_ctx_train?: int, size?: int, ftype?: string}}
 * @phpstan-type LlamaProgress array{message: string, ratio?: float, detail?: string}
 */
final class LlamaClient
{
    private const int REQUEST_TIMEOUT_MS = 15_000;

    public readonly string $serverUrl;

    private readonly ?string $apiKey;

    public function __construct(string $serverUrl, ?string $apiKey = null)
    {
        $this->serverUrl = self::normalizeLlamaServerUrl($serverUrl);
        $this->apiKey = $apiKey;
    }

    /** Upstream's `errorMessage()`: `payload.error.message` when it is a non-empty string. */
    public static function errorMessage(mixed $payload, string $fallback): string
    {
        if (!is_array($payload)) {
            return $fallback;
        }

        $error = $payload['error'] ?? null;

        if (!is_array($error)) {
            return $fallback;
        }

        $message = $error['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /** Upstream's `isModelInfo()`: a string `id` and a string `status.value`. */
    private static function isModelInfo(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['id'] ?? null)
            && is_array($value['status'] ?? null)
            && is_string($value['status']['value'] ?? null);
    }

    /**
     * Upstream's `linkSignal()`: abort $target when $source aborts. Answers the unlink.
     *
     * @return Closure(): void
     */
    private static function linkSignal(?AbortSignal $source, AbortController $target): Closure
    {
        if ($source === null) {
            return static function (): void {
            };
        }

        if ($source->aborted()) {
            $target->abort($source->reason());

            return static function (): void {
            };
        }

        $id = $source->onAbort(static fn (string $reason) => $target->abort($reason));

        return static fn () => $source->removeListener($id);
    }

    /** Upstream's `sleep()`: wait $ms, or fail with the signal's reason when it aborts first. */
    private static function sleep(int $ms, ?AbortSignal $signal = null): void
    {
        if ($signal?->aborted() === true) {
            throw new AbortError($signal->reason() !== '' ? $signal->reason() : 'Cancelled');
        }

        $done = new Deferred();
        $listener = null;
        $timer = Loop::get()->delay($ms / 1000, static function () use ($done, $signal, &$listener): void {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            if (!$done->isComplete()) {
                $done->complete(null);
            }
        });

        if ($signal !== null) {
            $listener = $signal->onAbort(static function (string $reason) use ($done, $timer): void {
                Loop::get()->cancel($timer);

                if (!$done->isComplete()) {
                    $done->error(new AbortError($reason !== '' ? $reason : 'Cancelled'));
                }
            });
        }

        $done->future->await();
    }

    /**
     * Upstream's `parseLoadProgress()`: the current stage of `progress.stages`, and how far into it.
     *
     * @return LlamaProgress|null
     */
    public static function parseLoadProgress(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $value = $data['progress'] ?? null;

        if (!is_array($value)) {
            return null;
        }

        $stage = is_string($value['current'] ?? null) ? $value['current'] : (is_string($value['stage'] ?? null) ? $value['stage'] : null);
        $stages = is_array($value['stages'] ?? null) && array_is_list($value['stages'])
            ? array_values(array_filter($value['stages'], is_string(...)))
            : [];
        $stageRatio = is_int($value['value'] ?? null) || is_float($value['value'] ?? null) ? max(0.0, min(1.0, (float) $value['value'])) : null;
        $ratio = $stageRatio;

        if ($stage !== null && $stage !== '' && $stages !== []) {
            $index = array_search($stage, $stages, true);

            if ($index !== false) {
                $ratio = ($index + ($stageRatio ?? 0.0)) / count($stages);
            }
        }

        return [
            'message' => $stage !== null && $stage !== '' ? 'Loading ' . str_replace('_', ' ', $stage) : 'Loading model',
            ...($ratio !== null ? ['ratio' => $ratio] : []),
        ];
    }

    /**
     * Upstream's `parseDownloadProgress()`: the files' `done` and `total` summed.
     *
     * @return LlamaProgress|null
     */
    public static function parseDownloadProgress(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $nested = $data['progress'] ?? null;
        $files = is_array($nested) ? $nested : $data;
        $done = 0;
        $total = 0;

        foreach ($files as $value) {
            if (!is_array($value)) {
                continue;
            }

            $entryDone = $value['done'] ?? null;
            $entryTotal = $value['total'] ?? null;

            if (!(is_int($entryDone) || is_float($entryDone)) || !(is_int($entryTotal) || is_float($entryTotal))) {
                continue;
            }

            $done += $entryDone;
            $total += $entryTotal;
        }

        if ($total <= 0) {
            return null;
        }

        return [
            'message' => 'Downloading model',
            'ratio' => $done / $total,
            'detail' => self::formatBytes($done) . ' / ' . self::formatBytes($total),
        ];
    }

    /** Upstream's `formatBytes()`: `B` under a KiB, then two decimals under ten and one from there. */
    public static function formatBytes(int|float $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = $units[0];

        for ($index = 1; $index < count($units) && $value >= 1024; $index++) {
            $value /= 1024;
            $unit = $units[$index];
        }

        return number_format($value, $value >= 10 ? 1 : 2, '.', '') . " {$unit}";
    }

    /**
     * Upstream's `normalizeLlamaServerUrl()`: http or https only, no query or fragment, and the path
     * without its trailing slashes or `/v1` — the router root, as `new URL(...).toString()` writes it.
     */
    public static function normalizeLlamaServerUrl(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $value, $scheme) === 1 && !in_array(strtolower($scheme[1]), ['http', 'https'], true)) {
            throw new RuntimeException('Server URL must use http or https');
        }

        $parts = parse_url($value);

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new RuntimeException('Invalid URL');
        }

        $protocol = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $defaultPort = $protocol === 'https' ? 443 : 80;
        $user = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $path = (string) preg_replace('#/v1$#', '', (string) preg_replace('#/+$#', '', $parts['path'] ?? ''));
        $url = "{$protocol}://{$user}" . strtolower($parts['host']) . ($port !== null && $port !== $defaultPort ? ":{$port}" : '') . ($path !== '' ? $path : '/');

        return (string) preg_replace('#/$#', '', $url);
    }

    /** Upstream's `llamaInferenceUrl()`: the OpenAI-compatible `/v1` under the router root. */
    public static function llamaInferenceUrl(string $serverUrl): string
    {
        return self::normalizeLlamaServerUrl($serverUrl) . '/v1';
    }

    /**
     * Upstream's `request()`: JSON in and out, the key as a bearer token, fifteen seconds beside the
     * caller's signal, and a refusal as the server's `error.message` or `llama.cpp returned HTTP <status>`.
     */
    private function request(string $path, string $method = 'GET', ?string $body = null, ?AbortSignal $signal = null): mixed
    {
        $headers = [];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $signal?->throwIfAborted();
        $controller = new AbortController();
        $timedOut = false;
        $timer = Loop::get()->delay(self::REQUEST_TIMEOUT_MS / 1000, static function () use ($controller, &$timedOut): void {
            $timedOut = true;
            $controller->abort('The operation was aborted due to timeout');
        });
        $unlink = self::linkSignal($signal, $controller);

        try {
            $response = (new HttpClient())->send(new Request($method, $this->serverUrl . $path, $headers, $body), $controller->signal);
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
            $unlink();
        }

        $payload = $text === '' ? null : json_decode($text, true);

        if (!$response->isSuccessful()) {
            throw new RuntimeException(self::errorMessage($payload, "llama.cpp returned HTTP {$response->status}"));
        }

        return $payload;
    }

    /**
     * Upstream's `list()`: the router's catalog, `?reload=1` to have it look at its models again.
     *
     * @return list<LlamaModelInfo>
     */
    public function list(bool $reload = false, ?AbortSignal $signal = null): array
    {
        $payload = $this->request('/models' . ($reload ? '?reload=1' : ''), signal: $signal);

        if (!is_array($payload) || !is_array($payload['data'] ?? null) || !array_is_list($payload['data'])) {
            throw new RuntimeException('llama.cpp returned an invalid model catalog');
        }

        foreach ($payload['data'] as $model) {
            if (!self::isModelInfo($model)) {
                throw new RuntimeException('Server is not running in llama.cpp router mode');
            }
        }

        /** @var list<LlamaModelInfo> */
        return $payload['data'];
    }

    /**
     * Upstream's `props()`: `models_autoload` and `chat_template`, for one model without loading it.
     *
     * @return array{models_autoload?: bool, chat_template?: string}
     */
    public function props(?string $model = null, ?AbortSignal $signal = null): array
    {
        $query = $model !== null && $model !== '' ? '?' . http_build_query(['model' => $model, 'autoload' => 'false'], '', '&', PHP_QUERY_RFC1738) : '';
        $payload = $this->request("/props{$query}", signal: $signal);

        if (!is_array($payload)) {
            return [];
        }

        $modelsAutoload = $payload['models_autoload'] ?? null;
        $chatTemplate = $payload['chat_template'] ?? null;

        return [
            ...(is_bool($modelsAutoload) ? ['models_autoload' => $modelsAutoload] : []),
            ...(is_string($chatTemplate) ? ['chat_template' => $chatTemplate] : []),
        ];
    }

    public function load(string $model, ?AbortSignal $signal = null): void
    {
        $this->request('/models/load', 'POST', self::json(['model' => $model]), $signal);
    }

    public function unload(string $model, ?AbortSignal $signal = null): void
    {
        $this->request('/models/unload', 'POST', self::json(['model' => $model]), $signal);
    }

    /** Upstream's `unloadAndWait()`: unload, then poll every 100ms until the model is gone or unloaded. */
    public function unloadAndWait(string $model, ?AbortSignal $signal = null): void
    {
        $this->unload($model, $signal);

        while (true) {
            $entry = self::find($this->list(signal: $signal), $model);

            if ($entry === null || $entry['status']['value'] === 'unloaded') {
                return;
            }

            self::sleep(100, $signal);
        }
    }

    public function download(string $model, ?AbortSignal $signal = null): void
    {
        $this->request('/models', 'POST', self::json(['model' => $model]), $signal);
    }

    /**
     * Upstream's `watch()`: the router's `/models/sse` stream, each event with a string `model` and
     * `event` handed to $onEvent. "Ignore malformed events; catalog polling remains authoritative."
     *
     * @param Closure(array{model: string, event: string, data?: mixed}): void $onEvent
     */
    public function watch(Closure $onEvent, ?AbortSignal $signal = null): void
    {
        $headers = [];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $response = (new HttpClient())->send(new Request('GET', "{$this->serverUrl}/models/sse", $headers), $signal);

        if (!$response->isSuccessful()) {
            $response->body->close();

            throw new RuntimeException("llama.cpp SSE returned HTTP {$response->status}");
        }

        $buffer = '';

        foreach ($response->body as $chunk) {
            $buffer .= str_replace("\r\n", "\n", $chunk);
            $boundary = strpos($buffer, "\n\n");

            while ($boundary !== false) {
                $frame = substr($buffer, 0, $boundary);
                $buffer = substr($buffer, $boundary + 2);
                $lines = array_filter(explode("\n", $frame), static fn (string $line): bool => str_starts_with($line, 'data:'));
                $data = implode("\n", array_map(static fn (string $line): string => ltrim(substr($line, 5)), $lines));

                if ($data !== '') {
                    $event = json_decode($data, true);

                    if (is_array($event) && is_string($event['model'] ?? null) && is_string($event['event'] ?? null)) {
                        $onEvent($event);
                    }
                }

                $boundary = strpos($buffer, "\n\n");
            }
        }
    }

    /**
     * Upstream's `loadAndWait()`: load, watch the event stream for progress, and poll the catalog
     * every 250ms until the model is loaded or has failed.
     *
     * @param Closure(LlamaProgress): void $onProgress
     * @return LlamaModelInfo
     */
    public function loadAndWait(string $model, Closure $onProgress, ?AbortSignal $signal = null): array
    {
        $watcher = new AbortController();
        $unlink = self::linkSignal($signal, $watcher);
        $eventLoaded = false;
        $eventError = null;

        $this->watchInBackground(static function (array $event) use ($model, $onProgress, &$eventLoaded, &$eventError): void {
            if ($event['model'] !== $model) {
                return;
            }

            if ($event['event'] !== 'model_status' && $event['event'] !== 'status_change') {
                return;
            }

            $data = $event['data'] ?? null;
            $status = is_array($data) ? ($data['status'] ?? null) : null;

            if ($status === 'loaded') {
                $eventLoaded = true;
            }

            if ($status === 'unloaded') {
                $eventError = 'Model failed to load';
            }

            $progress = self::parseLoadProgress($data);

            if ($progress !== null) {
                $onProgress($progress);
            }
        }, $watcher->signal);

        try {
            $this->load($model, $signal);
            $onProgress(['message' => 'Loading model']);

            while (true) {
                if ($signal?->aborted() === true) {
                    throw new AbortError($signal->reason() !== '' ? $signal->reason() : 'Cancelled');
                }

                $entry = self::find($this->list(signal: $signal), $model);

                if ($entry !== null && $entry['status']['value'] === 'loaded') {
                    return $entry;
                }

                if ($eventLoaded && $entry === null) {
                    return ['id' => $model, 'status' => ['value' => 'loaded']];
                }

                if (($entry !== null && ($entry['status']['failed'] ?? false)) || $eventError !== null) {
                    throw new RuntimeException(
                        !isset($entry['status']['exit_code'])
                            ? ($eventError ?? 'Model failed to load')
                            : "Model exited with code {$entry['status']['exit_code']}",
                    );
                }

                self::sleep(250, $signal);
            }
        } finally {
            $unlink();
            $watcher->abort();
        }
    }

    /**
     * Upstream's `downloadAndWait()`: start the download, follow its progress on the event stream and
     * in the catalog every 500ms, and answer the catalog reloaded once the model is there.
     *
     * @param Closure(LlamaProgress): void $onProgress
     * @return list<LlamaModelInfo>
     */
    public function downloadAndWait(string $model, Closure $onProgress, ?AbortSignal $signal = null): array
    {
        $watcher = new AbortController();
        $unlink = self::linkSignal($signal, $watcher);
        $finished = false;
        $failure = null;
        $sawDownloading = false;
        $polls = 0;

        $this->watchInBackground(static function (array $event) use ($model, $onProgress, &$finished, &$failure, &$sawDownloading): void {
            if ($event['model'] !== $model) {
                return;
            }

            if ($event['event'] === 'download_finished') {
                $finished = true;
            }

            if ($event['event'] === 'download_failed') {
                $failure = self::errorMessage($event['data'] ?? null, 'Download failed');
            }

            if ($event['event'] === 'download_progress') {
                $sawDownloading = true;
                $progress = self::parseDownloadProgress($event['data'] ?? null);

                if ($progress !== null) {
                    $onProgress($progress);
                }
            }
        }, $watcher->signal);

        try {
            $this->download($model, $signal);
            $onProgress(['message' => 'Downloading model']);

            while (true) {
                if ($signal?->aborted() === true) {
                    throw new AbortError($signal->reason() !== '' ? $signal->reason() : 'Cancelled');
                }

                if ($failure !== null) {
                    throw new RuntimeException($failure);
                }

                $models = $this->list(signal: $signal);
                $polls++;
                $entry = self::find($models, $model);

                if ($entry !== null && $entry['status']['value'] === 'downloading') {
                    $sawDownloading = true;
                    $progress = self::parseDownloadProgress($entry['status']['progress'] ?? null);

                    if ($progress !== null) {
                        $onProgress($progress);
                    }
                } elseif ($finished || ($entry !== null && ($sawDownloading || $polls >= 2))) {
                    return $this->list(true, $signal);
                }

                self::sleep(500, $signal);
            }
        } finally {
            $unlink();
            $watcher->abort();
        }
    }

    /** Upstream's `void this.watch(...).catch(() => {})`: the stream in its own fiber, its failure ignored. */
    private function watchInBackground(Closure $onEvent, AbortSignal $signal): void
    {
        Async::spawn(function () use ($onEvent, $signal): void {
            try {
                $this->watch($onEvent, $signal);
            } catch (Throwable) {
                // Catalog polling remains authoritative.
            }
        });
    }

    /**
     * @param list<LlamaModelInfo> $models
     * @return LlamaModelInfo|null
     */
    private static function find(array $models, string $id): ?array
    {
        foreach ($models as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
