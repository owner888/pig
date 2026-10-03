<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\CustomTools\CustomTool;

/**
 * Pig Computer Use Extension
 *
 * Connects pig to the anti-bot browser service (docker/browser) or local desktop screen,
 * supporting screenshot inspection, mouse clicks, keyboard typing, keypress, and scrolling.
 */
return static function (ExtensionApi $pi): void {
    $browserEndpoint = rtrim(getenv('PIG_BROWSER_ENDPOINT') ?: 'http://127.0.0.1:9523', '/');

    // Register /computer slash command
    $pi->registerCommand(
        name: 'computer',
        description: 'Check browser automation service status or run actions: /computer status | screenshot',
        handler: static function (string $args, $ctx) use ($browserEndpoint, $pi): void {
            $cmd = trim($args);
            if ($cmd === 'status' || $cmd === '') {
                $status = @file_get_contents("{$browserEndpoint}/health");
                if ($status === false) {
                    $pi->sendMessage("❌ Browser service unavailable at {$browserEndpoint}. Start it with: docker compose -f docker/browser/docker-compose.yml up -d");
                    return;
                }
                $pi->sendMessage("✅ Browser service is healthy: {$status}");
                return;
            }

            if ($cmd === 'screenshot') {
                $ch = curl_init("{$browserEndpoint}/screenshot");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['fullPage' => false, 'format' => 'png']),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $res = curl_exec($ch);
                curl_close($ch);

                if (!$res) {
                    $pi->sendMessage("❌ Failed to take screenshot from {$browserEndpoint}");
                    return;
                }

                $data = json_decode((string) $res, true);
                if (empty($data['image'])) {
                    $pi->sendMessage("❌ Screenshot response missing image payload: {$res}");
                    return;
                }

                $raw = base64_decode($data['image']);
                $path = sys_get_temp_dir() . '/pig-computer-' . substr(md5($data['image']), 0, 12) . '.png';
                file_put_contents($path, $raw);

                $pi->sendMessage("📸 Screenshot taken [{$data['title']}]: {$path}");
                return;
            }

            $pi->sendMessage("Unknown /computer subcommand. Use: /computer status | screenshot");
        }
    );

    // Register computer tool for LLMs
    $pi->registerTool(new CustomTool(
        name: 'computer',
        label: 'Computer Use & Browser Automation',
        description: 'Control the anti-detection browser or screen: navigate to URLs, take screenshots, click coordinates, type text, press keys, and scroll.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['navigate', 'screenshot', 'click', 'mouse_move', 'type', 'key', 'scroll', 'text'],
                    'description' => 'The action to perform.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'Target URL for "navigate" action (e.g. "https://jd.com").',
                ],
                'coordinate' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Coordinate [x, y] in pixels for "click" or "mouse_move" (viewport 1920x1080).',
                ],
                'selector' => [
                    'type' => 'string',
                    'description' => 'Optional CSS selector to click or focus before typing.',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'The text to type for "type" action.',
                ],
                'key' => [
                    'type' => 'string',
                    'description' => 'Key name to press for "key" action (e.g. "Enter", "Tab", "Escape", "Backspace").',
                ],
                'delta_y' => [
                    'type' => 'integer',
                    'description' => 'Vertical scroll amount in pixels (e.g. 400 for down, -400 for up).',
                ],
            ],
            'required' => ['action'],
        ],
        execute: static function ($id, $params, $onUpdate, $ctx) use ($browserEndpoint): AgentToolResult {
            $action = (string) ($params['action'] ?? 'screenshot');

            $callApi = static function (string $path, array $data = [], string $method = 'POST') use ($browserEndpoint): array {
                $ch = curl_init("{$browserEndpoint}{$path}");
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 45,
                ];

                if ($method === 'POST') {
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
                }

                curl_setopt_array($ch, $opts);
                $res = curl_exec($ch);
                $err = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($err !== '' || $res === false) {
                    throw new \RuntimeException("Browser service at {$browserEndpoint} error: {$err}");
                }

                $json = json_decode((string) $res, true);
                if ($code >= 400) {
                    $msg = $json['error'] ?? (string) $res;
                    throw new \RuntimeException("Browser service returned {$code}: {$msg}");
                }

                return is_array($json) ? $json : [];
            };

            switch ($action) {
                case 'navigate':
                    $url = (string) ($params['url'] ?? '');
                    if ($url === '') {
                        return new AgentToolResult([new TextContent('Error: "url" parameter is required for navigate action.')]);
                    }
                    $res = $callApi('/navigate', ['url' => $url]);
                    // Auto return current view text and confirmation
                    return new AgentToolResult([
                        new TextContent("Navigated to {$res['url']} (Title: \"{$res['title']}\")."),
                    ]);

                case 'screenshot':
                    $res = $callApi('/screenshot', ['format' => 'png']);
                    $base64 = $res['image'] ?? '';
                    $title = $res['title'] ?? 'Browser Screenshot';
                    $url = $res['url'] ?? '';

                    $contents = [
                        new TextContent("Current Page: \"{$title}\" ({$url})"),
                    ];
                    if ($base64 !== '') {
                        $contents[] = new ImageContent('image/png', $base64);
                    }

                    return new AgentToolResult($contents);

                case 'click':
                    $payload = [];
                    if (!empty($params['coordinate']) && is_array($params['coordinate'])) {
                        $payload['x'] = (int) $params['coordinate'][0];
                        $payload['y'] = (int) $params['coordinate'][1];
                    }
                    if (!empty($params['selector'])) {
                        $payload['selector'] = (string) $params['selector'];
                    }
                    $res = $callApi('/click', $payload);
                    return new AgentToolResult([
                        new TextContent("Clicked at x={$res['clicked']['x']}, y={$res['clicked']['y']}."),
                    ]);

                case 'mouse_move':
                    $x = (int) ($params['coordinate'][0] ?? 0);
                    $y = (int) ($params['coordinate'][1] ?? 0);
                    $res = $callApi('/mouse_move', ['x' => $x, 'y' => $y]);
                    return new AgentToolResult([
                        new TextContent("Mouse moved to x={$res['position']['x']}, y={$res['position']['y']}."),
                    ]);

                case 'type':
                    $text = (string) ($params['text'] ?? '');
                    $payload = ['text' => $text];
                    if (!empty($params['selector'])) {
                        $payload['selector'] = (string) $params['selector'];
                    }
                    $res = $callApi('/type', $payload);
                    return new AgentToolResult([
                        new TextContent("Typed {$res['typedLength']} characters into browser."),
                    ]);

                case 'key':
                    $key = (string) ($params['key'] ?? 'Enter');
                    $res = $callApi('/press', ['key' => $key]);
                    return new AgentToolResult([
                        new TextContent("Pressed key '{$res['pressed']}'."),
                    ]);

                case 'scroll':
                    $deltaY = (int) ($params['delta_y'] ?? 400);
                    $res = $callApi('/scroll', ['deltaY' => $deltaY]);
                    return new AgentToolResult([
                        new TextContent("Scrolled viewport by deltaY={$res['scrolled']['deltaY']}."),
                    ]);

                case 'text':
                    $res = $callApi('/text', [], 'GET');
                    return new AgentToolResult([
                        new TextContent("Page: {$res['title']} ({$res['url']})\n\n{$res['text']}"),
                    ]);

                default:
                    return new AgentToolResult([new TextContent("Unknown computer action: '{$action}'.")]);
            }
        }
    ));
};
