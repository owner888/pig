<?php

declare(strict_types=1);

use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\Events\SessionShutdownEvent;
use Pig\CodingAgent\Extensions\Events\SessionStartEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Logger;
use PigVless\VlessServer;

require_once __DIR__ . '/VlessServer.php';

/**
 * A VLESS inbound beside the agent, so a phone can go through this machine.
 *
 * An extension and not a mode or a package, on purpose: it has nothing to do with the agent,
 * it only shares the loop. Loading it fills in `settings.json` — a listen address and a UUID,
 * made once and kept, so the phone's link stays valid across restarts — but **listens on
 * nothing until asked**: `/vless start`. `/web`'s shape: `start | stop | restart | status`,
 * and the bare command is `status`, because a proxy port is not something to open by
 * accident. The session ending stops it.
 *
 *     "vless": {
 *       "listen": "0.0.0.0:10086",      // written on first load
 *       "uuid":   "…",                   // likewise
 *       "cert":   "/path/fullchain.pem", // optional; both set → TLS, else plain
 *       "key":    "/path/privkey.pem",
 *       "wsPath": "/vless"               // optional; when set → WebSocket, else raw TCP
 *     }
 *
 * See `VlessServer` for what the protocol is and what is left out (UDP, MUX, everything Xray
 * added after the header).
 */
return function (ExtensionApi $pig): void {
    /** @var VlessServer|null the server as configured, started or not */
    $server = null;
    $running = false;
    $problem = null;

    $pig->on('session_start', static function (SessionStartEvent $event, ExtensionContext $ctx) use ($pig, &$server, &$problem): void {
        $settings = $pig->getSettings();
        $config = $settings?->get('vless');
        $config = is_array($config) ? $config : [];

        $listen = $config['listen'] ?? null;

        if (!is_string($listen) || $listen === '') {
            $listen = '0.0.0.0:10086';
            $settings?->set('vless.listen', $listen);
        }

        $uuid = $config['uuid'] ?? null;

        if (!is_string($uuid) || $uuid === '') {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
            $settings?->set('vless.uuid', $uuid);
        }

        [$host, $port] = str_contains($listen, ':') ? explode(':', $listen, 2) : [$listen, '10086'];

        $wsPath = $config['wsPath'] ?? $config['path'] ?? null;

        try {
            $server = new VlessServer(
                $host,
                ctype_digit($port) ? (int) $port : 10086,
                $uuid,
                is_string($config['cert'] ?? null) ? $config['cert'] : null,
                is_string($config['key'] ?? null) ? $config['key'] : null,
                is_string($wsPath) ? $wsPath : null,
            );
            $problem = null;
        } catch (Throwable $e) {
            $server = null;
            $problem = $e->getMessage();
            Logger::warning("[vless] bad configuration: {$problem}");
        }
    });

    $pig->on('session_shutdown', static function (SessionShutdownEvent $event, ExtensionContext $ctx) use (&$server, &$running): void {
        if ($running) {
            $server?->stop();
            $running = false;
        }
    });

    $pig->registerCommand(
        'vless',
        static function (string $args, ExtensionContext $ctx) use (&$server, &$running, &$problem): void {
            $say = static function (string $text, string $level = 'info') use ($ctx): void {
                if ($ctx->hasUi) {
                    $ctx->ui->notify($text, $level);
                } else {
                    echo $text, "\n";
                }
            };

            $action = strtolower(trim($args));

            if ($action === '') {
                $action = 'status';
            }

            if (!in_array($action, ['start', 'stop', 'restart', 'status'], true)) {
                $say('Usage: /vless start | stop | restart | status', 'warning');

                return;
            }

            if ($server === null) {
                $say('VLESS cannot run: ' . ($problem ?? 'the session has not started yet'), 'warning');

                return;
            }

            if ($action === 'stop' || $action === 'restart') {
                if ($running) {
                    $server->stop();
                    $running = false;
                    Logger::info('[vless] stopped');
                }

                if ($action === 'stop') {
                    $say('VLESS stopped.');

                    return;
                }
            }

            $mode = ($server->isWs() ? "WS {$server->wsPath}" : 'TCP') . ($server->isTls() ? ' + TLS' : '');

            if ($action === 'start' || $action === 'restart') {
                if ($running) {
                    $say("VLESS is already listening on {$server->host}:{$server->port} ({$mode}).");

                    return;
                }

                try {
                    $server->start();
                    $running = true;
                    Logger::info("[vless] listening on {$server->host}:{$server->port} ({$mode})");
                } catch (Throwable $e) {
                    $say("VLESS could not start: {$e->getMessage()}", 'warning');

                    return;
                }
            }

            $where = $server->host === '0.0.0.0' || $server->host === '::' ? '<this machine\'s address>' : $server->host;
            $say(
                ($running
                    ? "VLESS listening on {$server->host}:{$server->port} ({$mode}), {$server->connections()} socket(s) open."
                    : "VLESS is not listening (configured for {$server->host}:{$server->port}, {$mode}). `/vless start` opens it.")
                . "\nLink for the phone: " . $server->shareLink($where),
                $running ? 'info' : 'warning',
            );
        },
        'VLESS inbound: start | stop | restart | status',
    );
};
