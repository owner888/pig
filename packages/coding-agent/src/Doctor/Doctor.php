<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Doctor;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Stream;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\Tui\Env;
use Pig\Tui\Process;
use Pig\Tui\Style;

/**
 * System-wide diagnostic inspector and health reporter.
 *
 * Checks PHP runtime, external tooling, credentials, proxy connectivity, and active session state.
 */
final class Doctor
{
    public static function inspect(AgentSession $session, ?Auth $auth = null): DoctorReport
    {
        $auth ??= Auth::discover();

        // 1. PHP Runtime & Extensions
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.3.0', '>=');
        $extensions = [
            'ext-pcntl' => extension_loaded('pcntl'),
            'ext-mbstring' => extension_loaded('mbstring'),
            'ext-openssl' => extension_loaded('openssl'),
            'ext-json' => extension_loaded('json'),
        ];

        // 2. Binaries & Tooling
        $stty = Process::capture(['which', 'stty']);
        $git = Process::capture(['which', 'git']);
        $fd = ExternalTool::locate('fd');
        $rg = ExternalTool::locate('rg');

        $clipboardText = self::detectTextClipboard();
        $clipboardImage = self::detectImageClipboard();

        // 3. Auth & Providers
        $authPath = $auth->path();
        $authReadable = $authPath !== null && is_file($authPath) && is_readable($authPath);
        $authValid = $authReadable && $auth->problems() === [];

        $providersList = [];
        $knownProviders = ['anthropic', 'openai', 'google', 'google-vertex', 'amazon-bedrock', 'github-copilot', 'xai', 'groq', 'deepseek'];
        $discovered = array_unique([...$auth->providers(), ...$knownProviders]);

        foreach ($discovered as $p) {
            if (!$auth->hasKeyFor($p) && !$auth->has($p)) {
                continue;
            }

            $detail = 'API key / token present';
            $type = 'api_key';

            // Vertex's Application Default Credentials and Bedrock's AWS sources are credentials
            // that are not a key: `Stream::envApiKey()` answers the ambient marker for them.
            if (!$auth->has($p) && Stream::envApiKey($p) === Stream::AMBIENT_AUTH_MARKER) {
                $detail = $p === 'amazon-bedrock' ? 'AWS credentials in the environment' : 'Application Default Credentials';
            }

            // The built-in enum or an extension's flow — `Auth::signIn()` knows both.
            if (Auth::signIn($p) !== null) {
                $cred = $auth->credentials($p);
                if ($cred !== null) {
                    $type = 'oauth';
                    $now = (int) (microtime(true) * 1000);
                    $remSec = (int) (($cred->expires - $now) / 1000);
                    $email = $cred->email ? " ({$cred->email})" : '';
                    $detail = $remSec > 0 ? "OAuth token valid (~{$remSec}s remaining){$email}" : "OAuth token expired (refreshable){$email}";
                }
            }

            $providersList[] = [
                'provider' => $p,
                'type' => $type,
                'detail' => $detail,
                'active' => $session->model()?->provider === $p,
            ];
        }

        // One sign-in per extension provider counts for one here; an extension that keeps
        // several accounts has its own `/doctor` command for them (`/antigravity.doctor`).
        $antigravityAccounts = $auth->credentials('antigravity') !== null ? 1 : 0;

        // 4. Network & Proxy
        $proxy = HttpClient::proxy();
        $proxyEnabled = $proxy !== null;
        $proxyUrl = $proxy?->describe();
        $proxyScheme = $proxyUrl !== null ? parse_url($proxyUrl, PHP_URL_SCHEME) : null;

        // 5. Session & Working Directory
        $cwd = $session->cwd();
        $model = $session->model();
        $modelId = $model?->id ?? 'no-model';
        $providerName = $model?->provider ?? 'unknown';
        $thinking = $session->thinkingLevel()->value;

        $gitBranch = null;
        $gitDirty = false;
        [$branchCode, $branchOut] = Process::run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], cwd: $cwd);
        if ($branchCode === 0) {
            $gitBranch = trim($branchOut);
            [$statusCode, $statusOut] = Process::run(['git', 'status', '--porcelain'], cwd: $cwd);
            $gitDirty = $statusCode === 0 && trim($statusOut) !== '';
        }

        $sessionStore = $session->store();
        $sessionFile = $sessionStore?->path;
        $sessionSize = $sessionFile !== null && is_file($sessionFile) ? filesize($sessionFile) : null;
        $messageCount = count($session->messages());

        return new DoctorReport(
            php: [
                'version' => $phpVersion,
                'ok' => $phpOk,
                'extensions' => $extensions,
            ],
            binaries: [
                'stty' => is_string($stty) && $stty !== '' ? trim($stty) : null,
                'git' => is_string($git) && $git !== '' ? trim($git) : null,
                'fd' => $fd !== false ? $fd : null,
                'rg' => $rg !== false ? $rg : null,
                'clipboardText' => $clipboardText,
                'clipboardImage' => $clipboardImage,
            ],
            auth: [
                'path' => $authPath,
                'readable' => $authReadable,
                'valid' => $authValid,
                'providers' => $providersList,
                'antigravityAccounts' => $antigravityAccounts,
            ],
            proxy: [
                'enabled' => $proxyEnabled,
                'url' => $proxyUrl,
                'scheme' => is_string($proxyScheme) ? $proxyScheme : null,
            ],
            session: [
                'cwd' => $cwd,
                'model' => $modelId,
                'provider' => $providerName,
                'thinkingLevel' => $thinking,
                'gitBranch' => $gitBranch,
                'gitDirty' => $gitDirty,
                'sessionFile' => $sessionFile,
                'sessionSize' => is_int($sessionSize) ? $sessionSize : null,
                'messageCount' => $messageCount,
            ],
        );
    }

    public static function renderTui(DoctorReport $report): string
    {
        $tick = Themes::theme()->fg('success', '✓');
        $cross = Themes::theme()->fg('error', '✗');
        $warn = Themes::theme()->fg('warning', '!');

        $lines = [];

        // 1. PHP Runtime
        $lines[] = Themes::theme()->fg('accent', Style::bold('[1. PHP Runtime & Extensions]'));
        $phpMark = $report->php['ok'] ? $tick : $cross;
        $lines[] = "  {$phpMark} PHP {$report->php['version']} " . ($report->php['ok'] ? Themes::theme()->fg('dim', '(>= 8.3 required)') : Themes::theme()->fg('error', '(PHP >= 8.3 required)'));

        foreach ($report->php['extensions'] as $ext => $loaded) {
            $m = $loaded ? $tick : $cross;
            $note = $loaded ? Themes::theme()->fg('dim', 'loaded') : Themes::theme()->fg('error', 'missing');
            $lines[] = "  {$m} {$ext} {$note}";
        }

        $lines[] = '';

        // 2. Binaries
        $lines[] = Themes::theme()->fg('accent', Style::bold('[2. External Binaries & Tooling]'));
        $sttyMark = $report->binaries['stty'] !== null ? $tick : $cross;
        $lines[] = "  {$sttyMark} stty: " . ($report->binaries['stty'] ?? Themes::theme()->fg('error', 'missing (required for TUI)'));

        $gitMark = $report->binaries['git'] !== null ? $tick : $warn;
        $lines[] = "  {$gitMark} git: " . ($report->binaries['git'] ?? Themes::theme()->fg('warning', 'not in PATH'));

        $fdMark = $report->binaries['fd'] !== null ? $tick : $warn;
        $lines[] = "  {$fdMark} fd: " . ($report->binaries['fd'] ?? Themes::theme()->fg('warning', 'missing (will auto-download on use)'));

        $rgMark = $report->binaries['rg'] !== null ? $tick : $warn;
        $lines[] = "  {$rgMark} ripgrep: " . ($report->binaries['rg'] ?? Themes::theme()->fg('warning', 'missing (will auto-download on use)'));

        $clipTextMark = $report->binaries['clipboardText'] !== null ? $tick : $warn;
        $lines[] = "  {$clipTextMark} text clipboard: " . ($report->binaries['clipboardText'] ?? Themes::theme()->fg('warning', 'none detected'));

        $clipImgMark = $report->binaries['clipboardImage'] !== null ? $tick : $warn;
        $lines[] = "  {$clipImgMark} image clipboard: " . ($report->binaries['clipboardImage'] ?? Themes::theme()->fg('warning', 'none detected'));

        $lines[] = '';

        // 3. Authentication & Providers
        $lines[] = Themes::theme()->fg('accent', Style::bold('[3. Model Providers & Credentials]'));
        $authMark = $report->auth['readable'] ? $tick : $warn;
        $authPathStr = $report->auth['path'] ? Themes::theme()->fg('dim', $report->auth['path']) : Themes::theme()->fg('warning', 'in-memory / no file');
        $lines[] = "  {$authMark} auth file: {$authPathStr}";

        if ($report->auth['providers'] === []) {
            $lines[] = "  {$warn} No authorized providers configured. Run /login or set API keys.";
        } else {
            foreach ($report->auth['providers'] as $p) {
                $star = $p['active'] ? Themes::theme()->fg('accent', '*') : ' ';
                $lines[] = "  {$tick} {$star} {$p['provider']} [{$p['type']}]: " . Themes::theme()->fg('dim', $p['detail']);
            }
        }

        if ($report->auth['antigravityAccounts'] > 0) {
            $lines[] = "    " . Themes::theme()->fg('dim', "Antigravity linked accounts: {$report->auth['antigravityAccounts']} (auto-failover ready)");
        }

        $lines[] = '';

        // 4. Network & Proxy
        $lines[] = Themes::theme()->fg('accent', Style::bold('[4. Network & Proxy]'));
        if ($report->proxy['enabled']) {
            $lines[] = "  {$tick} Proxy active: " . Themes::theme()->fg('accent', (string) $report->proxy['url']);
        } else {
            $lines[] = "  {$tick} Direct connection (no proxy configured)";
        }

        $lines[] = '';

        // 5. Active Session
        $lines[] = Themes::theme()->fg('accent', Style::bold('[5. Active Session State]'));
        $branchInfo = $report->session['gitBranch'] !== null
            ? $report->session['gitBranch'] . ($report->session['gitDirty'] ? Themes::theme()->fg('warning', ' *') : '')
            : 'not a git repo';

        $lines[] = "  Directory: " . Themes::theme()->fg('dim', $report->session['cwd']) . " ({$branchInfo})";
        $lines[] = "  Model:     " . Themes::theme()->fg('accent', "({$report->session['provider']}) {$report->session['model']} • {$report->session['thinkingLevel']}");

        $fileSizeStr = $report->session['sessionSize'] !== null
            ? sprintf(' (%.1f KB, %d messages)', $report->session['sessionSize'] / 1024, $report->session['messageCount'])
            : " ({$report->session['messageCount']} messages)";

        $sessionFile = $report->session['sessionFile'] ? basename($report->session['sessionFile']) : 'unsaved';
        $lines[] = "  Session:   " . Themes::theme()->fg('dim', $sessionFile . $fileSizeStr);

        return implode("\n", $lines);
    }

    public static function renderPlain(DoctorReport $report): string
    {
        $lines = [];

        $lines[] = '[1. PHP Runtime & Extensions]';
        $lines[] = '  ' . ($report->php['ok'] ? '[OK]' : '[FAIL]') . " PHP {$report->php['version']}";
        foreach ($report->php['extensions'] as $ext => $loaded) {
            $lines[] = '  ' . ($loaded ? '[OK]' : '[MISSING]') . " {$ext}";
        }
        $lines[] = '';

        $lines[] = '[2. External Binaries & Tooling]';
        $lines[] = '  stty: ' . ($report->binaries['stty'] ?? 'missing');
        $lines[] = '  git:  ' . ($report->binaries['git'] ?? 'missing');
        $lines[] = '  fd:   ' . ($report->binaries['fd'] ?? 'missing');
        $lines[] = '  rg:   ' . ($report->binaries['rg'] ?? 'missing');
        $lines[] = '  text clipboard:  ' . ($report->binaries['clipboardText'] ?? 'none');
        $lines[] = '  image clipboard: ' . ($report->binaries['clipboardImage'] ?? 'none');
        $lines[] = '';

        $lines[] = '[3. Model Providers & Credentials]';
        $lines[] = '  auth file: ' . ($report->auth['path'] ?? 'none');
        foreach ($report->auth['providers'] as $p) {
            $mark = $p['active'] ? '*' : ' ';
            $lines[] = "  {$mark} {$p['provider']} [{$p['type']}]: {$p['detail']}";
        }
        $lines[] = '';

        $lines[] = '[4. Network & Proxy]';
        $lines[] = $report->proxy['enabled'] ? "  Proxy active: {$report->proxy['url']}" : '  Direct connection';
        $lines[] = '';

        $lines[] = '[5. Active Session State]';
        $lines[] = "  Directory: {$report->session['cwd']}";
        $lines[] = "  Model:     ({$report->session['provider']}) {$report->session['model']} • {$report->session['thinkingLevel']}";
        $lines[] = '  Session:   ' . ($report->session['sessionFile'] ? basename($report->session['sessionFile']) : 'unsaved');

        return implode("\n", $lines);
    }

    private static function detectTextClipboard(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return Process::capture(['which', 'pbcopy']) ? 'pbcopy/pbpaste' : null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'clip.exe';
        }

        if (Env::isSet('WAYLAND_DISPLAY') && Process::capture(['which', 'wl-copy'])) {
            return 'wl-clipboard';
        }

        if (Process::capture(['which', 'xclip'])) {
            return 'xclip';
        }

        if (Process::capture(['which', 'xsel'])) {
            return 'xsel';
        }

        return null;
    }

    private static function detectImageClipboard(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (Process::capture(['which', 'pngpaste'])) {
                return 'pngpaste';
            }
            if (Process::capture(['which', 'osascript'])) {
                return 'osascript (built-in)';
            }
        }

        if (Env::isSet('WAYLAND_DISPLAY') && Process::capture(['which', 'wl-paste'])) {
            return 'wl-paste';
        }

        if (Process::capture(['which', 'xclip'])) {
            return 'xclip';
        }

        return null;
    }
}
