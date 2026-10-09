<?php

declare(strict_types=1);

namespace Pig\Extensions\AndroidUse;

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;

/**
 * Pig Android Use Extension
 *
 * 100% pure PHP controlling physical or emulator Android devices directly via native ADB.
 * Zero Python, zero Node.js, and zero runtime dependencies beyond PHP itself.
 */
return static function (ExtensionApi $pi): void {
    if (!class_exists(AdbClient::class, false)) {
        require_once __DIR__ . '/src/AdbClient.php';
        require_once __DIR__ . '/src/ScreenCapture.php';
        require_once __DIR__ . '/src/InputManager.php';
        require_once __DIR__ . '/src/TreeParser.php';
    }

    $adb = new AdbClient();
    /** @var array{float, float} Last observed pixel-to-point scaling ratio */
    $lastScale = [1.0, 1.0];

    // -------------------------------------------------------------------------
    // Slash Command: /android
    // -------------------------------------------------------------------------
    $pi->registerCommand(
        name: 'android',
        description: 'Android device controller: /android status | connect <ip:port> | screenshot',
        handler: static function (string $args, $ctx) use ($adb, $pi, &$lastScale): void {
            $parts = preg_split('/\s+/', trim($args), 2, PREG_SPLIT_NO_EMPTY);
            $sub = strtolower($parts[0] ?? 'status');
            $param = trim($parts[1] ?? '');

            if ($sub === 'status' || $sub === '') {
                if (!$adb->isAvailable()) {
                    $pi->sendMessage("❌ ADB binary not found. Please install Android platform-tools or set ADB_PATH.");
                    return;
                }

                $ver = $adb->version() ?? 'unknown';
                $devices = $adb->devices();

                if ($devices === []) {
                    $pi->sendMessage("📱 ADB ready ({$ver}), but no Android devices attached.\nConnect your device via USB (enable USB debugging) or run: /android connect <ip:5555>");
                    return;
                }

                $lines = ["📱 Android Devices ({$ver}):"];
                foreach ($devices as $idx => $d) {
                    $star = ($idx === 0) ? ' (active)' : '';
                    $lines[] = sprintf(
                        "  • [%s] %s | %s | %s%s",
                        $d['serial'],
                        $d['model'] ?? 'Device',
                        $d['state'],
                        $d['transport'],
                        $star
                    );
                }

                $screen = $adb->screenSize();
                if ($screen !== null) {
                    $lines[] = "  📐 Screen Resolution: {$screen['width']}x{$screen['height']}";
                }
                $focus = $adb->currentFocus();
                if ($focus !== null) {
                    $lines[] = "  🎯 Foreground: {$focus['package']}/{$focus['activity']}";
                }

                $pi->sendMessage(implode("\n", $lines));
                return;
            }

            if ($sub === 'connect') {
                if ($param === '') {
                    $pi->sendMessage("Usage: /android connect <ip:port> (e.g. 192.168.1.100:5555)");
                    return;
                }
                $res = $adb->connect($param);
                $pi->sendMessage($res['ok'] ? "✅ {$res['message']}" : "❌ {$res['message']}");
                return;
            }

            if ($sub === 'disconnect') {
                $res = $adb->disconnect($param !== '' ? $param : null);
                $pi->sendMessage($res['ok'] ? "✅ {$res['message']}" : "❌ {$res['message']}");
                return;
            }

            if ($sub === 'screenshot') {
                $raw = $adb->screencapRaw();
                if ($raw === null) {
                    $pi->sendMessage("❌ Failed to capture screenshot. Make sure the device is connected, awake and unlocked.");
                    return;
                }

                $processed = ScreenCapture::process($raw);
                $lastScale = $processed['pixel_to_point'];
                $pi->sendMessage(
                    "📸 Screenshot captured: {$processed['width']}x{$processed['height']} " .
                    "(raw {$processed['raw_width']}x{$processed['raw_height']}, " .
                    "ratio {$processed['pixel_to_point'][0]}x{$processed['pixel_to_point'][1]})\n" .
                    "Saved to: {$processed['path']}"
                );
                return;
            }

            $pi->sendMessage("Unknown command '/android {$sub}'. Available: /android status | connect <ip:port> | screenshot");
        }
    );

    // -------------------------------------------------------------------------
    // Helper: take observation
    // -------------------------------------------------------------------------
    $observeScreen = static function (?string $serial = null) use ($adb, &$lastScale): array {
        $adb->wakeUp($serial);
        $raw = $adb->screencapRaw($serial);
        if ($raw === null) {
            throw new \RuntimeException('Failed to capture screen from Android device via ADB.');
        }

        $processed = ScreenCapture::process($raw);
        $lastScale = $processed['pixel_to_point'];
        $focus = $adb->currentFocus($serial);

        return [
            'app' => $focus['package'] ?? 'unknown',
            'activity' => $focus['activity'] ?? 'unknown',
            'screen_width' => $processed['raw_width'],
            'screen_height' => $processed['raw_height'],
            'viewport_width' => $processed['width'],
            'viewport_height' => $processed['height'],
            'pixel_to_point' => $processed['pixel_to_point'],
            'image' => [
                'path' => $processed['path'],
                'mimeType' => $processed['mimeType'],
                'width' => $processed['width'],
                'height' => $processed['height'],
                'data' => $processed['data'],
            ],
        ];
    };

    // -------------------------------------------------------------------------
    // Tool 1: android_doctor
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_doctor',
        label: 'Android Doctor',
        description: 'Diagnose local ADB environment, connected Android devices, screen resolution and battery/focus state.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($adb): AgentToolResult {
            $serial = $params['serial'] ?? null;
            $available = $adb->isAvailable();

            $info = [
                'adb_available' => $available,
                'adb_path' => $available ? $adb->binary() : null,
                'adb_version' => $available ? $adb->version() : null,
                'devices' => $available ? $adb->devices() : [],
                'screen_size' => $available ? $adb->screenSize($serial) : null,
                'foreground' => $available ? $adb->currentFocus($serial) : null,
                'screen_on' => $available ? $adb->isScreenOn($serial) : null,
            ];

            return new AgentToolResult([new TextContent((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 2: android_ready
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_ready',
        label: 'Android Ready Check',
        description: 'Check if an Android device is connected, wake it up, and return initial viewport dimensions and screenshot.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
                'screenshot' => ['type' => 'boolean', 'description' => 'Whether to include screenshot. Default true.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($adb, $observeScreen): AgentToolResult {
            $serial = $params['serial'] ?? null;
            if (!$adb->isAvailable()) {
                throw new \RuntimeException('ADB binary not found on this machine. Install Android platform-tools or set ADB_PATH.');
            }

            $devices = $adb->devices();
            if ($devices === []) {
                throw new \RuntimeException('No Android devices connected. Enable USB debugging on your device, or connect via WiFi.');
            }

            $wantScreenshot = (bool) ($params['screenshot'] ?? true);
            $obs = $observeScreen($serial);

            $contents = [];
            $meta = [
                'ready' => true,
                'device' => $devices[0]['serial'],
                'model' => $devices[0]['model'] ?? 'Android',
                'app' => $obs['app'],
                'activity' => $obs['activity'],
                'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                'pixel_to_point' => $obs['pixel_to_point'],
            ];

            $contents[] = new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($wantScreenshot) {
                $contents[] = new ImageContent(
                    base64_encode($obs['image']['data']),
                    $obs['image']['mimeType']
                );
            }

            return new AgentToolResult($contents);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 3: android_observe
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_observe',
        label: 'Android Observe Screen',
        description: 'Capture the current screen screenshot and foreground app info from the Android device.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['screenshot', 'info', 'both'],
                    'description' => 'Observation mode. Default both.',
                ],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($observeScreen): AgentToolResult {
            $serial = $params['serial'] ?? null;
            $mode = (string) ($params['mode'] ?? 'both');
            $obs = $observeScreen($serial);

            $contents = [];
            $meta = [
                'app' => $obs['app'],
                'activity' => $obs['activity'],
                'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                'pixel_to_point' => $obs['pixel_to_point'],
                'image_path' => $obs['image']['path'],
            ];

            $contents[] = new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($mode === 'screenshot' || $mode === 'both') {
                $contents[] = new ImageContent(
                    base64_encode($obs['image']['data']),
                    $obs['image']['mimeType']
                );
            }

            return new AgentToolResult($contents);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 4: android_tap
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_tap',
        label: 'Android Tap',
        description: 'Tap on screen coordinates (x, y). Coordinates can match the scaled screenshot and will automatically convert to physical pixels with anti-ban jitter.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'x' => ['type' => 'number', 'description' => 'X coordinate to tap.'],
                'y' => ['type' => 'number', 'description' => 'Y coordinate to tap.'],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Post-action observation. Default none.',
                ],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
            'required' => ['x', 'y'],
        ],
        execute: static function (string $id, array $params) use ($adb, $observeScreen, &$lastScale): AgentToolResult {
            $x = (float) $params['x'];
            $y = (float) $params['y'];
            $serial = $params['serial'] ?? null;
            $observe = (string) ($params['observe'] ?? 'none');

            $tapped = InputManager::tap($adb, $x, $y, $lastScale, $serial, humanJitter: true);

            $contents = [];
            $res = [
                'action_executed' => true,
                'tapped' => $tapped,
            ];

            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen($serial);
                $res['observation'] = [
                    'app' => $obs['app'],
                    'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                    'pixel_to_point' => $obs['pixel_to_point'],
                ];
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
            } else {
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return new AgentToolResult($contents);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 5: android_swipe
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_swipe',
        label: 'Android Swipe Gesture',
        description: 'Swipe on Android screen by direction ("up", "down", "left", "right") or by explicit from/to coordinates.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'direction' => [
                    'type' => 'string',
                    'enum' => ['up', 'down', 'left', 'right'],
                    'description' => 'Finger movement direction. "up" usually scrolls down.',
                ],
                'from_x' => ['type' => 'number', 'description' => 'Optional explicit start X.'],
                'from_y' => ['type' => 'number', 'description' => 'Optional explicit start Y.'],
                'to_x' => ['type' => 'number', 'description' => 'Optional explicit end X.'],
                'to_y' => ['type' => 'number', 'description' => 'Optional explicit end Y.'],
                'duration_ms' => ['type' => 'integer', 'description' => 'Gesture duration in milliseconds. Default 300.'],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Post-action observation. Default none.',
                ],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($adb, $observeScreen, &$lastScale): AgentToolResult {
            $serial = $params['serial'] ?? null;
            $duration = (int) ($params['duration_ms'] ?? 300);
            $observe = (string) ($params['observe'] ?? 'none');

            $info = [];
            if (isset($params['from_x'], $params['from_y'], $params['to_x'], $params['to_y'])) {
                $info = InputManager::swipe(
                    $adb,
                    (float) $params['from_x'],
                    (float) $params['from_y'],
                    (float) $params['to_x'],
                    (float) $params['to_y'],
                    $duration,
                    $lastScale,
                    $serial
                );
            } elseif (!empty($params['direction'])) {
                $screen = $adb->screenSize($serial) ?? ['width' => 1080, 'height' => 2400];
                $info = InputManager::swipeDirection($adb, (string) $params['direction'], $screen, $duration, $serial);
            } else {
                throw new \RuntimeException('Must provide either direction ("up", "down", "left", "right") or (from_x, from_y, to_x, to_y).');
            }

            $contents = [];
            $res = [
                'action_executed' => true,
                'gesture' => $info,
            ];

            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen($serial);
                $res['observation'] = [
                    'app' => $obs['app'],
                    'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                    'pixel_to_point' => $obs['pixel_to_point'],
                ];
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
            } else {
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return new AgentToolResult($contents);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 6: android_type_text
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_type_text',
        label: 'Android Type Text',
        description: 'Input text on the focused Android input field, with full support for Chinese, Unicode, and multiline text.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'The full text to enter.'],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
            'required' => ['text'],
        ],
        execute: static function (string $id, array $params) use ($adb): AgentToolResult {
            $text = (string) ($params['text'] ?? '');
            $serial = $params['serial'] ?? null;

            InputManager::typeText($adb, $text, $serial);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'typed_length' => mb_strlen($text),
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 7: android_press_button
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_press_button',
        label: 'Android Press Button',
        description: 'Simulate physical hardware or navigation button presses (home, back, power, volumeup, volumedown, enter, app_switch).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'enum' => ['home', 'back', 'menu', 'power', 'volumeup', 'volumedown', 'enter', 'app_switch', 'recent'],
                    'description' => 'Button to press.',
                ],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
            'required' => ['name'],
        ],
        execute: static function (string $id, array $params) use ($adb): AgentToolResult {
            $button = (string) $params['name'];
            $serial = $params['serial'] ?? null;

            InputManager::pressButton($adb, $button, $serial);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'button' => $button,
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 8: android_launch_app
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_launch_app',
        label: 'Android Launch App',
        description: 'Launch an Android application by package name (e.g. com.tencent.mm, com.android.settings).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'package' => ['type' => 'string', 'description' => 'Target application package name.'],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
            'required' => ['package'],
        ],
        execute: static function (string $id, array $params) use ($adb): AgentToolResult {
            $pkg = trim((string) $params['package']);
            $serial = $params['serial'] ?? null;

            if ($pkg === '' || !preg_match('/^[a-zA-Z0-9._]+$/', $pkg)) {
                throw new \RuntimeException('Invalid package name format.');
            }

            // Launch via monkey launcher intent
            $res = $adb->executeShell("monkey -p {$pkg} -c android.intent.category.LAUNCHER 1", $serial);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'package' => $pkg,
                'success' => $res['exit'] === 0,
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 9: android_connect
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_connect',
        label: 'Android Connect Wireless',
        description: 'Connect to an Android device over WiFi/TCP (requires device to have Wireless Debugging enabled).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'address' => ['type' => 'string', 'description' => 'Device address in IP:port format (e.g. 192.168.1.100:5555).'],
            ],
            'required' => ['address'],
        ],
        execute: static function (string $id, array $params) use ($adb): AgentToolResult {
            $addr = trim((string) $params['address']);
            $res = $adb->connect($addr);

            return new AgentToolResult([new TextContent((string) json_encode([
                'success' => $res['ok'],
                'message' => $res['message'],
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 10: android_tree (Compact Accessibility Hierarchy)
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_tree',
        label: 'Android Compact Hierarchy Tree',
        description: 'Dump native Android View hierarchy tree and return compact, token-saving JSON nodes with labels, IDs and bounds.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'max_nodes' => ['type' => 'integer', 'description' => 'Max number of interactive nodes to return. Default 60.'],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($adb, &$lastScale): AgentToolResult {
            $serial = $params['serial'] ?? null;
            $maxNodes = max(1, min(200, (int) ($params['max_nodes'] ?? 60)));

            $rawXml = $adb->dumpWindowHierarchy($serial);
            if ($rawXml === null) {
                return new AgentToolResult([new TextContent((string) json_encode([
                    'nodes' => [],
                    'note' => 'Empty or unavailable UI hierarchy (custom-rendered surface or game). Use vision screenshots.',
                ]))]);
            }

            $nodes = TreeParser::parse($rawXml, $lastScale, $maxNodes);
            $focus = $adb->currentFocus($serial);

            return new AgentToolResult([new TextContent((string) json_encode([
                'app' => $focus['package'] ?? 'unknown',
                'activity' => $focus['activity'] ?? 'unknown',
                'total_nodes' => count($nodes),
                'nodes' => $nodes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 11: android_batch (Humanized Anti-Ban Sequence)
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'android_batch',
        label: 'Android Anti-Ban Batch Steps',
        description: 'Execute up to 15 sequential actions with anti-ban random coordinate jitter and humanized micro-delays (150-350ms).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'description' => 'List of action objects: {op: "tap"|"swipe"|"type_text"|"press_button"|"wait", args: {...}}',
                    'items' => ['type' => 'object'],
                ],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Observe screen after the batch completes. Default screenshot.',
                ],
                'serial' => ['type' => 'string', 'description' => 'Optional specific device serial number.'],
            ],
            'required' => ['steps'],
        ],
        execute: static function (string $id, array $params) use ($adb, $observeScreen, &$lastScale): AgentToolResult {
            $steps = (array) ($params['steps'] ?? []);
            if (count($steps) > 15) {
                throw new \RuntimeException('Batch steps exceed maximum budget of 15 operations.');
            }

            $serial = $params['serial'] ?? null;
            $observe = (string) ($params['observe'] ?? 'screenshot');

            $batchRes = InputManager::batch($adb, $steps, $lastScale, $serial);

            $contents = [];
            $res = [
                'action_executed' => true,
                'completed' => $batchRes['completed'],
                'results' => $batchRes['results'],
            ];

            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen($serial);
                $res['observation'] = [
                    'app' => $obs['app'],
                    'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                    'pixel_to_point' => $obs['pixel_to_point'],
                ];
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $contents[] = new ImageContent(base64_encode($obs['image']['data']), $obs['image']['mimeType']);
            } else {
                $contents[] = new TextContent((string) json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return new AgentToolResult($contents);
        }
    ));

    // -------------------------------------------------------------------------
    // Web HTTP Route: /api/android (Screen Monitoring & Inspection)
    // -------------------------------------------------------------------------
    $pi->registerHttpRoute('/api/android', static function (string $path, array $req) use ($adb, $observeScreen, &$lastScale): ?array {
        if ($path === '/api/android/screen.jpg' && $req['method'] === 'GET') {
            $raw = $adb->screencapRaw();
            if ($raw === null) {
                return ['status' => 503, 'body' => 'Device screen unavailable.'];
            }
            $processed = ScreenCapture::process($raw);
            $lastScale = $processed['pixel_to_point'];

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'image/jpeg',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ],
                'body' => $processed['data'],
            ];
        }

        if ($path === '/api/android/screen' && $req['method'] === 'GET') {
            try {
                $obs = $observeScreen();
                return [
                    'status' => 200,
                    'body' => [
                        'ok' => true,
                        'app' => $obs['app'],
                        'activity' => $obs['activity'],
                        'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                        'pixel_to_point' => $obs['pixel_to_point'],
                        'image_url' => '/api/android/screen.jpg?t=' . microtime(true),
                    ],
                ];
            } catch (\Throwable $e) {
                return ['status' => 503, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
            }
        }

        if ($path === '/api/android/stream' && $req['method'] === 'GET') {
            $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Android Live Screen - pig</title>
    <style>
        body { margin: 0; background: #0f1117; color: #fff; font-family: system-ui, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; }
        .frame { border: 12px solid #2a2d3d; border-radius: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); overflow: hidden; background: #000; position: relative; }
        img { display: block; max-height: 80vh; max-width: 90vw; object-fit: contain; }
        .bar { margin-top: 15px; display: flex; gap: 10px; }
        button { background: #3b82f6; border: none; color: #fff; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 500; }
        button:hover { background: #2563eb; }
        .info { margin-top: 8px; font-size: 13px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="frame">
        <img id="screen" src="/api/android/screen.jpg" alt="Android Screen" />
    </div>
    <div class="bar">
        <button onclick="refresh()">刷新画面</button>
        <button id="toggleBtn" onclick="toggleStream()">自动刷新: 开</button>
    </div>
    <div class="info" id="status">正在通过原生 ADB 读取画面...</div>
    <script>
        let live = true;
        let timer = null;
        function refresh() {
            const img = document.getElementById('screen');
            img.src = '/api/android/screen.jpg?t=' + Date.now();
        }
        function toggleStream() {
            live = !live;
            document.getElementById('toggleBtn').innerText = '自动刷新: ' + (live ? '开' : '关');
            if (live) start(); else clearInterval(timer);
        }
        function start() {
            clearInterval(timer);
            timer = setInterval(refresh, 800);
        }
        start();
    </script>
</body>
</html>
HTML;
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
                'body' => $html,
            ];
        }

        return null;
    });
};
