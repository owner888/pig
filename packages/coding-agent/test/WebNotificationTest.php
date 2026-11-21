<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * Android Chrome has no `Notification` constructor for a page: it throws, and a phone with permission
 * granted, over https, showed nothing. The page's notifications go through its service worker
 * (`sw.js`, `attention.js`), and nothing in the page's scripts goes back to the constructor.
 */
final class WebNotificationTest extends TestCase
{
    public function testNotificationsGoThroughTheServiceWorker(): void
    {
        $js = dirname(__DIR__) . '/src/Web/assets/js';
        $files = [...glob($js . '/*.js') ?: [], ...glob($js . '/components/*.js') ?: []];
        $this->assertNotEmpty($files);

        $constructed = [];
        foreach ($files as $file) {
            if (preg_match('/new\s+Notification\s*\(/', (string) file_get_contents($file)) === 1) {
                $constructed[] = basename($file);
            }
        }
        $this->assertSame([], $constructed, 'Show a notification with `registration.showNotification()` (attention.js), not the `Notification` constructor.');

        $this->assertFileExists($js . '/sw.js');
        $attention = (string) file_get_contents($js . '/attention.js');
        $this->assertStringContainsString('register("/assets/js/sw.js")', $attention);
        $this->assertStringContainsString('showNotification(', $attention);
        $this->assertStringContainsString('notificationclick', (string) file_get_contents($js . '/sw.js'));
    }
}
