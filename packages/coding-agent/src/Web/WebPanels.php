<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\WebPanel;
use Throwable;

/**
 * `/api/panels`: the extensions' panels (`ExtensionApi::registerWebPanel()`) as the page asks for
 * them. Answers in the shape of an extension's HTTP route, and from the same fiber, because a
 * panel's `render` may make a network call.
 *
 * - `GET /api/panels?locale=` — `{panels: [{id, title, icon, commands}]}`, for the sidebar
 * - `GET /api/panels/<id>?locale=&section=` — `{ok, blocks}`, the panel or one of its lazy sections
 * - `POST /api/panels/<id>` with `{action, args, locale}` — the action, then the panel drawn again:
 *   `{ok, error?, blocks}`
 */
final class WebPanels
{
    public static function handles(string $path): bool
    {
        return $path === '/api/panels' || str_starts_with($path, '/api/panels/');
    }

    /**
     * @param array{method: string, body: string, query: array<string, string>} $req
     * @return array{status: int, body: mixed}
     */
    public static function answer(string $path, array $req): array
    {
        if ($path === '/api/panels') {
            $locale = self::locale($req['query']['locale'] ?? null);

            return ['status' => 200, 'body' => ['panels' => array_map(
                static fn (WebPanel $panel): array => $panel->describe($locale),
                ExtensionApi::webPanels(),
            )]];
        }

        $panel = ExtensionApi::webPanel(rawurldecode(substr($path, strlen('/api/panels/'))));

        if ($panel === null) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'No such panel.']];
        }

        if ($req['method'] === 'GET') {
            $section = $req['query']['section'] ?? null;
            $request = ['locale' => self::locale($req['query']['locale'] ?? null), 'section' => is_string($section) && $section !== '' ? $section : null];

            return self::drawn($panel, $request, ['ok' => true]);
        }

        if ($req['method'] !== 'POST') {
            return ['status' => 405, 'body' => ['ok' => false, 'error' => 'GET or POST.']];
        }

        $data = json_decode($req['body'], true);
        $data = is_array($data) ? $data : [];
        $request = ['locale' => self::locale($data['locale'] ?? null), 'section' => null];
        $name = is_string($data['action'] ?? null) ? $data['action'] : '';
        $args = is_array($data['args'] ?? null) ? $data['args'] : [];

        if ($panel->action === null || $name === '') {
            return self::drawn($panel, $request, ['ok' => false, 'error' => "Panel '{$panel->id}' has no action '{$name}'."]);
        }

        try {
            $error = ($panel->action)($name, $args, $request);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return self::drawn($panel, $request, $error === null ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    }

    /**
     * The panel drawn, after whatever `$outcome` says happened. A `render` that throws is an error
     * with no blocks — the page keeps what it had and says why.
     *
     * @param array{locale: string, section: ?string} $request
     * @param array{ok: bool, error?: string} $outcome
     * @return array{status: int, body: mixed}
     */
    private static function drawn(WebPanel $panel, array $request, array $outcome): array
    {
        try {
            $blocks = ($panel->render)($request);
        } catch (Throwable $e) {
            return ['status' => 200, 'body' => ['ok' => false, 'error' => $outcome['error'] ?? $e->getMessage()]];
        }

        return ['status' => 200, 'body' => [...$outcome, 'blocks' => array_values($blocks)]];
    }

    private static function locale(mixed $locale): string
    {
        return is_string($locale) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) === 1 ? $locale : 'en';
    }
}
