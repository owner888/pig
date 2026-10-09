<?php

declare(strict_types=1);

namespace Pig\Extensions\Computer;

use Pig\Tui\Process;
use RuntimeException;

/**
 * Native desktop interaction client for macOS via JXA / CoreGraphics and native utilities.
 * 100% pure PHP without external language wrappers or heavy docker services.
 */
final class DesktopClient
{
    public function isAvailable(): bool
    {
        return PHP_OS_FAMILY === 'Darwin'
            && file_exists('/usr/sbin/screencapture')
            && file_exists('/usr/bin/osascript');
    }

    /**
     * Get logical screen size in Points (e.g. 1470x956 on 14" MBP Retina).
     *
     * @return array{width: int, height: int}
     */
    public function screenSize(): array
    {
        $script = <<<JS
ObjC.import('AppKit');
var f = $.NSScreen.mainScreen.frame;
JSON.stringify({width: Math.round(f.size.width), height: Math.round(f.size.height)});
JS;
        [$exit, $stdout] = Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 5.0);
        if ($exit === 0) {
            $parsed = json_decode(trim($stdout), true);
            if (is_array($parsed) && isset($parsed['width'], $parsed['height']) && $parsed['width'] > 0) {
                return ['width' => (int) $parsed['width'], 'height' => (int) $parsed['height']];
            }
        }

        // Fallback default
        return ['width' => 1440, 'height' => 900];
    }

    /**
     * Get the currently active frontmost desktop application.
     *
     * @return array{name: string, bundleId: string}|null
     */
    public function frontmostApp(): ?array
    {
        $script = <<<JS
ObjC.import('AppKit');
var app = $.NSWorkspace.sharedWorkspace.frontmostApplication;
JSON.stringify({
    name: app.localizedName ? app.localizedName.js : '',
    bundleId: app.bundleIdentifier ? app.bundleIdentifier.js : ''
});
JS;
        [$exit, $stdout] = Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 5.0);
        if ($exit === 0) {
            $parsed = json_decode(trim($stdout), true);
            if (is_array($parsed) && !empty($parsed['name'])) {
                return ['name' => (string) $parsed['name'], 'bundleId' => (string) ($parsed['bundleId'] ?? '')];
            }
        }

        return null;
    }

    /**
     * Capture silent screen image with cursor directly into a PNG file.
     */
    public function captureRawPng(string $destinationPath): bool
    {
        // -x: do not play sound; -C: capture cursor
        [$exit] = Process::run(['/usr/sbin/screencapture', '-x', '-C', $destinationPath], timeout: 8.0);
        return $exit === 0 && file_exists($destinationPath) && filesize($destinationPath) > 64;
    }

    /**
     * Move mouse cursor to (x, y).
     */
    public function mouseMove(float $x, float $y): void
    {
        $script = <<<JS
ObjC.import('CoreGraphics');
var pt = $.CGPointMake({$x}, {$y});
var ev = $.CGEventCreateMouseEvent(null, $.kCGEventMouseMoved, pt, 0);
$.CGEventPost($.kCGHIDEventTap, ev);
JS;
        Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 4.0);
    }

    /**
     * Click mouse at (x, y). Supports left, right, middle buttons and double clicks.
     */
    public function mouseClick(float $x, float $y, string $button = 'left', int $clickCount = 1): void
    {
        $downEvent = match ($button) {
            'right' => '$.kCGEventRightMouseDown',
            'middle' => '$.kCGEventOtherMouseDown',
            default => '$.kCGEventLeftMouseDown',
        };
        $upEvent = match ($button) {
            'right' => '$.kCGEventRightMouseUp',
            'middle' => '$.kCGEventOtherMouseUp',
            default => '$.kCGEventLeftMouseUp',
        };
        $mouseButton = match ($button) {
            'right' => '$.kCGMouseButtonRight',
            'middle' => '$.kCGMouseButtonCenter',
            default => '$.kCGMouseButtonLeft',
        };

        if ($clickCount >= 2) {
            $script = <<<JS
ObjC.import('CoreGraphics');
var pt = $.CGPointMake({$x}, {$y});
var d1 = $.CGEventCreateMouseEvent(null, {$downEvent}, pt, {$mouseButton});
var u1 = $.CGEventCreateMouseEvent(null, {$upEvent}, pt, {$mouseButton});
var d2 = $.CGEventCreateMouseEvent(null, {$downEvent}, pt, {$mouseButton});
var u2 = $.CGEventCreateMouseEvent(null, {$upEvent}, pt, {$mouseButton});
$.CGEventSetIntegerValueField(d2, $.kCGMouseEventClickState, 2);
$.CGEventSetIntegerValueField(u2, $.kCGMouseEventClickState, 2);
$.CGEventPost($.kCGHIDEventTap, d1);
$.CGEventPost($.kCGHIDEventTap, u1);
$.CGEventPost($.kCGHIDEventTap, d2);
$.CGEventPost($.kCGHIDEventTap, u2);
JS;
        } else {
            $script = <<<JS
ObjC.import('CoreGraphics');
var pt = $.CGPointMake({$x}, {$y});
var down = $.CGEventCreateMouseEvent(null, {$downEvent}, pt, {$mouseButton});
var up = $.CGEventCreateMouseEvent(null, {$upEvent}, pt, {$mouseButton});
$.CGEventPost($.kCGHIDEventTap, down);
$.CGEventPost($.kCGHIDEventTap, up);
JS;
        }

        Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 4.0);
    }

    /**
     * Drag mouse from (fromX, fromY) to (toX, toY).
     */
    public function mouseDrag(float $fromX, float $fromY, float $toX, float $toY, int $durationMs = 300): void
    {
        $durationMs = max(50, min(3000, $durationMs));
        $script = <<<JS
ObjC.import('CoreGraphics');
var p1 = $.CGPointMake({$fromX}, {$fromY});
var p2 = $.CGPointMake({$toX}, {$toY});
var down = $.CGEventCreateMouseEvent(null, $.kCGEventLeftMouseDown, p1, $.kCGMouseButtonLeft);
$.CGEventPost($.kCGHIDEventTap, down);

var steps = 8;
for (var i = 1; i <= steps; i++) {
    var curX = {$fromX} + ({$toX} - {$fromX}) * (i / steps);
    var curY = {$fromY} + ({$toY} - {$fromY}) * (i / steps);
    var drag = $.CGEventCreateMouseEvent(null, $.kCGEventLeftMouseDragged, $.CGPointMake(curX, curY), $.kCGMouseButtonLeft);
    $.CGEventPost($.kCGHIDEventTap, drag);
}

var up = $.CGEventCreateMouseEvent(null, $.kCGEventLeftMouseUp, p2, $.kCGMouseButtonLeft);
$.CGEventPost($.kCGHIDEventTap, up);
JS;
        Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 5.0);
    }

    /**
     * Scroll wheel vertically or horizontally.
     */
    public function mouseScroll(int $deltaY, int $deltaX = 0): void
    {
        // deltaY > 0 scrolls down (or content moves up); CoreGraphics takes line units
        $script = <<<JS
ObjC.import('CoreGraphics');
var ev = $.CGEventCreateScrollWheelEvent(null, 1, 2, {$deltaY}, {$deltaX});
$.CGEventPost($.kCGHIDEventTap, ev);
JS;
        Process::run(['/usr/bin/osascript', '-l', 'JavaScript', '-e', $script], timeout: 4.0);
    }

    /**
     * Launch an application or open a URL.
     */
    public function launchAppOrUrl(string $target): bool
    {
        if (preg_match('/^https?:\/\//i', $target)) {
            [$exit] = Process::run(['/usr/bin/open', $target], timeout: 6.0);
            return $exit === 0;
        }

        [$exit] = Process::run(['/usr/bin/open', '-a', $target], timeout: 6.0);
        return $exit === 0;
    }
}
