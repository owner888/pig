<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Throwable;

/**
 * Logger - 彩色日志与持久化工具类 (对齐 smart-book OmniPHP\Logger 规范)
 *
 * 支持 verbose、debug、info、warning、error 五个日志级别
 * 级别优先级：
 *   ERROR(4) > WARNING(3) > INFO(2) > DEBUG(1) > VERBOSE(0)
 *
 * 通过环境变量或参数控制最低输出级别：
 *   PIG_LOG_LEVEL=info (或 LOG_LEVEL=info) → 只输出 INFO/WARNING/ERROR（默认推荐）
 *   PIG_LOG_LEVEL=debug                    → 输出 DEBUG 及以上
 *   PIG_LOG_LEVEL=verbose                  → 输出全部详细日志
 *
 * 具有控制台彩色输出、文件自动按天轮转与过期清理（默认5天）以及 TUI 安全防干扰能力。
 */
class Logger
{
    // ANSI 颜色代码
    public const string COLOR_VERBOSE = '34'; // 蓝色
    public const string COLOR_DEBUG = '36';   // 青色
    public const string COLOR_INFO = '32';    // 绿色
    public const string COLOR_WARNING = '33'; // 黄色
    public const string COLOR_ERROR = '31';   // 红色

    // 日志级别名称
    public const string LEVEL_VERBOSE = 'VERBOSE';
    public const string LEVEL_DEBUG = 'DEBUG';
    public const string LEVEL_INFO = 'INFO';
    public const string LEVEL_WARNING = 'WARNING';
    public const string LEVEL_ERROR = 'ERROR';

    // 级别优先级
    public const array LEVEL_PRIORITY = [
        'VERBOSE' => 0,
        'DEBUG' => 1,
        'INFO' => 2,
        'WARNING' => 3,
        'WARN' => 3,
        'ERROR' => 4,
    ];

    private static bool $enabled = true;
    private static bool $showTimestamp = true;
    private static bool $consoleOutput = true;
    private static bool $enableFileLog = true;
    private static ?string $logDir = null;
    private static int $keepDays = 5;

    private static string $minLevel = 'INFO';
    private static ?bool $isCli = null;

    /** @var array<string, float> */
    private static array $timers = [];

    /** @var array<string, Closure(string, string, array<string, mixed>|string): void> */
    private static array $handlers = [];

    private static bool $initialized = false;

    /**
     * 初始化配置
     */
    public static function init(
        bool $enabled = true,
        bool $showTimestamp = true,
        bool $enableFileLog = true,
        ?string $logDir = null,
        ?string $minLevel = null,
    ): void {
        self::$enabled = $enabled;
        self::$showTimestamp = $showTimestamp;
        self::$enableFileLog = $enableFileLog;
        self::$logDir = $logDir;
        self::$isCli = php_sapi_name() === 'cli';

        $envLevel = getenv('PIG_LOG_LEVEL') ?: getenv('LOG_LEVEL');
        if (is_string($envLevel) && trim($envLevel) !== '') {
            self::setLevel($envLevel);
        } elseif ($minLevel !== null) {
            self::setLevel($minLevel);
        }

        $envKeep = getenv('PIG_LOG_KEEP_DAYS') ?: getenv('LOG_KEEP_DAYS');
        if ($envKeep !== false && is_numeric($envKeep)) {
            self::$keepDays = max(1, (int) $envKeep);
        }

        self::$initialized = true;
    }

    /**
     * 启用/禁用日志
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * 设置是否开启控制台输出（在 TUI raw 模式下可设为 false 避免破坏屏幕渲染）
     */
    public static function setConsoleOutput(bool $enable): void
    {
        self::$consoleOutput = $enable;
    }

    /**
     * 设置最低输出级别
     */
    public static function setLevel(string $level): void
    {
        $normalized = strtoupper(trim($level));
        if (isset(self::LEVEL_PRIORITY[$normalized])) {
            self::$minLevel = $normalized === 'WARN' ? self::LEVEL_WARNING : $normalized;
        }
    }

    public static function getLevel(): string
    {
        return self::$minLevel;
    }

    public static function isLevelEnabled(string $level): bool
    {
        $levelPriority = self::LEVEL_PRIORITY[strtoupper($level)] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[self::$minLevel] ?? 2;

        return $levelPriority >= $minPriority;
    }

    public static function setShowTimestamp(bool $show): void
    {
        self::$showTimestamp = $show;
    }

    public static function setLogDir(?string $dir): void
    {
        self::$logDir = $dir;
    }

    /**
     * 注册额外自定义处理回调（如扩展上报、外部监控）
     *
     * @param Closure(string, string, array<string, mixed>|string): void $handler
     */
    public static function addHandler(string $name, Closure $handler): void
    {
        self::$handlers[$name] = $handler;
    }

    public static function removeHandler(string $name): void
    {
        unset(self::$handlers[$name]);
    }

    public static function reset(): void
    {
        self::$enabled = true;
        self::$showTimestamp = true;
        self::$consoleOutput = true;
        self::$enableFileLog = true;
        self::$logDir = null;
        self::$keepDays = 5;
        self::$minLevel = 'INFO';
        self::$timers = [];
        self::$handlers = [];
        self::$initialized = false;
    }

    // ---- 核心级别记录方法 -----------------------------------------------------------

    /**
     * VERBOSE 级别 - 蓝色（最详细追踪，如流式 chunk、内部原始信号）
     *
     * @param array<string, mixed>|string $context
     */
    public static function verbose(string $message, array|string $context = []): void
    {
        self::log(self::LEVEL_VERBOSE, $message, self::COLOR_VERBOSE, $context);
    }

    /**
     * DEBUG 级别 - 青色（诊断排查、状态机变更、缓存命中）
     *
     * @param array<string, mixed>|string $context
     */
    public static function debug(string $message, array|string $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, self::COLOR_DEBUG, $context);
    }

    /**
     * INFO 级别 - 绿色（常规生命周期事件、就绪通知、会话切换）
     *
     * @param array<string, mixed>|string $context
     */
    public static function info(string $message, array|string $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, self::COLOR_INFO, $context);
    }

    /**
     * WARNING 级别 - 黄色（非致命故障、网络重试触发、过期警告）
     *
     * @param array<string, mixed>|string $context
     */
    public static function warning(string $message, array|string $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, self::COLOR_WARNING, $context);
    }

    /**
     * ERROR 级别 - 红色（请求失败、异常捕获、进程故障）
     *
     * @param array<string, mixed>|string $context
     */
    public static function error(string $message, array|string $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, self::COLOR_ERROR, $context);
    }

    /**
     * 调试打印结构化数据
     */
    public static function dump(string $label, mixed $data): void
    {
        if (!self::$enabled || !self::isLevelEnabled(self::LEVEL_DEBUG)) {
            return;
        }

        $formatted = print_r($data, true);
        self::debug("{$label}: {$formatted}");
    }

    /**
     * 性能计时器开始
     */
    public static function time(string $label): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$timers[$label] = microtime(true);
        self::debug("Timer '{$label}' started");
    }

    /**
     * 性能计时器结束并输出耗时
     */
    public static function timeEnd(string $label): void
    {
        if (!self::$enabled || !isset(self::$timers[$label])) {
            return;
        }

        $elapsed = (microtime(true) - self::$timers[$label]) * 1000;
        unset(self::$timers[$label]);

        self::debug(sprintf("Timer '%s': %.2f ms", $label, $elapsed));
    }

    // ---- 内部处理与写入 -------------------------------------------------------------

    /**
     * @param array<string, mixed>|string $context
     */
    private static function log(string $level, string $message, string $color, array|string $context = []): void
    {
        if (!self::$enabled) {
            return;
        }

        if (!self::$initialized) {
            self::init();
        }

        // 级别过滤
        $levelPriority = self::LEVEL_PRIORITY[$level] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[self::$minLevel] ?? 2;
        if ($levelPriority < $minPriority) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $contextStr = '';
        if ($context !== [] && $context !== '') {
            $contextStr = is_array($context)
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $context;
        }

        // 1. 控制台输出
        if (self::$consoleOutput) {
            $timestamp = self::$showTimestamp ? "[{$now}] " : '';
            $prefix = sprintf('[%-7s]', $level);

            if (self::$isCli ?? (php_sapi_name() === 'cli')) {
                $coloredPrefix = "\033[{$color}m{$prefix}\033[0m";
                $line = $timestamp . $coloredPrefix . ' ' . $message;
            } else {
                $line = $timestamp . $prefix . ' ' . $message;
            }

            if ($contextStr !== '') {
                $line .= ' ' . $contextStr;
            }

            if (is_resource(STDERR)) {
                fwrite(STDERR, $line . PHP_EOL);
            } else {
                echo $line . PHP_EOL;
            }
        }

        // 2. 文件持久化
        if (self::$enableFileLog) {
            self::writeToFile($now, $level, $message, $contextStr);
        }

        // 3. 自定义 Handlers
        foreach (self::$handlers as $handler) {
            try {
                $handler($level, $message, $context);
            } catch (Throwable) {
                // Handlers must not throw out
            }
        }
    }

    private static function writeToFile(string $now, string $level, string $message, string $contextStr): void
    {
        try {
            $dir = self::$logDir ?? (Config::home() . '/logs');
            if (!is_dir($dir)) {
                set_error_handler(static fn (): bool => true);
                try {
                    mkdir($dir, 0755, true);
                } finally {
                    restore_error_handler();
                }
            }

            $date = date('Y-m-d');
            $file = "{$dir}/pig-{$date}.log";
            $prefix = sprintf('[%-7s]', $level);
            $line = "[{$now}] {$prefix} {$message}";
            if ($contextStr !== '') {
                $line .= ' ' . $contextStr;
            }
            $line .= PHP_EOL;

            set_error_handler(static fn (): bool => true);
            try {
                file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            } finally {
                restore_error_handler();
            }

            // 偶尔触发日志过期清理（约 1% 概率避免每次写文件都进行全目录扫盘）
            if (random_int(1, 100) === 1) {
                self::rotateLogs($dir);
            }
        } catch (Throwable) {
            // File logging must be fail-safe
        }
    }

    /**
     * 清理过期日志文件
     */
    public static function rotateLogs(?string $logDir = null): void
    {
        $dir = $logDir ?? (self::$logDir ?? (Config::home() . '/logs'));
        if (!is_dir($dir)) {
            return;
        }

        $files = glob("{$dir}/pig-*.log");
        if ($files === false || $files === []) {
            return;
        }

        $now = time();
        $retentionSeconds = self::$keepDays * 86400;

        foreach ($files as $file) {
            if (is_file($file)) {
                $mtime = filemtime($file);
                if ($mtime !== false && ($now - $mtime) > $retentionSeconds) {
                    set_error_handler(static fn (): bool => true);
                    try {
                        unlink($file);
                    } finally {
                        restore_error_handler();
                    }
                }
            }
        }
    }
}

// Global alias for convenient access across extensions, packages, and scripts
if (!class_exists(\Pig\Logger::class, false)) {
    class_alias(Logger::class, \Pig\Logger::class);
}
