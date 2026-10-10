<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;

/**
 * Pig Native Computer Use Extension
 *
 * 100% pure PHP native desktop automation for macOS.
 * Zero Docker containers, zero Python/Node.js bridges, zero external dependencies.
 * Fully aligned with iPhone Use & Android Use architectures.
 */
return static function (ExtensionApi $pi): void {
    require_once __DIR__ . '/src/DesktopClient.php';
    require_once __DIR__ . '/src/ScreenScaler.php';
    require_once __DIR__ . '/src/InputManager.php';

    $desktop = new DesktopClient();
    /** @var array{float, float} Last observed pixel-to-point scaling ratio */
    $lastScale = [1.0, 1.0];

    // -------------------------------------------------------------------------
    // Helper: capture and process screen observation
    // -------------------------------------------------------------------------
    $observeScreen = static function () use ($desktop, &$lastScale): array {
        $screenSize = $desktop->screenSize();

        $artifactsDir = Config::home() . '/artifacts';
        if (!is_dir($artifactsDir)) {
            mkdir($artifactsDir, 0o700, true);
        }

        $tmpPng = "{$artifactsDir}/raw-screen-" . uniqid() . '.png';
        if (!$desktop->captureRawPng($tmpPng)) {
            throw new \RuntimeException('Failed to capture screen using native macOS screencapture.');
        }

        $processed = ScreenScaler::process($tmpPng, $screenSize);
        $lastScale = $processed['pixel_to_point'];
        $app = $desktop->frontmostApp();

        return [
            'app' => $app['name'] ?? 'Finder',
            'bundle_id' => $app['bundleId'] ?? '',
            'screen_width' => $screenSize['width'],
            'screen_height' => $screenSize['height'],
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
    // Slash Command: /computer
    // -------------------------------------------------------------------------
    $pi->registerCommand(
        name: 'computer',
        description: 'Native Mac desktop controller: /computer status | screenshot | app <name>',
        handler: static function (string $args, $ctx) use ($desktop, $observeScreen, $pi): void {
            $parts = preg_split('/\s+/', trim($args), 2, PREG_SPLIT_NO_EMPTY);
            $sub = strtolower($parts[0] ?? 'status');
            $param = trim($parts[1] ?? '');

            if ($sub === 'status' || $sub === '') {
                if (!$desktop->isAvailable()) {
                    $pi->sendMessage("❌ Native desktop automation requires macOS with screencapture and osascript.");
                    return;
                }

                $size = $desktop->screenSize();
                $app = $desktop->frontmostApp();

                $lines = [
                    '🖥️ Native Desktop Controller (macOS):',
                    "  • Logical Screen: {$size['width']}x{$size['height']} Points",
                    '  • Frontmost App: ' . ($app !== null ? "{$app['name']} ({$app['bundleId']})" : 'Finder'),
                    '  • Automation Engine: Pure PHP + JXA CoreGraphics',
                ];

                $pi->sendMessage(implode("\n", $lines));
                return;
            }

            if ($sub === 'screenshot') {
                $obs = $observeScreen();
                $pi->sendMessage(
                    "📸 Desktop screenshot captured: {$obs['viewport_width']}x{$obs['viewport_height']} " .
                    "(Retina logical {$obs['screen_width']}x{$obs['screen_height']}, " .
                    "ratio {$obs['pixel_to_point'][0]}x{$obs['pixel_to_point'][1]})\n" .
                    "Saved to: {$obs['image']['path']}"
                );
                return;
            }

            if ($sub === 'app') {
                if ($param === '') {
                    $pi->sendMessage("Usage: /computer app <Application Name> (e.g. /computer app Google Chrome)");
                    return;
                }
                $ok = $desktop->launchAppOrUrl($param);
                $pi->sendMessage($ok ? "✅ Opened/Focused: {$param}" : "❌ Failed to open: {$param}");
                return;
            }

            $pi->sendMessage("Unknown command '/computer {$sub}'. Available: /computer status | screenshot | app <name>");
        }
    );

    // -------------------------------------------------------------------------
    // Tool 1: computer_doctor
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_doctor',
        label: 'Computer Doctor',
        description: 'Diagnose local desktop automation environment, screen resolution, Retina scale and frontmost application.',
        parameters: [
            'type' => 'object',
            'properties' => [],
        ],
        execute: static function (string $id, array $params) use ($desktop): AgentToolResult {
            $available = $desktop->isAvailable();
            $info = [
                'platform' => PHP_OS_FAMILY,
                'available' => $available,
                'screen_size' => $available ? $desktop->screenSize() : null,
                'frontmost_app' => $available ? $desktop->frontmostApp() : null,
                'screencapture_binary' => file_exists('/usr/sbin/screencapture'),
                'osascript_binary' => file_exists('/usr/bin/osascript'),
            ];

            return new AgentToolResult([new TextContent((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 2: computer_ready
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_ready',
        label: 'Computer Ready Check',
        description: 'Verify desktop control channel, return logical screen dimensions, active frontmost app and initial screen capture.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'screenshot' => ['type' => 'boolean', 'description' => 'Whether to include screenshot. Default true.'],
            ],
        ],
        execute: static function (string $id, array $params) use ($desktop, $observeScreen): AgentToolResult {
            if (!$desktop->isAvailable()) {
                throw new \RuntimeException('Desktop automation is only available on macOS with screencapture and osascript.');
            }

            $wantScreenshot = (bool) ($params['screenshot'] ?? true);
            $obs = $observeScreen();

            $meta = [
                'ready' => true,
                'app' => $obs['app'],
                'bundle_id' => $obs['bundle_id'],
                'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                'pixel_to_point' => $obs['pixel_to_point'],
            ];

            $contents = [new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))];

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
    // Tool 3: computer_observe
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_observe',
        label: 'Computer Observe Screen',
        description: 'Capture the current desktop screenshot and frontmost application information.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['screenshot', 'info', 'both'],
                    'description' => 'Observation mode. Default both.',
                ],
            ],
        ],
        execute: static function (string $id, array $params) use ($observeScreen): AgentToolResult {
            $mode = (string) ($params['mode'] ?? 'both');
            $obs = $observeScreen();

            $meta = [
                'app' => $obs['app'],
                'bundle_id' => $obs['bundle_id'],
                'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                'pixel_to_point' => $obs['pixel_to_point'],
                'image_path' => $obs['image']['path'],
            ];

            $contents = [new TextContent((string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))];

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
    // Tool 4: computer_click
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_click',
        label: 'Computer Mouse Click',
        description: 'Click mouse at coordinates (x, y). Coordinates can match the scaled screenshot and automatically convert to Retina points.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'x' => ['type' => 'number', 'description' => 'X coordinate to click.'],
                'y' => ['type' => 'number', 'description' => 'Y coordinate to click.'],
                'button' => [
                    'type' => 'string',
                    'enum' => ['left', 'right', 'middle'],
                    'description' => 'Mouse button. Default left.',
                ],
                'click_count' => [
                    'type' => 'integer',
                    'description' => '1 for single click, 2 for double click. Default 1.',
                ],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Post-action observation. Default none.',
                ],
            ],
            'required' => ['x', 'y'],
        ],
        execute: static function (string $id, array $params) use ($desktop, $observeScreen, &$lastScale): AgentToolResult {
            $x = (float) $params['x'];
            $y = (float) $params['y'];
            $button = (string) ($params['button'] ?? 'left');
            $clickCount = (int) ($params['click_count'] ?? 1);
            $observe = (string) ($params['observe'] ?? 'none');

            $coords = InputManager::scaleCoordinates($x, $y, $lastScale, true);
            $desktop->mouseClick((float) $coords['real_x'], (float) $coords['real_y'], $button, $clickCount);

            $res = [
                'action_executed' => true,
                'clicked' => $coords,
                'button' => $button,
                'click_count' => $clickCount,
            ];

            $contents = [];
            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen();
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
    // Tool 5: computer_drag
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_drag',
        label: 'Computer Mouse Drag',
        description: 'Drag mouse from (from_x, from_y) to (to_x, to_y).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'from_x' => ['type' => 'number', 'description' => 'Start X coordinate.'],
                'from_y' => ['type' => 'number', 'description' => 'Start Y coordinate.'],
                'to_x' => ['type' => 'number', 'description' => 'End X coordinate.'],
                'to_y' => ['type' => 'number', 'description' => 'End Y coordinate.'],
                'duration_ms' => ['type' => 'integer', 'description' => 'Drag duration in milliseconds. Default 300.'],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Post-action observation. Default none.',
                ],
            ],
            'required' => ['from_x', 'from_y', 'to_x', 'to_y'],
        ],
        execute: static function (string $id, array $params) use ($desktop, $observeScreen, &$lastScale): AgentToolResult {
            $p1 = InputManager::scaleCoordinates((float) $params['from_x'], (float) $params['from_y'], $lastScale, false);
            $p2 = InputManager::scaleCoordinates((float) $params['to_x'], (float) $params['to_y'], $lastScale, false);
            $duration = (int) ($params['duration_ms'] ?? 300);
            $observe = (string) ($params['observe'] ?? 'none');

            $desktop->mouseDrag((float) $p1['real_x'], (float) $p1['real_y'], (float) $p2['real_x'], (float) $p2['real_y'], $duration);

            $res = [
                'action_executed' => true,
                'drag' => ['from' => $p1, 'to' => $p2, 'duration_ms' => $duration],
            ];

            $contents = [];
            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen();
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
    // Tool 6: computer_scroll
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_scroll',
        label: 'Computer Mouse Scroll',
        description: 'Scroll mouse wheel vertically or horizontally.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'delta_y' => ['type' => 'integer', 'description' => 'Vertical scroll amount (positive down, negative up).'],
                'delta_x' => ['type' => 'integer', 'description' => 'Horizontal scroll amount (positive right, negative left). Default 0.'],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Post-action observation. Default none.',
                ],
            ],
            'required' => ['delta_y'],
        ],
        execute: static function (string $id, array $params) use ($desktop, $observeScreen): AgentToolResult {
            $dy = (int) $params['delta_y'];
            $dx = (int) ($params['delta_x'] ?? 0);
            $observe = (string) ($params['observe'] ?? 'none');

            $desktop->mouseScroll($dy, $dx);

            $res = ['action_executed' => true, 'scroll' => ['delta_y' => $dy, 'delta_x' => $dx]];
            $contents = [];

            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen();
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
    // Tool 7: computer_type_text
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_type_text',
        label: 'Computer Type Text',
        description: 'Input text into the focused field with native clipboard support for Chinese, multiline, and special symbols.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'The text to type or paste.'],
            ],
            'required' => ['text'],
        ],
        execute: static function (string $id, array $params): AgentToolResult {
            $text = (string) ($params['text'] ?? '');
            InputManager::typeText($text);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'typed_length' => mb_strlen($text),
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 8: computer_press_key
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_press_key',
        label: 'Computer Press Key',
        description: 'Press a keyboard key or shortcut combination (e.g. "Enter", "Tab", "Escape", "Space", "cmd+c", "cmd+v", "cmd+space").',
        parameters: [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Key name or shortcut combination.'],
            ],
            'required' => ['key'],
        ],
        execute: static function (string $id, array $params): AgentToolResult {
            $key = (string) $params['key'];
            InputManager::pressKey($key);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'key' => $key,
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 9: computer_launch_app
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_launch_app',
        label: 'Computer Launch App Or URL',
        description: 'Launch or focus a desktop application (e.g. "Google Chrome", "Slack", "Finder") or open a URL.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'description' => 'Application name (e.g. "Google Chrome") or URL (e.g. "https://google.com").'],
            ],
            'required' => ['target'],
        ],
        execute: static function (string $id, array $params) use ($desktop): AgentToolResult {
            $target = trim((string) $params['target']);
            $ok = $desktop->launchAppOrUrl($target);

            return new AgentToolResult([new TextContent((string) json_encode([
                'action_executed' => true,
                'target' => $target,
                'success' => $ok,
            ]))]);
        }
    ));

    // -------------------------------------------------------------------------
    // Tool 10: computer_batch
    // -------------------------------------------------------------------------
    $pi->registerTool(new CustomTool(
        name: 'computer_batch',
        label: 'Computer Batch Steps',
        description: 'Execute up to 15 sequential desktop operations with anti-ban random coordinate jitter and humanized micro-delays (120-300ms).',
        parameters: [
            'type' => 'object',
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'description' => 'List of action objects: {op: "click"|"drag"|"scroll"|"type_text"|"press_key"|"wait", args: {...}}',
                    'items' => ['type' => 'object'],
                ],
                'observe' => [
                    'type' => 'string',
                    'enum' => ['none', 'screenshot', 'both'],
                    'description' => 'Observe screen after the batch completes. Default screenshot.',
                ],
            ],
            'required' => ['steps'],
        ],
        execute: static function (string $id, array $params) use ($desktop, $observeScreen, &$lastScale): AgentToolResult {
            $steps = (array) ($params['steps'] ?? []);
            if (count($steps) > 15) {
                throw new \RuntimeException('Batch steps exceed maximum budget of 15 operations.');
            }

            $observe = (string) ($params['observe'] ?? 'screenshot');
            $batchRes = InputManager::batch($desktop, $steps, $lastScale);

            $contents = [];
            $res = [
                'action_executed' => true,
                'completed' => $batchRes['completed'],
                'results' => $batchRes['results'],
            ];

            if ($observe === 'screenshot' || $observe === 'both') {
                $obs = $observeScreen();
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
    // Web HTTP Route: /api/computer (Live Desktop Monitoring)
    // -------------------------------------------------------------------------
    $pi->registerHttpRoute('/api/computer', static function (string $path, array $req) use ($observeScreen): ?array {
        if ($path === '/api/computer/screen.jpg' && $req['method'] === 'GET') {
            try {
                $obs = $observeScreen();
                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'image/jpeg',
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    ],
                    'body' => $obs['image']['data'],
                ];
            } catch (\Throwable $e) {
                return ['status' => 503, 'body' => $e->getMessage()];
            }
        }

        if ($path === '/api/computer/screen' && $req['method'] === 'GET') {
            try {
                $obs = $observeScreen();
                return [
                    'status' => 200,
                    'body' => [
                        'ok' => true,
                        'app' => $obs['app'],
                        'bundle_id' => $obs['bundle_id'],
                        'viewport' => ['width' => $obs['viewport_width'], 'height' => $obs['viewport_height']],
                        'screen_size' => ['width' => $obs['screen_width'], 'height' => $obs['screen_height']],
                        'pixel_to_point' => $obs['pixel_to_point'],
                        'image_url' => '/api/computer/screen.jpg?t=' . microtime(true),
                    ],
                ];
            } catch (\Throwable $e) {
                return ['status' => 503, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
            }
        }

        if ($path === '/api/computer/stream' && $req['method'] === 'GET') {
            $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mac Desktop Live Stream - pig</title>
    <style>
        body { margin: 0; background: #0b0f19; color: #fff; font-family: system-ui, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; }
        .monitor { border: 10px solid #1e293b; border-radius: 16px; box-shadow: 0 25px 60px rgba(0,0,0,0.7); overflow: hidden; background: #000; position: relative; max-width: 92vw; }
        img { display: block; max-height: 82vh; max-width: 100%; object-fit: contain; }
        .bar { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
        button { background: #3b82f6; border: none; color: #fff; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 13px; }
        button:hover { background: #2563eb; }
        .info { font-size: 13px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="monitor">
        <img id="screen" src="/api/computer/screen.jpg" alt="Desktop Screen" />
    </div>
    <div class="bar">
        <button onclick="refresh()">刷新画面</button>
        <button id="toggleBtn" onclick="toggleStream()">自动刷新: 开</button>
        <span class="info" id="status">原生 macOS 桌面静音推流</span>
    </div>
    <script>
        let live = true;
        let timer = null;
        function refresh() {
            const img = document.getElementById('screen');
            img.src = '/api/computer/screen.jpg?t=' + Date.now();
        }
        function toggleStream() {
            live = !live;
            document.getElementById('toggleBtn').innerText = '自动刷新: ' + (live ? '开' : '关');
            if (live) start(); else clearInterval(timer);
        }
        function start() {
            clearInterval(timer);
            timer = setInterval(refresh, 1000);
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
