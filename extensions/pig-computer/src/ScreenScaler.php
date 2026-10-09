<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\CodingAgent\Config;
use Pig\Tui\Process;
use RuntimeException;

/**
 * Desktop screenshot processor: Retina downscaling, JPEG 80 compression,
 * and exact Point-to-Pixel coordinate ratio tracking.
 * 100% pure PHP without external dependencies.
 */
final class ScreenScaler
{
    public const int MAX_LONG_SIDE = 1568;
    public const int MAX_SHORT_SIDE = 980;
    public const int JPEG_QUALITY = 80;

    /**
     * Read PNG width and height from the IHDR chunk.
     *
     * @return array{width: int, height: int}|null
     */
    public static function pngDimensions(string $filePath): ?array
    {
        if (!file_exists($filePath) || filesize($filePath) < 24) {
            return null;
        }

        $f = fopen($filePath, 'rb');
        if ($f === false) {
            return null;
        }

        $header = fread($f, 24);
        fclose($f);

        if ($header === false || strlen($header) < 24 || !str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        if (substr($header, 12, 4) !== 'IHDR') {
            return null;
        }

        $parsed = unpack('Nwidth/Nheight', substr($header, 16, 8));
        if ($parsed === false || !isset($parsed['width'], $parsed['height'])) {
            return null;
        }

        $w = (int) $parsed['width'];
        $h = (int) $parsed['height'];

        return ($w > 0 && $h > 0) ? ['width' => $w, 'height' => $h] : null;
    }

    /**
     * Compute target bounds maintaining aspect ratio.
     *
     * @return array{width: int, height: int, scale: float}
     */
    public static function fitDimensions(
        int $width,
        int $height,
        int $maxLong = self::MAX_LONG_SIDE,
        int $maxShort = self::MAX_SHORT_SIDE
    ): array {
        $long = max($width, $height);
        $short = min($width, $height);

        $scale = min(1.0, $maxLong / (float) $long, $maxShort / (float) $short);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
            'scale' => $scale,
        ];
    }

    /**
     * Process raw screencapture PNG file into an optimized JPEG with point-to-pixel ratio.
     *
     * @param array{width: int, height: int} $logicalPoints
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
    public static function process(string $rawPngPath, array $logicalPoints): array
    {
        $dims = self::pngDimensions($rawPngPath);
        if ($dims === null) {
            throw new RuntimeException("Invalid PNG screenshot file: {$rawPngPath}");
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
        $hash = substr(md5_file($rawPngPath) ?: uniqid(), 0, 8);
        $jpgPath = "{$artifactsDir}/desktop-{$timestamp}-{$hash}.jpg";

        // Calculate model coordinate to logical screen Points: [logicalW / targetW, logicalH / targetH]
        $pixelToPoint = [
            round($logicalPoints['width'] / (float) $targetW, 4),
            round($logicalPoints['height'] / (float) $targetH, 4),
        ];

        // Compress with macOS native sips
        if (file_exists('/usr/bin/sips') && is_executable('/usr/bin/sips')) {
            $cmd = [
                '/usr/bin/sips',
                '-s', 'format', 'jpeg',
                '-s', 'formatOptions', (string) self::JPEG_QUALITY,
                '-z', (string) $targetH, (string) $targetW,
                $rawPngPath,
                '--out', $jpgPath,
            ];
            [$exit] = Process::run($cmd, timeout: 8.0);
            if ($exit === 0 && file_exists($jpgPath) && filesize($jpgPath) > 64) {
                unlink($rawPngPath);
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

        // Fallback: keep original PNG
        $rawBytes = (string) file_get_contents($rawPngPath);
        return [
            'path' => $rawPngPath,
            'mimeType' => 'image/png',
            'width' => $rawW,
            'height' => $rawH,
            'raw_width' => $rawW,
            'raw_height' => $rawH,
            'pixel_to_point' => [
                round($logicalPoints['width'] / (float) $rawW, 4),
                round($logicalPoints['height'] / (float) $rawH, 4),
            ],
            'data' => $rawBytes,
        ];
    }
}
