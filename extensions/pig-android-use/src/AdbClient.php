<?php

declare(strict_types=1);

namespace Pig\Extensions\AndroidUse;

use Pig\Tui\Process;
use RuntimeException;

/**
 * 100% pure PHP wrapper around the platform `adb` binary.
 * Zero external interpreters or language bridges.
 */
final class AdbClient
{
    private ?string $binary = null;
    private ?string $defaultSerial = null;
    private ?string $ffmpegPath = null;
    private bool $ffmpegProbed = false;

    public function __construct(?string $binary = null, ?string $defaultSerial = null)
    {
        $this->binary = $binary;
        $this->defaultSerial = $defaultSerial;
    }

    /**
     * Locate the `adb` binary from environment or common platform locations.
     */
    public function binary(): string
    {
        if ($this->binary !== null && $this->binary !== '') {
            return $this->binary;
        }

        $envPath = getenv('ADB_PATH');
        if ($envPath !== false && (string) $envPath !== '' && file_exists((string) $envPath) && is_executable((string) $envPath)) {
            return $this->binary = (string) $envPath;
        }

        // Try standard PATH lookup
        $which = Process::capture(['which', 'adb']);
        if ($which !== null && $which !== '') {
            $candidate = trim($which);
            if (file_exists($candidate) && is_executable($candidate)) {
                return $this->binary = $candidate;
            }
        }

        $home = getenv('HOME') ?: '';
        $candidates = [
            '/opt/homebrew/bin/adb',
            '/usr/local/bin/adb',
            '/usr/bin/adb',
            $home . '/Library/Android/sdk/platform-tools/adb',
            $home . '/Android/Sdk/platform-tools/adb',
        ];

        foreach ($candidates as $cand) {
            if ($cand !== '' && file_exists($cand) && is_executable($cand)) {
                return $this->binary = $cand;
            }
        }

        throw new RuntimeException("Could not find 'adb' binary. Install Android platform-tools or set ADB_PATH.");
    }

    public function isAvailable(): bool
    {
        try {
            $this->binary();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function defaultSerial(): ?string
    {
        return $this->defaultSerial;
    }

    public function setDefaultSerial(?string $serial): void
    {
        $this->defaultSerial = $serial;
    }

    /**
     * Query ADB server version.
     */
    public function version(): ?string
    {
        try {
            $res = $this->executeAdb(['version']);
            if ($res['exit'] === 0) {
                return trim($res['stdout']);
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * List all attached devices (USB and TCP/IP).
     *
     * @return list<array{serial: string, state: string, model: ?string, product: ?string, transport: string}>
     */
    public function devices(): array
    {
        $res = $this->executeAdb(['devices', '-l']);
        if ($res['exit'] !== 0) {
            return [];
        }

        $lines = explode("\n", trim($res['stdout']));
        $devices = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'List of devices attached') || str_starts_with($line, '* daemon')) {
                continue;
            }

            // e.g. "emulator-5554 device product:sdk_gphone64_arm64 model:sdk_gphone64_arm64 device:emu64a transport_id:1"
            // or "192.168.1.100:5555 device product:... model:..."
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $serial = $parts[0];
            $state = $parts[1];

            $model = null;
            $product = null;

            for ($i = 2; $i < count($parts); $i++) {
                if (str_starts_with($parts[$i], 'model:')) {
                    $model = substr($parts[$i], 6);
                } elseif (str_starts_with($parts[$i], 'product:')) {
                    $product = substr($parts[$i], 8);
                }
            }

            $isWifi = str_contains($serial, ':') || preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $serial) === 1;

            $devices[] = [
                'serial' => $serial,
                'state' => $state,
                'model' => $model,
                'product' => $product,
                'transport' => $isWifi ? 'wireless' : 'usb',
            ];
        }

        return $devices;
    }

    /**
     * Connect to a wireless ADB device (IP:port).
     *
     * @return array{ok: bool, message: string}
     */
    public function connect(string $address): array
    {
        $res = $this->executeAdb(['connect', $address]);
        $out = trim($res['stdout'] . "\n" . $res['stderr']);
        $ok = str_contains(strtolower($out), 'connected to') && !str_contains(strtolower($out), 'cannot');
        return ['ok' => $ok, 'message' => $out];
    }

    /**
     * Disconnect wireless ADB device.
     *
     * @return array{ok: bool, message: string}
     */
    public function disconnect(?string $address = null): array
    {
        $args = ['disconnect'];
        if ($address !== null && $address !== '') {
            $args[] = $address;
        }
        $res = $this->executeAdb($args);
        return ['ok' => $res['exit'] === 0, 'message' => trim($res['stdout'] . "\n" . $res['stderr'])];
    }

    /**
     * Execute an arbitrary adb shell command.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function executeShell(string $command, ?string $serial = null, int $timeoutSeconds = 15): array
    {
        return $this->executeAdb(['shell', $command], $serial, $timeoutSeconds);
    }

    /**
     * Execute an adb command with specified arguments.
     *
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function executeAdb(array $args, ?string $serial = null, int $timeoutSeconds = 15): array
    {
        $bin = $this->binary();
        $targetSerial = $serial ?? $this->defaultSerial;

        $cmd = [$bin];
        if ($targetSerial !== null && $targetSerial !== '') {
            $cmd[] = '-s';
            $cmd[] = $targetSerial;
        }

        foreach ($args as $a) {
            $cmd[] = $a;
        }

        [$exit, $stdout, $stderr] = Process::run($cmd, timeout: (float) $timeoutSeconds);
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Capture raw screenshot PNG binary directly via `adb exec-out screencap -p`.
     */
    public function screencapRaw(?string $serial = null, int $timeoutSeconds = 15): ?string
    {
        $bin = $this->binary();
        $targetSerial = $serial ?? $this->defaultSerial;

        $cmd = [$bin];
        if ($targetSerial !== null && $targetSerial !== '') {
            $cmd[] = '-s';
            $cmd[] = $targetSerial;
        }
        $cmd[] = 'exec-out';
        $cmd[] = 'screencap';
        $cmd[] = '-p';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        set_error_handler(static fn (): bool => true);
        try {
            $process = proc_open($cmd, $descriptors, $pipes);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;

            set_error_handler(static fn (): bool => true);
            try {
                $ready = stream_select($r, $w, $e, 0, 100000);
            } finally {
                restore_error_handler();
            }

            if ($ready !== false && $ready > 0) {
                foreach ($r as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            if (!$status['running'] || feof($pipes[1])) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                proc_terminate($process, 9);
                break;
            }
        }

        $stdout .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (strlen($stdout) > 32 && str_starts_with($stdout, "\x89PNG\r\n\x1a\n")) {
            return $stdout;
        }

        return null;
    }

    /**
     * Locate ffmpeg binary for hardware stream decoding.
     */
    public function ffmpegBinary(): ?string
    {
        if ($this->ffmpegProbed) {
            return $this->ffmpegPath;
        }

        $this->ffmpegProbed = true;
        $env = getenv('FFMPEG_PATH');
        if ($env !== false && (string) $env !== '' && file_exists((string) $env) && is_executable((string) $env)) {
            return $this->ffmpegPath = (string) $env;
        }

        $candidates = [
            '/opt/homebrew/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/usr/bin/ffmpeg',
        ];

        foreach ($candidates as $c) {
            if (file_exists($c) && is_executable($c)) {
                return $this->ffmpegPath = $c;
            }
        }

        $which = Process::capture(['which', 'ffmpeg']);
        if ($which !== null && $which !== '') {
            $candidate = trim($which);
            if (file_exists($candidate) && is_executable($candidate)) {
                return $this->ffmpegPath = $candidate;
            }
        }

        return $this->ffmpegPath = null;
    }

    /**
     * Capture a single frame using Android hardware H.264 encoder (MediaCodec)
     * piped directly to ffmpeg. Dramatically faster than software screencap.
     *
     * @return array{
     *     path: string,
     *     mimeType: string,
     *     width: int,
     *     height: int,
     *     raw_width: int,
     *     raw_height: int,
     *     pixel_to_point: array{float, float},
     *     data: string,
     *     method: string
     * }|null
     */
    public function captureHardwareH264(?string $serial = null): ?array
    {
        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            return null;
        }

        $size = $this->screenSize($serial);
        if ($size === null || $size['width'] <= 0 || $size['height'] <= 0) {
            return null;
        }

        $rawW = $size['width'];
        $rawH = $size['height'];
        $fitted = ScreenCapture::fitDimensions($rawW, $rawH);
        // H.264 hardware encoders strictly require even dimensions
        $targetW = max(2, $fitted['width'] & ~1);
        $targetH = max(2, $fitted['height'] & ~1);

        $adbBin = $this->binary();
        $targetSerial = $serial ?? $this->defaultSerial;

        $adbArgs = [$adbBin];
        if ($targetSerial !== null && $targetSerial !== '') {
            $adbArgs[] = '-s';
            $adbArgs[] = $targetSerial;
        }
        $adbArgs[] = 'exec-out';
        $adbArgs[] = 'screenrecord';
        $adbArgs[] = '--output-format=h264';
        $adbArgs[] = '--size';
        $adbArgs[] = "{$targetW}x{$targetH}";
        $adbArgs[] = '--time-limit';
        $adbArgs[] = '1';
        $adbArgs[] = '-';

        $adbProc = null;
        set_error_handler(static fn (): bool => true);
        try {
            $adbProc = proc_open($adbArgs, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $adbPipes);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($adbProc)) {
            return null;
        }

        stream_set_blocking($adbPipes[1], false);
        fclose($adbPipes[0]);
        fclose($adbPipes[2]);

        $data = '';
        $start = microtime(true);
        $lastRead = microtime(true);

        while ((microtime(true) - $start) < 1.2) {
            $r = [$adbPipes[1]];
            $w = null;
            $e = null;

            set_error_handler(static fn (): bool => true);
            try {
                $ready = stream_select($r, $w, $e, 0, 15000);
            } finally {
                restore_error_handler();
            }

            if ($ready !== false && $ready > 0) {
                $chunk = fread($adbPipes[1], 16384);
                if ($chunk !== false && $chunk !== '') {
                    $data .= $chunk;
                    $lastRead = microtime(true);
                    if (strlen($data) >= 49152) {
                        break;
                    }
                }
            } elseif (strlen($data) >= 8192 && (microtime(true) - $lastRead) > 0.04) {
                // Initial keyframe burst completed
                break;
            }

            $status = proc_get_status($adbProc);
            if (!$status['running'] && feof($adbPipes[1])) {
                break;
            }
        }

        proc_terminate($adbProc, 9);
        fclose($adbPipes[1]);
        proc_close($adbProc);

        if (strlen($data) < 1024) {
            return null;
        }

        $paths = ScreenCapture::createArtifactPath();
        $jpgPath = $paths['jpg'];

        $ffCmd = [
            $ffmpeg,
            '-nostdin',
            '-y',
            '-f', 'h264',
            '-i', '-',
            '-vframes', '1',
            '-q:v', (string) ScreenCapture::JPEG_QUALITY,
            $jpgPath,
        ];

        $ffProc = null;
        set_error_handler(static fn (): bool => true);
        try {
            $ffProc = proc_open($ffCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ffPipes);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($ffProc)) {
            return null;
        }

        fwrite($ffPipes[0], $data);
        fclose($ffPipes[0]);
        fclose($ffPipes[1]);
        fclose($ffPipes[2]);
        proc_close($ffProc);

        if (!file_exists($jpgPath) || filesize($jpgPath) < 1024) {
            if (file_exists($jpgPath)) {
                unlink($jpgPath);
            }
            return null;
        }

        $jpgData = (string) file_get_contents($jpgPath);

        return [
            'path' => $jpgPath,
            'mimeType' => 'image/jpeg',
            'width' => $targetW,
            'height' => $targetH,
            'raw_width' => $rawW,
            'raw_height' => $rawH,
            'pixel_to_point' => [
                round($rawW / (float) $targetW, 4),
                round($rawH / (float) $targetH, 4),
            ],
            'data' => $jpgData,
            'method' => 'hardware_h264',
        ];
    }

    /**
     * Capture screen using hardware H.264 stream when available,
     * gracefully falling back to standard screencap.
     *
     * @return array{
     *     path: string,
     *     mimeType: string,
     *     width: int,
     *     height: int,
     *     raw_width: int,
     *     raw_height: int,
     *     pixel_to_point: array{float, float},
     *     data: string,
     *     method: string
     * }|null
     */
    public function captureScreen(?string $serial = null): ?array
    {
        $this->wakeUp($serial);

        // 1. Try hardware accelerated H.264 stream
        $hw = $this->captureHardwareH264($serial);
        if ($hw !== null) {
            return $hw;
        }

        // 2. Fallback to standard screencap PNG
        $raw = $this->screencapRaw($serial);
        if ($raw === null) {
            return null;
        }

        $processed = ScreenCapture::process($raw);
        $processed['method'] = 'screencap_fallback';

        return $processed;
    }

    /**
     * Get device physical screen dimensions.
     *
     * @return array{width: int, height: int}|null
     */
    public function screenSize(?string $serial = null): ?array
    {
        $res = $this->executeShell('wm size', $serial);
        if ($res['exit'] !== 0) {
            return null;
        }

        // Example output:
        // Physical size: 1080x2400
        // Override size: 720x1600
        $output = $res['stdout'];
        if (preg_match('/Override size:\s*(\d+)x(\d+)/i', $output, $m)) {
            return ['width' => (int) $m[1], 'height' => (int) $m[2]];
        }
        if (preg_match('/Physical size:\s*(\d+)x(\d+)/i', $output, $m)) {
            return ['width' => (int) $m[1], 'height' => (int) $m[2]];
        }

        return null;
    }

    /**
     * Get currently focused App package and activity.
     *
     * @return array{package: string, activity: string}|null
     */
    public function currentFocus(?string $serial = null): ?array
    {
        $res = $this->executeShell('dumpsys window | grep -E "mCurrentFocus|mFocusedApp"', $serial);
        if ($res['exit'] !== 0) {
            return null;
        }

        // e.g. "mCurrentFocus=Window{... u0 com.android.settings/com.android.settings.Settings}"
        if (preg_match('/([a-zA-Z0-9._]+)\/([a-zA-Z0-9._]+)/', $res['stdout'], $m)) {
            return ['package' => $m[1], 'activity' => $m[2]];
        }

        return null;
    }

    /**
     * Check if device screen is awake and on.
     */
    public function isScreenOn(?string $serial = null): bool
    {
        $res = $this->executeShell('dumpsys power | grep -E "mWakefulness=|Display Power: state="', $serial);
        if ($res['exit'] !== 0) {
            return true;
        }

        $out = strtolower($res['stdout']);
        return str_contains($out, 'awake') || str_contains($out, 'state=on');
    }

    /**
     * Wake up device screen if asleep.
     */
    public function wakeUp(?string $serial = null): void
    {
        if (!$this->isScreenOn($serial)) {
            // KEYCODE_WAKEUP = 224 and dismiss keyguard
            $this->executeShell('input keyevent 224 && wm dismiss-keyguard', $serial);
            usleep(100000);
        }
    }

    /**
     * Dump UI hierarchy XML from the device.
     */
    public function dumpWindowHierarchy(?string $serial = null, int $timeoutSeconds = 8): ?string
    {
        $tmpXml = '/data/local/tmp/uidump.xml';
        // Run uiautomator dump
        $dumpRes = $this->executeShell("uiautomator dump {$tmpXml}", $serial, $timeoutSeconds);
        if ($dumpRes['exit'] !== 0) {
            return null;
        }

        // Cat file content and cleanup
        $catRes = $this->executeShell("cat {$tmpXml} && rm -f {$tmpXml}", $serial, $timeoutSeconds);
        if ($catRes['exit'] === 0 && str_contains($catRes['stdout'], '<hierarchy')) {
            return $catRes['stdout'];
        }

        return null;
    }
}
