<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\CustomTools\CustomTool;

/**
 * Pig Computer Use Extension
 *
 * Connects pig to the anti-bot browser service (docker/browser) or local desktop screen,
 * supporting screenshot inspection, mouse clicks, keyboard typing, keypress, and scrolling.
 * Includes automated Cookie inspection and domain authentication gating for e-commerce platforms.
 */
return static function (ExtensionApi $pi): void {
    $browserEndpoint = rtrim(getenv('PIG_BROWSER_ENDPOINT') ?: 'http://127.0.0.1:9523', '/');

    // Sites requiring authentication/cookies to avoid infinite redirection or failed carts
    $authRequiredDomains = [
        'jd.com' => ['name' => '京东 (JD.com)', 'auth_cookies' => ['pt_key', 'pt_pin', 'thor', 'pin', 'pwdt_id']],
        'taobao.com' => ['name' => '淘宝 (Taobao)', 'auth_cookies' => ['_m_h5_tk', 'cookie2', '_tb_token_', 'sgcookie', 'unb']],
        'tmall.com' => ['name' => '天猫 (Tmall)', 'auth_cookies' => ['_m_h5_tk', 'cookie2', '_tb_token_', 'sgcookie', 'unb']],
        'douyin.com' => ['name' => '抖音电商 (Douyin)', 'auth_cookies' => ['sessionid', 'passport_csrf_token', 'sid_guard']],
        'amazon.com' => ['name' => '亚马逊 (Amazon)', 'auth_cookies' => ['session-id', 'ubid-main', 'at-main', 'x-main']],
    ];

    /**
     * Inspect ~/.pig/agent/cookies.json for a given target domain
     *
     * @return array{ok: bool, error: ?string}
     */
    $checkCookieStatus = static function (string $url) use ($authRequiredDomains): array {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            return ['ok' => true, 'error' => null];
        }

        $matchedRule = null;
        $matchedDomain = null;
        foreach ($authRequiredDomains as $domain => $rule) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $matchedRule = $rule;
                $matchedDomain = $domain;
                break;
            }
        }

        if ($matchedRule === null) {
            return ['ok' => true, 'error' => null];
        }

        $cookieFile = Config::home() . '/cookies.json';
        if (!file_exists($cookieFile) || !is_readable($cookieFile)) {
            $msg = "【登录凭据缺失 - 操作已终止】\n"
                 . "检测到您正在访问需要登录态的站点：{$matchedRule['name']} ({$matchedDomain})。\n"
                 . "当前尚未在 ~/.pig/agent/cookies.json 检测到任何 Cookie 文件。\n\n"
                 . "如何解决：\n"
                 . "1. 在您电脑的 Chrome 浏览器中打开并登录 {$matchedRule['name']}。\n"
                 . "2. 安装浏览器扩展（推荐 EditThisCookie 或 Cookie-Editor），点击「导出 (Export)」复制为 JSON。\n"
                 . "3. 将导出的 JSON 保存到本地：\n"
                 . "   mkdir -p ~/.pig/agent\n"
                 . "   vim ~/.pig/agent/cookies.json (粘贴保存)\n"
                 . "4. 保存完成后，请重新吩咐我继续执行操作！";
            return ['ok' => false, 'error' => $msg];
        }

        $raw = file_get_contents($cookieFile);
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || empty($json)) {
            $msg = "【Cookie 格式错误 - 操作已终止】\n"
                 . "文件 ~/.pig/agent/cookies.json 内容为空或不是合法的 JSON 数组。\n"
                 . "请使用 Cookie-Editor 重新导出 {$matchedRule['name']} 的 Cookie 覆盖保存。";
            return ['ok' => false, 'error' => $msg];
        }

        // Check if there are cookies for this domain
        $domainCookies = [];
        $hasKeyCookie = false;
        $now = time();
        $expiredCount = 0;

        foreach ($json as $c) {
            if (!is_array($c) || empty($c['name'])) {
                continue;
            }
            $cDomain = strtolower((string) ($c['domain'] ?? ''));
            if ($cDomain === $matchedDomain || str_ends_with($cDomain, '.' . $matchedDomain) || str_ends_with($matchedDomain, $cDomain)) {
                $domainCookies[] = $c;
                if (in_array($c['name'], $matchedRule['auth_cookies'], true)) {
                    $hasKeyCookie = true;
                    // Check expiration if present
                    if (!empty($c['expirationDate']) && is_numeric($c['expirationDate'])) {
                        if ($c['expirationDate'] < $now) {
                            $expiredCount++;
                        }
                    }
                }
            }
        }

        if (empty($domainCookies)) {
            $msg = "【未找到该站点的登录 Cookie - 操作已终止】\n"
                 . "文件 ~/.pig/agent/cookies.json 中包含 Cookie，但未找到与 {$matchedDomain} 相关的登录信息。\n"
                 . "请打开您的浏览器，进入并登录 {$matchedRule['name']}，使用 Cookie 插件重新导出并追加/覆盖到 ~/.pig/agent/cookies.json。";
            return ['ok' => false, 'error' => $msg];
        }

        if (!$hasKeyCookie || $expiredCount > 0) {
            $msg = "【登录凭据可能已过期或不完整 - 操作已终止】\n"
                 . "检测到 ~/.pig/agent/cookies.json 中的 {$matchedRule['name']} 核心认证 Cookie 已过期或缺失核心登录字段（如 " . implode(', ', $matchedRule['auth_cookies']) . "）。\n"
                 . "为避免在未登录状态下出现死循环跳转或购物车失败，请在浏览器中重新刷新登录 {$matchedRule['name']}，重新导出覆盖 ~/.pig/agent/cookies.json。";
            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true, 'error' => null];
    };

    // Register /computer slash command
    $pi->registerCommand(
        name: 'computer',
        description: 'Check browser automation service status or run actions: /computer status | screenshot | cookies',
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

            if ($cmd === 'cookies') {
                $cookieFile = Config::home() . '/cookies.json';
                if (!file_exists($cookieFile)) {
                    $pi->sendMessage("⚠️ No cookies.json found at {$cookieFile}. Create it to store browser login state.");
                    return;
                }
                $raw = (string) file_get_contents($cookieFile);
                $json = json_decode($raw, true);
                if (!is_array($json)) {
                    $pi->sendMessage("❌ Invalid JSON in {$cookieFile}");
                    return;
                }

                $domains = [];
                foreach ($json as $c) {
                    if (!empty($c['domain'])) {
                        $domains[ltrim((string) $c['domain'], '.')] = true;
                    }
                }
                $dList = implode(', ', array_keys($domains));
                $pi->sendMessage("🍪 Loaded " . count($json) . " cookies from {$cookieFile}\nDomains: " . ($dList ?: 'none'));
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
                unset($ch);

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

            $pi->sendMessage("Unknown /computer subcommand. Use: /computer status | screenshot | cookies");
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
        execute: static function ($id, $params, $onUpdate, $ctx) use ($browserEndpoint, $checkCookieStatus): AgentToolResult {
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
                unset($ch);

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

                    // 1. Check cookies status for login-required domains (e.g. JD.com, Taobao.com)
                    $authCheck = $checkCookieStatus($url);
                    if (!$authCheck['ok']) {
                        // Immediately stop and instruct user without making useless blind requests
                        return new AgentToolResult([
                            new TextContent($authCheck['error']),
                        ]);
                    }

                    // 2. Perform navigation
                    $res = $callApi('/navigate', ['url' => $url]);

                    // 3. Inspect if redirected to passport/login page
                    $currentUrl = strtolower($res['url'] ?? '');
                    if (str_contains($currentUrl, 'passport.') || str_contains($currentUrl, 'login.') || str_contains($currentUrl, '/login')) {
                        $warn = "⚠️ 提示：页面已被重定向至登录页面 ({$res['url']})，说明 Cookie 可能已失效。\n"
                              . "请在电脑浏览器重新登录对应网站，导出最新 Cookie 覆盖到 ~/.pig/agent/cookies.json。";
                        return new AgentToolResult([new TextContent($warn)]);
                    }

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
                        $contents[] = new ImageContent($base64, 'image/png');
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
