<?php

declare(strict_types=1);

namespace Pig\Extensions\GeminiVideo;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Hooks\HookContext;
use RuntimeException;
use Throwable;

/**
 * Hands a local video to Gemini and brings back its answer as text.
 *
 * pig's own, not upstream's: the developer wrote it as a personal extension, and it is bundled
 * because most models only see images while Gemini watches video — motion, audio, on-screen text —
 * so any model pig talks to can now ask about a video through it.
 *
 * Two routes, chosen by `geminiVideo.via` (env `PIG_GEMINI_VIDEO_VIA`):
 *
 * - **`antigravity`** — the Antigravity subscription signed in with `/login antigravity`, through its
 *   Cloud Code Assist endpoint. Already paid for, so `auto` tries it first. It has no Files API, so
 *   the video goes inline and is capped at {@see self::INLINE_LIMIT}.
 * - **`gemini`** — the Gemini developer API with an API key, billed per token. Small videos go
 *   inline; larger ones through the Files API's resumable upload, deleted again afterwards.
 * - **`auto`** (the default) — Antigravity when signed in and the video fits; otherwise, or when that
 *   attempt fails (quota, an expired sign-in), the API key.
 */
final class GeminiVideo
{
    /**
     * The largest video sent inline, on either route. Gemini's documented limit is 20MB per inline
     * *request*, and base64 makes a video a third larger, so 14MB leaves room for the rest of it.
     * For Antigravity the same figure is a conservative guess, not a measured one: nobody has
     * found out how large a request its endpoint takes, and this is borrowed from Gemini's.
     */
    public const int INLINE_LIMIT = 14 * 1024 * 1024;

    public const string DEFAULT_MODEL = 'gemini-3.8-flash';

    public const string DEFAULT_ANTIGRAVITY_MODEL = 'gemini-3.8-flash';

    public const string DEFAULT_PROMPT = 'Describe this video in detail: what happens in it, the actions taken, and any on-screen text or interface.';

    /** The video formats Gemini takes, by extension. */
    public const array MIME_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        '3gp' => 'video/3gpp',
        'flv' => 'video/x-flv',
        'wmv' => 'video/x-ms-wmv',
    ];

    /** What the Antigravity endpoint is told the client is — the string pig-antigravity sends. */
    private const string ANTIGRAVITY_USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

    /** Analysing a long video takes minutes; the client's default read timeout would cut it off. */
    private const float HTTP_TIMEOUT = 600.0;

    /**
     * @param Closure(string): mixed $setting a `geminiVideo.<name>` setting, by name
     */
    public function __construct(
        private readonly Closure $setting,
        private readonly int $inlineLimit = self::INLINE_LIMIT,
        private readonly int $chunkSize = 8 * 1024 * 1024,
        private readonly float $pollInterval = 2.0,
        private readonly float $processingTimeout = 600.0,
    ) {
    }

    /**
     * @param Closure(string): void $progress one line on what is happening now
     * @return array{text: string, model: string, route: string, bytes: int}
     */
    public function analyze(string $path, string $prompt, HookContext $ctx, Closure $progress, ?AbortSignal $signal = null): array
    {
        $resolved = realpath(str_starts_with($path, '/') ? $path : rtrim($ctx->cwd, '/') . '/' . $path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException("No such file: {$path}");
        }

        $mime = self::MIME_TYPES[strtolower(pathinfo($resolved, PATHINFO_EXTENSION))] ?? null;
        if ($mime === null) {
            throw new RuntimeException('Not a video format Gemini takes: .' . pathinfo($resolved, PATHINFO_EXTENSION) . '. Supported: ' . implode(', ', array_keys(self::MIME_TYPES)));
        }

        $bytes = (int) filesize($resolved);
        $route = $this->via();
        $credentials = null;

        // `auto` tries Antigravity first because the subscription is already paid for and the key is
        // billed per token; the key is for when Antigravity cannot take it — not signed in, too
        // large to send inline, or this attempt failed.
        if ($route !== 'gemini') {
            try {
                $credentials = $this->antigravityCredentials($ctx);
            } catch (RuntimeException $e) {
                if ($route === 'antigravity') {
                    throw $e;
                }
            }

            if ($credentials === null && $route === 'antigravity') {
                throw new RuntimeException('Not signed in to Antigravity: run /login antigravity');
            }

            if ($credentials !== null && ($bytes <= $this->inlineLimit || $route === 'antigravity')) {
                try {
                    $answer = $this->viaAntigravity($resolved, $mime, $prompt, $credentials, $progress, $signal);

                    return ['text' => $answer['text'], 'model' => $answer['model'], 'route' => 'antigravity', 'bytes' => $bytes];
                } catch (RuntimeException $e) {
                    if ($route === 'antigravity' || $this->geminiKey($ctx) === null || ($signal?->aborted() ?? false)) {
                        throw $e;
                    }
                    $progress('Antigravity failed, using the Gemini API key instead: ' . mb_substr($e->getMessage(), 0, 80));
                }
            } elseif ($credentials !== null) {
                $progress(sprintf('Over %dMB, too large for Antigravity; using the Gemini API key', $this->megabytes()));
            }
        }

        $key = $this->geminiKey($ctx);

        if ($key === null) {
            throw new RuntimeException(match (true) {
                $route === 'gemini' => 'No Gemini API key: sign in to Google with /login, or set GEMINI_API_KEY',
                $credentials !== null => sprintf('Over %dMB, which Antigravity cannot take inline; a larger video needs a Gemini API key (Files API), or compress it first', $this->megabytes()),
                default => 'Nothing to send it with: /login antigravity for the Antigravity subscription, or sign in to Google with /login (or set GEMINI_API_KEY)',
            });
        }

        return $this->viaGemini($resolved, $mime, $prompt, $key, $progress, $signal) + ['bytes' => $bytes];
    }

    /** `auto`, `gemini` or `antigravity`. */
    public function via(): string
    {
        $via = strtolower($this->option('PIG_GEMINI_VIDEO_VIA', 'via', 'auto'));
        if (!in_array($via, ['auto', 'gemini', 'antigravity'], true)) {
            throw new RuntimeException("geminiVideo.via is auto, gemini or antigravity, not {$via}");
        }

        return $via;
    }

    /**
     * Antigravity takes runtime ids, not the catalogue's: `gemini-3.8-flash` goes out as
     * `gemini-3.8-flash-medium`, and the plain id is answered 404 — measured live. pig-antigravity's
     * routing table says how, and follows Antigravity when it changes; without that extension loaded,
     * or for an id it does not know, the id goes out as written.
     */
    public static function wireModel(string $model): string
    {
        if (class_exists(\PigAntigravity\Routing::class)) {
            try {
                return \PigAntigravity\Routing::resolve($model, 'medium')[0];
            } catch (Throwable) {
            }
        }

        return $model;
    }

    // ---- the Gemini developer API -------------------------------------------------------------

    /** @return array{text: string, model: string, route: string} */
    private function viaGemini(string $path, string $mime, string $prompt, string $key, Closure $progress, ?AbortSignal $signal): array
    {
        $model = $this->option('PIG_GEMINI_VIDEO_MODEL', 'model', self::DEFAULT_MODEL);
        $uploaded = null;

        try {
            $part = $this->videoPart($path, $mime, $key, $progress, $signal, $uploaded);
            $progress("{$model} is watching…");

            $answer = $this->call(new Request(
                'POST',
                $this->baseUrl() . '/v1beta/models/' . rawurlencode($model) . ':generateContent',
                ['x-goog-api-key' => $key, 'content-type' => 'application/json'],
                (string) json_encode(['contents' => [['role' => 'user', 'parts' => [$part, ['text' => $prompt]]]]]),
            ), $signal)['json'];
        } finally {
            if ($uploaded !== null) {
                try {
                    $this->call(new Request('DELETE', $this->baseUrl() . '/v1beta/' . $uploaded, ['x-goog-api-key' => $key]), null);
                } catch (Throwable) {
                    // Not worth failing for: Google deletes an uploaded file after 48 hours anyway.
                }
            }
        }

        $text = trim(self::text($answer));
        if ($text === '') {
            $reason = $answer['candidates'][0]['finishReason'] ?? $answer['promptFeedback']['blockReason'] ?? 'empty answer';

            throw new RuntimeException("Gemini gave no answer ({$reason})");
        }

        return ['text' => $text, 'model' => $model, 'route' => 'gemini'];
    }

    /**
     * The video as a request part: inline when small, otherwise uploaded through the Files API a
     * chunk at a time — only one chunk is in memory, because `HttpClient` takes a string body and
     * cannot stream a file — then waited on until Gemini has processed it.
     *
     * @return array<string, array<string, string>>
     */
    private function videoPart(string $path, string $mime, string $key, Closure $progress, ?AbortSignal $signal, ?string &$uploaded): array
    {
        $size = (int) filesize($path);

        if ($size <= $this->inlineLimit) {
            return ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($this->read($path))]];
        }

        $headers = ['x-goog-api-key' => $key];
        $start = $this->call(new Request('POST', $this->baseUrl() . '/upload/v1beta/files', $headers + [
            'content-type' => 'application/json',
            'x-goog-upload-protocol' => 'resumable',
            'x-goog-upload-command' => 'start',
            'x-goog-upload-header-content-length' => (string) $size,
            'x-goog-upload-header-content-type' => $mime,
        ], (string) json_encode(['file' => ['display_name' => basename($path)]])), $signal);

        $uploadUrl = $start['headers']['x-goog-upload-url'] ?? null;
        if (!is_string($uploadUrl) || $uploadUrl === '') {
            throw new RuntimeException('Gemini gave no upload address');
        }

        $handle = fopen($path, 'rb') ?: throw new RuntimeException("Cannot read {$path}");
        $file = null;

        try {
            for ($offset = 0; $offset < $size;) {
                $signal?->throwIfAborted();
                $chunk = fread($handle, $this->chunkSize);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException("Reading {$path} failed partway");
                }

                $last = $offset + strlen($chunk) >= $size;
                $result = $this->call(new Request('POST', $uploadUrl, $headers + [
                    'x-goog-upload-command' => $last ? 'upload, finalize' : 'upload',
                    'x-goog-upload-offset' => (string) $offset,
                ], $chunk), $signal);

                $offset += strlen($chunk);
                $progress(sprintf('Uploading %d%%', (int) floor($offset * 100 / $size)));

                if ($last) {
                    $file = $result['json']['file'] ?? null;
                }
            }
        } finally {
            fclose($handle);
        }

        if (!is_array($file) || !is_string($file['name'] ?? null)) {
            throw new RuntimeException('The upload finished, but Gemini said nothing about the file');
        }

        $uploaded = $file['name'];
        $deadline = microtime(true) + $this->processingTimeout;

        while (($file['state'] ?? 'PROCESSING') === 'PROCESSING') {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Gemini took too long to process the video');
            }

            $progress('Gemini is processing the video…');
            Async::delay($this->pollInterval);
            $signal?->throwIfAborted();
            $file = $this->call(new Request('GET', $this->baseUrl() . '/v1beta/' . $file['name'], $headers), $signal)['json'];
        }

        if (($file['state'] ?? '') !== 'ACTIVE') {
            throw new RuntimeException('Gemini could not process the video: ' . ($file['error']['message'] ?? $file['state'] ?? 'unknown state'));
        }

        return ['file_data' => ['mime_type' => $file['mimeType'] ?? $mime, 'file_uri' => $file['uri']]];
    }

    /**
     * One request read whole and decoded; anything but 2xx throws with Gemini's own message.
     *
     * @return array{json: array<string, mixed>, headers: array<string, string>}
     */
    private function call(Request $request, ?AbortSignal $signal): array
    {
        $response = (new HttpClient(timeout: self::HTTP_TIMEOUT))->send($request, $signal);
        $body = $response->body->all();
        $json = json_decode($body, true);

        if (!$response->isSuccessful()) {
            $this->debug('gemini', $request->url, '-', $response->status, $body, [$request->headers['x-goog-api-key'] ?? null]);
            $message = is_array($json) ? ($json['error']['message'] ?? null) : null;

            throw new RuntimeException("Gemini answered {$response->status}: " . ($message ?? trim(mb_substr($body, 0, 300))));
        }

        return ['json' => is_array($json) ? $json : [], 'headers' => $response->headers];
    }

    private function baseUrl(): string
    {
        $url = getenv('PIG_GEMINI_BASE_URL');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://generativelanguage.googleapis.com', '/');
    }

    /** pig's own credentials for `google` first — `/login`, `models.json` — then the environment. */
    private function geminiKey(HookContext $ctx): ?string
    {
        try {
            $key = $ctx->modelRegistry->getProviderAuth('google')?->auth->apiKey;
            if (is_string($key) && $key !== '') {
                return $key;
            }

            $known = $ctx->modelRegistry->find('google', $this->option('PIG_GEMINI_VIDEO_MODEL', 'model', self::DEFAULT_MODEL));
            if ($known !== null) {
                $key = $ctx->apiKey($known);
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            }
        } catch (Throwable) {
            // Google not set up in pig is not an error; the environment may have a key.
        }

        foreach (['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GENAI_API_KEY'] as $name) {
            $key = getenv($name);
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }

    // ---- Antigravity --------------------------------------------------------------------------

    /**
     * The token and Cloud project, through pig's `Auth` so an expired OAuth token is renewed here as
     * it is for pig-antigravity's own requests. Null when not signed in; signed in but not renewable
     * is an error.
     *
     * @return array{token: string, project: string}|null
     */
    private function antigravityCredentials(HookContext $ctx): ?array
    {
        try {
            $raw = $ctx->modelRegistry->getProviderAuth('antigravity')?->auth->apiKey;
        } catch (Throwable $e) {
            throw new RuntimeException('Renewing the Antigravity sign-in failed; try /login antigravity again: ' . $e->getMessage());
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_string($project) || $project === '') {
            throw new RuntimeException('The Antigravity credential has no token and Cloud project; try /login antigravity again');
        }

        return ['token' => $token, 'project' => $project];
    }

    /**
     * Inline, the configured model and then older ones when a model is not there (404 on both
     * methods), `streamGenerateContent` when `generateContent` is 404, each endpoint in turn.
     *
     * @param array{token: string, project: string} $credentials
     * @return array{text: string, model: string}
     */
    private function viaAntigravity(string $path, string $mime, string $prompt, array $credentials, Closure $progress, ?AbortSignal $signal): array
    {
        if ((int) filesize($path) > $this->inlineLimit) {
            throw new RuntimeException(sprintf(
                'Antigravity takes videos up to %dMB, sent inline; this one is %.1fMB. A larger video needs a Gemini API key (Files API), or compress it first',
                $this->megabytes(),
                filesize($path) / 1024 / 1024,
            ));
        }

        $parts = [['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($this->read($path))]], ['text' => $prompt]];
        $models = array_values(array_unique([
            $this->option('PIG_GEMINI_VIDEO_AG_MODEL', 'antigravityModel', self::DEFAULT_ANTIGRAVITY_MODEL),
            'gemini-3.5-flash',
            'gemini-3-flash',
        ]));
        $headers = [
            'authorization' => "Bearer {$credentials['token']}",
            'content-type' => 'application/json',
            'user-agent' => self::ANTIGRAVITY_USER_AGENT,
        ];
        $http = new HttpClient(timeout: self::HTTP_TIMEOUT);
        $lastError = 'No endpoint answered';

        foreach ($models as $model) {
            $progress("Antigravity · {$model} is watching…");
            $body = (string) json_encode([
                'project' => $credentials['project'],
                'model' => self::wireModel($model),
                'request' => ['contents' => [['role' => 'user', 'parts' => $parts]]],
                'requestType' => 'agent',
                'userAgent' => 'antigravity',
                'requestId' => sprintf('agent/%d/%s/1', (int) (microtime(true) * 1000), bin2hex(random_bytes(16))),
            ]);
            $modelMissing = false;

            foreach ($this->antigravityEndpoints() as $endpoint) {
                $signal?->throwIfAborted();
                $url = $endpoint . '/v1internal:generateContent';

                try {
                    $response = $http->send(new Request('POST', $url, $headers, $body), $signal);

                    if ($response->status === 404) {
                        $this->debug('antigravity', $url, $model, 404, $response->body->all(), [$credentials['token']]);
                        $url = $endpoint . '/v1internal:streamGenerateContent?alt=sse';
                        $response = $http->send(new Request('POST', $url, $headers + ['accept' => 'text/event-stream'], $body), $signal);
                    }
                } catch (Throwable $e) {
                    if ($signal?->aborted() ?? false) {
                        throw $e;
                    }
                    $lastError = $e->getMessage();

                    continue;
                }

                $raw = $response->body->all();

                if (!$response->isSuccessful()) {
                    $this->debug('antigravity', $url, $model, $response->status, $raw, [$credentials['token']]);
                    $message = json_decode($raw, true)['error']['message'] ?? trim(mb_substr($raw, 0, 300));
                    $message = str_replace($credentials['token'], '[redacted]', (string) $message);
                    $lastError = "Antigravity answered {$response->status}: {$message}";

                    if ($response->status === 429) {
                        throw new RuntimeException("Antigravity quota used up or rate limited (429): {$message}");
                    }
                    if ($response->status === 404) {
                        $modelMissing = true;
                        break;
                    }
                    if (in_array($response->status, [401, 403], true)) {
                        throw new RuntimeException("{$lastError} (the sign-in may have lapsed; try /login antigravity again)");
                    }

                    continue;
                }

                $answer = self::antigravityText($raw);
                if (str_contains($answer['text'], 'is no longer available. Please switch')) {
                    $lastError = "Model {$model} has been retired";
                    $modelMissing = true;
                    break;
                }
                if ($answer['text'] === '') {
                    throw new RuntimeException('Antigravity gave no answer (' . ($answer['reason'] ?? 'empty answer') . ')');
                }

                return ['text' => $answer['text'], 'model' => $model];
            }

            if (!$modelMissing) {
                break;
            }
        }

        throw new RuntimeException($lastError);
    }

    /** @return list<string> pig-antigravity's `QuotaClient::ENDPOINTS`, in its order */
    private function antigravityEndpoints(): array
    {
        $url = getenv('PIG_GEMINI_VIDEO_AG_BASE_URL');
        if (is_string($url) && $url !== '') {
            return [rtrim($url, '/')];
        }

        return [
            'https://daily-cloudcode-pa.googleapis.com',
            'https://cloudcode-pa.googleapis.com',
            'https://daily-cloudcode-pa.sandbox.googleapis.com',
        ];
    }

    /**
     * The answer's text from plain JSON or SSE (`data:` lines), its candidates wrapped in `response`
     * (Cloud Code Assist's envelope) or at the top; thinking left out.
     *
     * @return array{text: string, reason: ?string}
     */
    private static function antigravityText(string $raw): array
    {
        $payloads = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $json = trim(substr($line, 5));
                if ($json !== '' && $json !== '[DONE]') {
                    $payloads[] = $json;
                }
            }
        }

        $texts = [];
        $reason = null;

        foreach ($payloads === [] ? [$raw] : $payloads as $payload) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (is_string($decoded['error']['message'] ?? null)) {
                throw new RuntimeException('Antigravity answered with an error: ' . $decoded['error']['message']);
            }

            $response = is_array($decoded['response'] ?? null) ? $decoded['response'] : $decoded;
            $reason = $response['candidates'][0]['finishReason'] ?? $response['promptFeedback']['blockReason'] ?? $reason;
            $texts[] = self::text($response);
        }

        return ['text' => trim(implode('', $texts)), 'reason' => is_string($reason) ? $reason : null];
    }

    // ---- shared -------------------------------------------------------------------------------

    /**
     * The answer's text parts joined, thinking left out — untrimmed, because a streamed answer is
     * this called once per event and the spaces between events are the answer's.
     *
     * @param array<string, mixed> $answer
     */
    private static function text(array $answer): string
    {
        $texts = [];
        foreach ($answer['candidates'][0]['content']['parts'] ?? [] as $piece) {
            if (is_string($piece['text'] ?? null) && empty($piece['thought'])) {
                $texts[] = $piece['text'];
            }
        }

        return implode('', $texts);
    }

    /** The environment first, then `geminiVideo.<name>` in the settings, then the default. */
    private function option(string $env, string $setting, string $default): string
    {
        $value = getenv($env);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = ($this->setting)($setting);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);

        return $bytes !== false ? $bytes : throw new RuntimeException("Cannot read {$path}");
    }

    private function megabytes(): int
    {
        return intdiv($this->inlineLimit, 1024 * 1024);
    }

    /**
     * With `PIG_GEMINI_VIDEO_DEBUG=1`, a failed response is written down — status and the first 2KB
     * of the body — for someone to send back when it fails against the real service. Never the
     * request, which holds the video, and never a token or key, even echoed back in the answer.
     *
     * @param list<string|null> $secrets
     */
    private function debug(string $route, string $url, string $model, int $status, string $body, array $secrets): void
    {
        if (getenv('PIG_GEMINI_VIDEO_DEBUG') !== '1') {
            return;
        }

        // A log that cannot be written is not worth failing the analysis over.
        $dir = Config::home() . '/logs';
        if ((!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) || !is_writable($dir)) {
            return;
        }

        $text = mb_strcut($body, 0, 2048);
        foreach (array_filter($secrets, static fn (?string $secret): bool => $secret !== null && $secret !== '') as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }
        $text = (string) preg_replace(['/ya29\.[A-Za-z0-9._-]+/', '/AIza[0-9A-Za-z_-]{20,}/'], '[redacted]', $text);
        $url = (string) preg_replace('/([?&]key=)[^&]+/', '$1[redacted]', $url);

        file_put_contents(
            "{$dir}/gemini-video-debug.log",
            sprintf("[%s] %s %s model=%s status=%d\n%s\n\n", date('c'), $route, $url, $model, $status, $text),
            FILE_APPEND,
        );
    }
}
