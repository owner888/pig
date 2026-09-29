<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Antigravity;

use InvalidArgumentException;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortSignal;
use RuntimeException;
use Throwable;

/**
 * Image generation via Antigravity Gemini Image models.
 */
final class ImageGenerator
{
    public const string DEFAULT_MODEL = 'gemini-3.1-flash-image';

    public const array MODEL_FALLBACKS = [
        'gemini-3.1-flash-image',
        'gemini-3-pro-image',
        'gemini-3-pro-image-preview',
    ];

    public const array ASPECT_RATIOS = [
        '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9',
    ];

    public const string DEFAULT_IMAGE_DIR = '.pig/generated-images';

    private const string USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

    private const string SYSTEM_INSTRUCTION =
        'You are an AI image generator. Generate images based on user descriptions. Focus on creating high-quality, visually appealing images that match the user\'s request.';

    public function __construct(
        private readonly ?HttpClient $http = null,
        private readonly ?string $endpoint = null,
    ) {
    }

    /**
     * Generate an image using Antigravity.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function generate(
        string $token,
        string $projectId,
        string $prompt,
        string $aspectRatio = '1:1',
        string $model = self::DEFAULT_MODEL,
        ?string $targetPath = null,
        string $cwd = '.',
        ?AbortSignal $signal = null,
    ): GeneratedImageResult {
        $cleanPrompt = trim($prompt);
        if ($cleanPrompt === '') {
            throw new InvalidArgumentException('Prompt cannot be empty.');
        }

        if (strlen($cleanPrompt) > 8000) {
            throw new InvalidArgumentException('Prompt exceeds maximum length of 8000 characters.');
        }

        $cleanRatio = trim($aspectRatio);
        if (!in_array($cleanRatio, self::ASPECT_RATIOS, true)) {
            throw new InvalidArgumentException(
                "Unsupported aspect ratio: '{$cleanRatio}'. Use one of " . implode(', ', self::ASPECT_RATIOS) . '.',
            );
        }

        $requestedModel = trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;
        $modelsToTry = array_values(array_unique([$requestedModel, ...self::MODEL_FALLBACKS]));

        $candidates = $this->endpoint !== null ? [$this->endpoint] : QuotaClient::ENDPOINTS;
        $http = $this->http ?? new HttpClient(60.0);

        $lastError = 'No endpoint available';
        $extractedImages = [];
        $extractedTexts = [];
        $usedModel = $requestedModel;

        foreach ($modelsToTry as $currentModel) {
            $body = [
                'project' => $projectId,
                'model' => $currentModel,
                'request' => [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $cleanPrompt]]],
                    ],
                    'systemInstruction' => [
                        'role' => 'user',
                        'parts' => [['text' => self::SYSTEM_INSTRUCTION]],
                    ],
                    'generationConfig' => [
                        'imageConfig' => ['aspectRatio' => $cleanRatio],
                        'candidateCount' => 1,
                    ],
                ],
                'requestType' => 'agent',
                'userAgent' => 'antigravity',
                'requestId' => sprintf('agent/%s/%d/%s/1', bin2hex(random_bytes(4)), (int) (microtime(true) * 1000), bin2hex(random_bytes(6))),
            ];

            foreach ($candidates as $endpoint) {
                $url = rtrim($endpoint, '/') . '/v1internal:streamGenerateContent?alt=sse';
                $headers = [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                    'User-Agent' => self::USER_AGENT,
                ];

                try {
                    $encoded = json_encode($body) ?: '{}';
                    $response = $http->send(new Request('POST', $url, $headers, $encoded), $signal);

                    if (!$response->isSuccessful()) {
                        $errText = $response->body->all();
                        $lastError = "Image generation failed ({$response->status}): " . substr($errText, 0, 300);
                        if ($response->status === 404) {
                            // Try next model if 404
                            break;
                        }
                        continue;
                    }

                    [$extractedImages, $extractedTexts] = self::parseResponse($response->body->all());

                    if ($extractedImages !== []) {
                        $usedModel = $currentModel;
                        break 2;
                    }
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
        }

        if ($extractedImages === []) {
            throw new RuntimeException("Image generation failed: {$lastError}");
        }

        $savedPaths = [];
        $root = realpath($cwd) ?: $cwd;

        foreach ($extractedImages as $idx => $img) {
            $mime = $img['mimeType'];
            $data = base64_decode($img['data'], true);
            if ($data === false) {
                continue;
            }

            $resolvedFile = self::resolveSavePath($root, $targetPath, $mime, count($extractedImages) > 1 ? $idx : null);
            $dir = dirname($resolvedFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($resolvedFile, $data);
            $savedPaths[] = $resolvedFile;
        }

        return new GeneratedImageResult($savedPaths, $extractedImages, $extractedTexts, $usedModel);
    }

    /**
     * @return array{0: list<array{data: string, mimeType: string}>, 1: list<string>}
     */
    private static function parseResponse(string $raw): array
    {
        $images = [];
        $texts = [];

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

        return [$images, $texts];
    }

    public static function resolveSavePath(string $root, ?string $requested, string $mimeType, ?int $index = null): string
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

        if ($requested === null || trim($requested) === '') {
            return rtrim($root, '/') . '/' . self::DEFAULT_IMAGE_DIR . '/' . $defaultFilename;
        }

        $trimmed = trim($requested);
        $path = str_starts_with($trimmed, '/') ? $trimmed : rtrim($root, '/') . '/' . $trimmed;

        if (is_dir($path) || str_ends_with($trimmed, '/')) {
            return rtrim($path, '/') . '/' . $defaultFilename;
        }

        return $path;
    }
}
