<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use InvalidArgumentException;
use Pig\Ai\AssistantImages;
use Pig\Ai\Cost;
use Pig\Ai\Extension\ProviderImages;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Pricing;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use RuntimeException;
use Throwable;

/**
 * Image generation via Antigravity's Gemini image models — the provider's `images` implementation
 * (upstream's `ProviderImages`), registered on the `antigravity` provider as its
 * `ProviderImages`, so `Models::generateImages()` (codemode's `$models->generateImages()`) reaches
 * it like any other image model. The `generate_image` tool and `/antigravity.image` go through
 * `Models::generateImages()` too and then save what came back (`save()`): one request path.
 *
 * What it sends is what pi-antigravity's image command sends: one `streamGenerateContent` envelope
 * per attempt, the prompt (and any reference images) as the user turn, an `imageConfig` with the
 * aspect ratio, tried on each of `QuotaClient::ENDPOINTS` and then on the next model of
 * `MODEL_FALLBACKS` after a 404. The model that answered is the result's `model`, so a fallback is
 * never silent. A quota wall on every attempt asks `$failover` for the next stored account, as the
 * chat protocol does (`AntigravityApi::send()`).
 */
final class ImageGenerator implements ProviderImages
{
    public const string DEFAULT_MODEL = 'gemini-3.1-flash-image';

    /**
     * The image models this provider sells, as the extension knows them: id => name. Subscription
     * models like the chat ones (`Models::fallback()`), so they cost nothing per token; they take a
     * prompt and reference images and answer with images and text, as Gemini's image models do.
     */
    public const array MODELS = [
        'gemini-3.1-flash-image' => 'Gemini 3.1 Flash Image (Antigravity)',
        'gemini-3-pro-image' => 'Gemini 3 Pro Image (Antigravity)',
        'gemini-3-pro-image-preview' => 'Gemini 3 Pro Image Preview (Antigravity)',
    ];

    public const array MODEL_FALLBACKS = [
        'gemini-3.1-flash-image',
        'gemini-3-pro-image',
        'gemini-3-pro-image-preview',
    ];

    public const array ASPECT_RATIOS = [
        '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9',
    ];

    public const string DEFAULT_IMAGE_DIR = '.pig/generated-images';

    private const string SYSTEM_INSTRUCTION =
        'You are an AI image generator. Generate images based on user descriptions. Focus on creating high-quality, visually appealing images that match the user\'s request.';

    /**
     * @param string|null $endpoint the one host to try in place of `QuotaClient::ENDPOINTS` — for tests
     * @param (Closure(list<string> $triedAccessTokens): ?string)|null $failover the next stored account's
     *        api key after a quota wall, or null when none is left — `AntigravityApi`'s `$failover`
     */
    public function __construct(
        private readonly ?HttpClient $http = null,
        private readonly ?string $endpoint = null,
        private readonly ?Closure $failover = null,
    ) {
    }

    /** @return list<ImageModel> `MODELS` as the provider's image models */
    public static function models(): array
    {
        return array_map(self::model(...), array_keys(self::MODELS));
    }

    /** One image model of this provider — a registered one, or one the tool was asked for by id. */
    public static function model(string $id): ImageModel
    {
        return new ImageModel(
            $id,
            self::MODELS[$id] ?? $id,
            ImageApi::Extension,
            Models::PROVIDER,
            AntigravityApi::ENDPOINT,
            ['text', 'image'],
            ['image', 'text'],
            new Pricing(),
            inputLimits: \Pig\Ai\Models::inputLimits(Models::PROVIDER, ['text', 'image'], 0),
        );
    }

    /**
     * `ProviderImages`: the prompt is the context's text, any image in it a reference; the aspect
     * ratio is `$options->metadata['aspectRatio']` (default `1:1`); the key is the signed-in account's
     * `{"token", "projectId"}`. Never throws — every failure is an error result.
     */
    #[\Override]
    public function generateImages(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages
    {
        $timestamp = Timestamp::nowMs();

        try {
            [$token, $project] = self::credentials($options->apiKey);
            $prompt = trim(implode("\n", array_map(
                static fn (TextContent $text): string => $text->text,
                array_values(array_filter($context->input, static fn (mixed $item): bool => $item instanceof TextContent)),
            )));

            if ($prompt === '') {
                throw new InvalidArgumentException('Prompt cannot be empty.');
            }

            if (strlen($prompt) > 8000) {
                throw new InvalidArgumentException('Prompt exceeds maximum length of 8000 characters.');
            }

            $ratio = $options->metadata['aspectRatio'] ?? '1:1';
            $ratio = is_string($ratio) ? trim($ratio) : '';

            if (!in_array($ratio, self::ASPECT_RATIOS, true)) {
                throw new InvalidArgumentException(
                    "Unsupported aspect ratio: '{$ratio}'. Use one of " . implode(', ', self::ASPECT_RATIOS) . '.',
                );
            }

            $references = array_values(array_filter($context->input, static fn (mixed $item): bool => $item instanceof ImageContent));
            [$images, $texts, $usedModel, $rawUsage] = $this->request($token, $project, $model->id, $prompt, $references, $ratio, $options);

            $output = [];
            $notes = trim(implode('', $texts));

            if ($notes !== '') {
                $output[] = new TextContent($notes);
            }

            foreach ($images as $image) {
                $output[] = new ImageContent($image['data'], $image['mimeType']);
            }

            return new AssistantImages(
                $model->api,
                $model->provider,
                $usedModel,
                $output,
                StopReason::Stop,
                $timestamp,
                usage: $rawUsage === null ? null : self::priced(GoogleShared::usage($rawUsage), $model->pricing),
            );
        } catch (Throwable $error) {
            return new AssistantImages(
                $model->api,
                $model->provider,
                $model->id,
                [],
                ($options->signal?->aborted() ?? false) ? StopReason::Aborted : StopReason::Error,
                $timestamp,
                errorMessage: AntigravityApi::redactSecrets($error->getMessage()),
            );
        }
    }

    /**
     * The request loop: the model asked for, then `MODEL_FALLBACKS` after a 404; each on every
     * endpoint until one answers with images; and after a quota wall, the next account.
     *
     * @param list<ImageContent> $references
     * @return array{0: list<array{data: string, mimeType: string}>, 1: list<string>, 2: string, 3: array<string, mixed>|null}
     *         the images, the text, the model that answered, Gemini's `usageMetadata`
     */
    private function request(string $token, string $project, string $model, string $prompt, array $references, string $ratio, ImagesOptions $options): array
    {
        $modelsToTry = array_values(array_unique([$model, ...self::MODEL_FALLBACKS]));
        $candidates = $this->endpoint !== null ? [$this->endpoint] : QuotaClient::ENDPOINTS;
        $http = $this->http ?? new HttpClient(60.0);
        $parts = [['text' => $prompt]];

        foreach ($references as $reference) {
            $parts[] = ['inlineData' => ['mimeType' => $reference->mimeType, 'data' => $reference->data]];
        }

        $tried = [$token];

        while (true) {
            $lastError = 'No endpoint available';
            $quotaWall = false;

            foreach ($modelsToTry as $currentModel) {
                $body = [
                    'project' => $project,
                    'model' => $currentModel,
                    'request' => [
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => [
                            'imageConfig' => ['aspectRatio' => $ratio],
                            'candidateCount' => 1,
                        ],
                    ],
                    'requestType' => 'image_gen',
                    'userAgent' => 'antigravity',
                    'requestId' => sprintf('image_gen/%d/%s/1', (int) (microtime(true) * 1000), bin2hex(random_bytes(16))),
                ];

                if ($options->onPayload !== null) {
                    $body = ($options->onPayload)($body, self::model($currentModel)) ?? $body;
                }

                foreach ($candidates as $endpoint) {
                    $directUrl = rtrim($endpoint, '/') . '/v1internal:generateContent';
                    $headers = [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type' => 'application/json',
                        'User-Agent' => Discovery::userAgent(),
                    ];

                    try {
                        $response = $http->send(new Request('POST', $directUrl, $headers, json_encode($body) ?: '{}'), $options->signal);

                        if ($response->status === 404) {
                            $streamUrl = rtrim($endpoint, '/') . '/v1internal:streamGenerateContent?alt=sse';
                            $streamHeaders = [...$headers, 'Accept' => 'text/event-stream'];
                            $response = $http->send(new Request('POST', $streamUrl, $streamHeaders, json_encode($body) ?: '{}'), $options->signal);
                        }

                        if ($options->onResponse !== null) {
                            ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], self::model($currentModel));
                        }

                        if (!$response->isSuccessful()) {
                            $errText = $response->body->all();
                            $lastError = "Image generation failed ({$response->status}): " . substr($errText, 0, 300);
                            $quotaWall = $response->status === 429 && AntigravityApi::isQuotaWall($errText);

                            if ($response->status === 404 || $quotaWall) {
                                // A 404 is the next model's to try; a quota wall every endpoint answers alike.
                                break;
                            }

                            continue;
                        }

                        $quotaWall = false;
                        [$images, $texts, $usage] = self::parseResponse($response->body->all());

                        if ($images !== []) {
                            return [$images, $texts, $currentModel, $usage];
                        }

                        $lastError = 'The response had no image in it' . ($texts !== [] ? ': ' . substr(trim(implode('', $texts)), 0, 300) : '.');
                    } catch (Throwable $e) {
                        if ($options->signal?->aborted() ?? false) {
                            throw $e;
                        }

                        $lastError = $e->getMessage();
                    }
                }

                if ($quotaWall) {
                    break;
                }
            }

            $next = $quotaWall && $this->failover !== null ? ($this->failover)($tried) : null;

            if ($next === null) {
                throw new RuntimeException("Image generation failed: {$lastError}");
            }

            [$token, $project] = self::credentials($next);
            $tried[] = $token;
        }
    }

    /**
     * The token and the project, out of the one string an api key carries — what
     * `AntigravityApi::credentials()` reads, refused in its words.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentials(?string $apiKey): array
    {
        $decoded = $apiKey === null || $apiKey === '' ? null : json_decode($apiKey, true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_string($project) || $project === '') {
            throw new ProviderError(
                'Antigravity needs a token and a Cloud project together, which is what signing in '
                . 'stores. An ordinary Gemini API key cannot be used with antigravity models.',
            );
        }

        return [$token, $project];
    }

    /** The counts at the model's four rates per million, as `OpenRouterImages::parseUsage()` prices them. */
    private static function priced(Usage $usage, Pricing $pricing): Usage
    {
        $cost = [
            $pricing->input / 1_000_000 * $usage->input,
            $pricing->output / 1_000_000 * $usage->output,
            $pricing->cacheRead / 1_000_000 * $usage->cacheRead,
            $pricing->cacheWrite / 1_000_000 * $usage->cacheWrite,
        ];

        return new Usage($usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite, $usage->totalTokens, new Cost(...[...$cost, array_sum($cost)]), $usage->reasoning);
    }

    /**
     * @return array{0: list<array{data: string, mimeType: string}>, 1: list<string>, 2: array<string, mixed>|null}
     */
    private static function parseResponse(string $raw): array
    {
        $images = [];
        $texts = [];
        $usage = null;

        // Response may be SSE (data: {...}) or raw JSON
        $lines = explode("\n", $raw);
        $payloads = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'data:')) {
                $json = trim(substr($trimmed, 5));
                if ($json !== '' && $json !== '[DONE]') {
                    $payloads[] = $json;
                }
            }
        }

        if ($payloads === []) {
            $payloads[] = $raw;
        }

        foreach ($payloads as $p) {
            $decoded = json_decode($p, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                throw new RuntimeException($decoded['error']['message']);
            }

            // The last chunk's metadata is the request's whole count, as Gemini streams it.
            $metadata = $decoded['response']['usageMetadata'] ?? $decoded['usageMetadata'] ?? null;

            if (is_array($metadata)) {
                $usage = $metadata;
            }

            $candidates = $decoded['response']['candidates'] ?? $decoded['candidates'] ?? [];
            if (!is_array($candidates)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $parts = $candidate['content']['parts'] ?? [];
                if (!is_array($parts)) {
                    continue;
                }

                foreach ($parts as $part) {
                    if (!is_array($part)) {
                        continue;
                    }

                    if (isset($part['text']) && is_string($part['text'])) {
                        $texts[] = $part['text'];
                    }

                    if (isset($part['inlineData']['data']) && is_string($part['inlineData']['data'])) {
                        $images[] = [
                            'data' => $part['inlineData']['data'],
                            'mimeType' => (string) ($part['inlineData']['mimeType'] ?? 'image/png'),
                        ];
                    }
                }
            }
        }

        return [$images, $texts, $usage];
    }

    /**
     * Write a result's images to disk — what the `generate_image` tool and `/antigravity.image` do with
     * `Models::generateImages()`'s answer: under `DEFAULT_IMAGE_DIR` unless a file or directory is asked
     * for, numbered when there are several. Always inside `$cwd`, and never through a symbolic link
     * (`writeImage()`).
     *
     * @return list<string> the paths written
     * @throws RuntimeException when an image cannot be written
     */
    public static function save(AssistantImages $result, ?string $targetPath, string $cwd): array
    {
        $images = array_values(array_filter($result->output, static fn (mixed $block): bool => $block instanceof ImageContent));
        $saved = [];

        foreach ($images as $index => $image) {
            $data = base64_decode($image->data, true);

            if ($data === false) {
                throw new RuntimeException("Image " . ($index + 1) . ' of the answer is not valid base64.');
            }

            $file = self::resolveSavePath($cwd, $targetPath, $image->mimeType, count($images) > 1 ? $index : null);
            self::writeImage($file, $data, $cwd);
            $saved[] = $file;
        }

        return $saved;
    }

    /**
     * pi-antigravity's `resolveImageSavePath()`: where an image goes. A path with no extension is a
     * directory to put a named image in; one of several images written to a named file has its
     * number put before the extension, so they do not overwrite each other; and anything that
     * would land outside the working directory — an absolute path elsewhere, a `..` — is refused.
     *
     * Lexical, as upstream's `resolve()`/`relative()` are: nothing on disk is looked at here.
     *
     * @throws RuntimeException when the path is not inside $cwd
     */
    public static function resolveSavePath(string $cwd, ?string $requested, string $mimeType, ?int $index = null): string
    {
        $ext = match (true) {
            str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg') => 'jpg',
            str_contains($mimeType, 'webp') => 'webp',
            str_contains($mimeType, 'gif') => 'gif',
            default => 'png',
        };

        $stamp = date('Y-m-d\TH-i-s');
        $suffix = $index !== null ? '-' . ($index + 1) : '';
        $defaultFilename = "image-{$stamp}{$suffix}.{$ext}";
        $root = self::resolvePath($cwd, '');
        $trimmed = $requested !== null ? trim($requested) : '';
        $target = $trimmed !== ''
            ? self::resolvePath($root, $trimmed)
            : self::resolvePath($root, self::DEFAULT_IMAGE_DIR . '/' . $defaultFilename);

        if (self::relativeInside($root, $target) === null) {
            throw new RuntimeException('Image save path must be inside the working directory.');
        }

        $currentExt = self::extname($target);

        if ($currentExt === '') {
            return "{$target}/{$defaultFilename}";
        }

        if ($index === null) {
            return $target;
        }

        return substr($target, 0, -strlen($currentExt)) . $suffix . $currentExt;
    }

    /**
     * pi-antigravity's `writeImage()`: the bytes into $filePath, which has to be inside $cwd as the
     * disk has it — every directory on the way made one at a time and refused if it is a symbolic
     * link, the file itself refused if it is one. A link inside the working directory is how a path
     * that reads as inside it writes outside it.
     *
     * Node opens with `O_NOFOLLOW`, which PHP's `fopen()` cannot ask for. A file that is not there
     * is created with `x` (exclusive, which does not follow a link either); one that is there is
     * opened and then checked to be the very file `lstat()` saw, before anything is written.
     *
     * @throws RuntimeException when the path leaves $cwd, crosses a link, or cannot be written
     */
    public static function writeImage(string $filePath, string $data, string $cwd): void
    {
        $root = realpath($cwd);

        if ($root === false) {
            throw new RuntimeException("The working directory {$cwd} does not exist.");
        }

        $lexicalRoot = self::resolvePath($cwd, '');
        $rel = self::relativeInside($lexicalRoot, self::resolvePath($lexicalRoot, $filePath));

        if ($rel === null) {
            throw new RuntimeException('Image path must stay inside the working directory');
        }

        $components = explode('/', $rel);
        $name = array_pop($components);
        $parent = $root;

        foreach ($components as $component) {
            $parent .= "/{$component}";

            if (!file_exists($parent) && !is_link($parent)) {
                self::quietly(static fn (): bool => mkdir($parent), "Cannot create {$parent} for the image");
            }

            if (is_link($parent) || !is_dir($parent)) {
                throw new RuntimeException('Image path must not traverse symlinks');
            }
        }

        $target = "{$parent}/{$name}";

        if (is_link($target)) {
            throw new RuntimeException('Image path must not follow symlinks');
        }

        $existed = file_exists($target);
        $handle = self::quietly(static fn () => fopen($target, $existed ? 'r+b' : 'xb'), "Cannot write the image to {$target}");

        try {
            if ($existed) {
                $opened = fstat($handle);
                $named = lstat($target);

                if (is_link($target) || $opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                    throw new RuntimeException('Image path must not follow symlinks');
                }

                ftruncate($handle, 0);
            } else {
                // `0o600`, as upstream opens it: a generated image is the person's own.
                chmod($target, 0o600);
            }

            if (fwrite($handle, $data) !== strlen($data)) {
                throw new RuntimeException("Cannot write the image to {$target}.");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Run a file function that warns and returns false, and throw its warning instead — the
     * warning is where the reason is.
     *
     * @template T
     * @param \Closure(): (T|false) $call
     * @return T
     */
    private static function quietly(\Closure $call, string $what): mixed
    {
        $warning = '';
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new RuntimeException($what . ($warning !== '' ? ": {$warning}" : '.'));
        }

        return $result;
    }

    /** Node's `path.resolve(base, path)` for `/` paths: absolute wins, `.` and `..` are worked out. */
    private static function resolvePath(string $base, string $path): string
    {
        $joined = str_starts_with($path, '/') ? $path : "{$base}/{$path}";

        if (!str_starts_with($joined, '/')) {
            $joined = (getcwd() ?: '') . "/{$joined}";
        }

        $parts = [];

        foreach (explode('/', $joined) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /** $target relative to $root when it is strictly inside it, null otherwise — both already resolved. */
    private static function relativeInside(string $root, string $target): ?string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($target, $prefix) && strlen($target) > strlen($prefix) ? substr($target, strlen($prefix)) : null;
    }

    /** Node's `path.extname()`: from the last dot of the last segment, unless that dot leads it. */
    private static function extname(string $path): string
    {
        $base = basename($path);
        $dot = strrpos($base, '.');

        return $dot === false || $dot === 0 ? '' : substr($base, $dot);
    }
}
