<?php

declare(strict_types=1);

namespace Pig\Extensions\AndroidUse;

use Pig\CodingAgent\Config;
use Pig\Tui\Process;
use RuntimeException;

/**
 * Android screenshot processor: bounded resolution, JPEG compression, and exact point-to-pixel scaling.
 * 100% pure PHP without external language wrappers.
 */
final class ScreenCapture
{
    public const int MAX_SHORT_SIDE = 768;
    public const int MAX_LONG_SIDE = 1568;
    public const int JPEG_QUALITY = 80;

    /**
     * Parse PNG width and height from the IHDR chunk.
     *
     * @return array{width: int, height: int}|null
     */
    public static function pngDimensions(string $bytes): ?array
    {
        if (strlen($bytes) < 24 || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        if (substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }

        $parsed = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
        if ($parsed === false || !isset($parsed['width'], $parsed['height'])) {
            return null;
        }

        $w = (int) $parsed['width'];
        $h = (int) $parsed['height'];

        if ($w <= 0 || $h <= 0 || $w > 20000 || $h > 20000) {
            return null;
        }

        return ['width' => $w, 'height' => $h];
    }

    /**
     * Compute target bounds maintaining aspect ratio.
     *
     * @return array{width: int, height: int, scale: float}
     */
    public static function fitDimensions(
        int $width,
        int $height,
        int $maxShort = self::MAX_SHORT_SIDE,
        int $maxLong = self::MAX_LONG_SIDE
    ): array {
        $short = min($width, $height);
        $long = max($width, $height);

        $scale = min(1.0, $maxShort / (float) $short, $maxLong / (float) $long);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
            'scale' => $scale,
        ];
    }

    /**
     * Process raw screencap bytes into an optimized JPEG with exact coordinate ratios.
     *
     * @return array{
     *     path: string,
     *     mimeType: string,
     *     width: int,
     *     height: int,
     *     raw_width: int,
     *     raw_height: int,
     *     pixel_to_point: array{float, float},
     *     data: string
     * }
     */
    public static function process(string $rawPng): array
    {
        $dims = self::pngDimensions($rawPng);
        if ($dims === null) {
            throw new RuntimeException('Invalid PNG screenshot stream received from ADB.');
        }

        $rawW = $dims['width'];
        $rawH = $dims['height'];
        $fitted = self::fitDimensions($rawW, $rawH);
        $targetW = $fitted['width'];
        $targetH = $fitted['height'];

        $artifactsDir = Config::home() . '/artifacts';
        if (!is_dir($artifactsDir)) {
            mkdir($artifactsDir, 0o700, true);
        }

        $timestamp = gmdate('Ymd\THis\Z');
        $hash = substr(md5($rawPng), 0, 8);
        $baseName = "{$artifactsDir}/android-{$timestamp}-{$hash}";
        $pngPath = "{$baseName}.png";
        $jpgPath = "{$baseName}.jpg";

        file_put_contents($pngPath, $rawPng);

        // Calculate coordinate conversion: [real_x / target_x, real_y / target_y]
        $pixelToPoint = [
            round($rawW / (float) $targetW, 4),
            round($rawH / (float) $targetH, 4),
        ];

        // 1. Try macOS native sips
        if (PHP_OS_FAMILY === 'Darwin' && file_exists('/usr/bin/sips') && is_executable('/usr/bin/sips')) {
            $cmd = [
                '/usr/bin/sips',
                '-s', 'format', 'jpeg',
                '-s', 'formatOptions', (string) self::JPEG_QUALITY,
                '-z', (string) $targetH, (string) $targetW,
                $pngPath,
                '--out', $jpgPath,
            ];
            $res = Process::run($cmd, timeout: 8.0);
            if ($res['exit'] === 0 && file_exists($jpgPath) && filesize($jpgPath) > 32) {
                // Remove temporary original PNG to save disk
                unlink($pngPath);
                $jpgData = (string) file_get_contents($jpgPath);
                return [
                    'path' => $jpgPath,
                    'mimeType' => 'image/jpeg',
                    'width' => $targetW,
                    'height' => $targetH,
                    'raw_width' => $rawW,
                    'raw_height' => $rawH,
                    'pixel_to_point' => $pixelToPoint,
                    'data' => $jpgData,
                ];
            }
        }

        // 2. Try PHP GD extension if available
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            set_error_handler(static fn (): bool => true);
            try {
                $src = imagecreatefromstring($rawPng);
                if ($src !== false) {
                    $dst = imagecreatetruecolor($targetW, $targetH);
                    if ($dst !== false) {
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $rawW, $rawH);
                        imagejpeg($dst, $jpgPath, self::JPEG_QUALITY);
                        imagedestroy($dst);
                        imagedestroy($src);
                        if (file_exists($jpgPath) && filesize($jpgPath) > 32) {
                            unlink($pngPath);
                            $jpgData = (string) file_get_contents($jpgPath);
                            return [
                                'path' => $jpgPath,
                                'mimeType' => 'image/jpeg',
                                'width' => $targetW,
                                'height' => $targetH,
                                'raw_width' => $rawW,
                                'raw_height' => $rawH,
                                'pixel_to_point' => $pixelToPoint,
                                'data' => $jpgData,
                            ];
                        }
                    }
                }
            } finally {
                restore_error_handler();
            }
        }

        // Fallback: return raw PNG
        return [
            'path' => $pngPath,
            'mimeType' => 'image/png',
            'width' => $rawW,
            'height' => $rawH,
            'raw_width' => $rawW,
            'raw_height' => $rawH,
            'pixel_to_point' => [1.0, 1.0],
            'data' => $rawPng,
        ];
    }
}
