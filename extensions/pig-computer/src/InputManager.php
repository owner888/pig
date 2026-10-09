<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\Tui\Process;
use RuntimeException;

/**
 * Desktop mouse, keyboard, shortcuts and clipboard manager.
 * 100% pure PHP using native macOS utilities and AppleScript.
 */
final class InputManager
{
    public const array KEY_CODES = [
        'enter' => 36,
        'return' => 36,
        'tab' => 48,
        'space' => 49,
        'delete' => 51,
        'backspace' => 51,
        'escape' => 53,
        'esc' => 53,
        'command' => 55,
        'shift' => 56,
        'capslock' => 57,
        'option' => 58,
        'alt' => 58,
        'control' => 59,
        'ctrl' => 59,
        'right_shift' => 60,
        'right_option' => 61,
        'right_control' => 62,
        'up' => 126,
        'down' => 125,
        'left' => 123,
        'right' => 124,
    ];

    /**
     * Map logical coordinates to physical macOS screen Points with optional anti-ban jitter.
     *
     * @param array{float, float} $pixelToPoint
     * @return array{real_x: int, real_y: int}
     */
    public static function scaleCoordinates(
        float $x,
        float $y,
        array $pixelToPoint = [1.0, 1.0],
        bool $jitter = true
    ): array {
        $scaleX = $pixelToPoint[0] ?? 1.0;
        $scaleY = $pixelToPoint[1] ?? 1.0;

        $jitterX = $jitter ? random_int(-2, 2) : 0;
        $jitterY = $jitter ? random_int(-2, 2) : 0;

        $realX = max(0, (int) round($x * $scaleX) + $jitterX);
        $realY = max(0, (int) round($y * $scaleY) + $jitterY);

        return ['real_x' => $realX, 'real_y' => $realY];
    }

    /**
     * Enter text with support for full Unicode, CJK characters and multiline text via clipboard paste.
     */
    public static function typeText(string $text): void
    {
        if ($text === '') {
            return;
        }

        // If simple single-line ASCII without quotes/specials, use direct AppleScript keystroke
        if (preg_match('/^[a-zA-Z0-9.,_ \-+]+$/', $text) === 1) {
            $escaped = addcslashes($text, '"\\');
            Process::run(['/usr/bin/osascript', '-e', "tell application \"System Events\" to keystroke \"{$escaped}\""], timeout: 4.0);
            return;
        }

        // For CJK, quotes, or multiline: write to native macOS clipboard and paste via Command+V
        Process::feed(['/usr/bin/pbcopy'], $text, timeout: 4.0);
        Process::run(['/usr/bin/osascript', '-e', 'tell application "System Events" to keystroke "v" using command down'], timeout: 4.0);
    }

    /**
     * Press a single key or key combination (e.g. "Enter", "Tab", "cmd+c", "cmd+space").
     */
    public static function pressKey(string $key): void
    {
        $normalized = strtolower(trim($key));

        // 1. Direct single key code
        if (isset(self::KEY_CODES[$normalized])) {
            $code = self::KEY_CODES[$normalized];
            Process::run(['/usr/bin/osascript', '-e', "tell application \"System Events\" to key code {$code}"], timeout: 4.0);
            return;
        }

        // 2. Shortcut combinations like "cmd+c", "command+shift+4"
        if (str_contains($normalized, '+')) {
            $parts = explode('+', $normalized);
            $mainKey = array_pop($parts);
            $modifiers = [];

            foreach ($parts as $mod) {
                $mod = trim($mod);
                $modifiers[] = match ($mod) {
                    'cmd', 'command' => 'command down',
                    'shift' => 'shift down',
                    'option', 'alt' => 'option down',
                    'ctrl', 'control' => 'control down',
                    default => null,
                };
            }
            $modifiers = array_values(array_filter($modifiers));

            $modClause = $modifiers !== [] ? ' using {' . implode(', ', $modifiers) . '}' : '';

            if (isset(self::KEY_CODES[$mainKey])) {
                $code = self::KEY_CODES[$mainKey];
                Process::run(['/usr/bin/osascript', '-e', "tell application \"System Events\" to key code {$code}{$modClause}"], timeout: 4.0);
            } else {
                $escaped = addcslashes($mainKey, '"\\');
                Process::run(['/usr/bin/osascript', '-e', "tell application \"System Events\" to keystroke \"{$escaped}\"{$modClause}"], timeout: 4.0);
            }
            return;
        }

        // 3. Fallback: single character keystroke
        $escaped = addcslashes($normalized, '"\\');
        Process::run(['/usr/bin/osascript', '-e', "tell application \"System Events\" to keystroke \"{$escaped}\""], timeout: 4.0);
    }

    /**
     * Execute a sequence of operations with humanized micro-delays between steps.
     *
     * @param list<array{op: string, args?: array<string, mixed>}> $steps
     * @param array{float, float} $pixelToPoint
     * @return array{completed: int, results: list<array<string, mixed>>}
     */
    public static function batch(
        DesktopClient $desktop,
        array $steps,
        array $pixelToPoint = [1.0, 1.0]
    ): array {
        $results = [];
        $completed = 0;

        foreach ($steps as $step) {
            $op = (string) ($step['op'] ?? '');
            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            switch ($op) {
                case 'click':
                case 'tap':
                    $x = (float) ($args['x'] ?? 0);
                    $y = (float) ($args['y'] ?? 0);
                    $coords = self::scaleCoordinates($x, $y, $pixelToPoint, true);
                    $btn = (string) ($args['button'] ?? 'left');
                    $count = (int) ($args['click_count'] ?? 1);
                    $desktop->mouseClick((float) $coords['real_x'], (float) $coords['real_y'], $btn, $count);
                    $results[] = ['op' => 'click', 'ok' => true, 'coords' => $coords];
                    break;

                case 'drag':
                    $p1 = self::scaleCoordinates((float) ($args['from_x'] ?? 0), (float) ($args['from_y'] ?? 0), $pixelToPoint, false);
                    $p2 = self::scaleCoordinates((float) ($args['to_x'] ?? 0), (float) ($args['to_y'] ?? 0), $pixelToPoint, false);
                    $duration = (int) ($args['duration_ms'] ?? 300);
                    $desktop->mouseDrag((float) $p1['real_x'], (float) $p1['real_y'], (float) $p2['real_x'], (float) $p2['real_y'], $duration);
                    $results[] = ['op' => 'drag', 'ok' => true];
                    break;

                case 'scroll':
                    $dy = (int) ($args['delta_y'] ?? 10);
                    $dx = (int) ($args['delta_x'] ?? 0);
                    $desktop->mouseScroll($dy, $dx);
                    $results[] = ['op' => 'scroll', 'ok' => true];
                    break;

                case 'type_text':
                    $text = (string) ($args['text'] ?? '');
                    self::typeText($text);
                    $results[] = ['op' => 'type_text', 'ok' => true, 'length' => mb_strlen($text)];
                    break;

                case 'press_key':
                    $k = (string) ($args['key'] ?? 'return');
                    self::pressKey($k);
                    $results[] = ['op' => 'press_key', 'ok' => true, 'key' => $k];
                    break;

                case 'wait':
                    $sec = max(0.1, min(10.0, (float) ($args['seconds'] ?? 1.0)));
                    usleep((int) round($sec * 1000000));
                    $results[] = ['op' => 'wait', 'ok' => true, 'seconds' => $sec];
                    break;

                default:
                    throw new RuntimeException("Unsupported batch operation '{$op}'.");
            }

            $completed++;

            if ($completed < count($steps)) {
                usleep(random_int(120000, 300000));
            }
        }

        return ['completed' => $completed, 'results' => $results];
    }
}
