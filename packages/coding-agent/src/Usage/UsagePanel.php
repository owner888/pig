<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\WebPanel;
use Throwable;

/**
 * `pig web`'s Usage panel: every registered usage source, one lazy section each, so a slow
 * provider does not hold up the others. pig's own, registered by core the first time an extension
 * calls `registerUsage()` — a page with no source has no empty panel. `/usage` typed in the page
 * opens it, as `/usage` in the terminal lists the same thing.
 */
final class UsagePanel
{
    public const string ID = 'usage';

    public const array TITLE = ['en' => 'Usage', 'zh-CN' => '用量'];

    public static function webPanel(): WebPanel
    {
        return new WebPanel(
            self::ID,
            self::TITLE,
            static fn (array $request): array => self::render($request['section'], $request['locale']),
            icon: '📊',
            commands: [self::ID],
            placement: 'header',
        );
    }

    /** @return list<array<string, mixed>> */
    public static function render(?string $section, string $locale, ?int $now = null): array
    {
        $zh = str_starts_with(strtolower($locale), 'zh');

        if ($section === null) {
            $blocks = [];

            foreach (ExtensionApi::usageSources() as $source) {
                $blocks[] = ['type' => 'heading', 'text' => $source->titleFor($locale)];
                $blocks[] = ['type' => 'lazy', 'section' => $source->id, 'placeholder' => $zh ? '正在获取用量…' : 'Fetching usage…'];
            }

            return $blocks;
        }

        $source = ExtensionApi::usageSource($section);

        if ($source === null) {
            return [['type' => 'text', 'text' => $zh ? "没有名为 {$section} 的用量。" : "No usage named {$section}.", 'tone' => 'error']];
        }

        try {
            return UsageView::blocks($source->fetch(), $locale, $now);
        } catch (Throwable $error) {
            return [['type' => 'text', 'text' => $error->getMessage(), 'tone' => 'error']];
        }
    }
}
