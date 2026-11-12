<?php

declare(strict_types=1);

/**
 * Custom Footer Extension - demonstrates `$ctx->ui->setFooter()`.
 *
 * Ported from upstream's `examples/extensions/custom-footer.ts`.
 *
 * `$footerData` (the built-in `FooterComponent`) exposes:
 * - `getGitBranch()`: current git branch, or null
 * - `getExtensionStatuses()`: status texts from `$ctx->ui->setStatus()`
 * - `getAvailableProviderCount()`: count of providers with available models
 * - `onBranchChange($cb)`: unsubscribe callback
 * - `render($width)`: the built-in 2-line footer
 */

use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Theme\Theme;
use Pig\Tui\Component;
use Pig\Tui\TUI;
use Pig\Tui\Width;

return static function (ExtensionApi $pig): void {
    $enabled = false;

    $pig->registerCommand(
        'footer',
        static function (string $args, ExtensionContext $ctx) use (&$enabled): void {
            unset($args);
            $enabled = !$enabled;

            if ($enabled) {
                $ctx->ui->setFooter(static function (TUI $tui, Theme $theme, FooterComponent $footerData) use ($ctx): Component {
                    unset($tui);

                    return new class ($theme, $footerData, $ctx) implements Component {
                        public function __construct(
                            private readonly Theme $theme,
                            private readonly FooterComponent $footerData,
                            private readonly ExtensionContext $ctx,
                        ) {
                        }

                        #[\Override]
                        public function invalidate(): void
                        {
                            $this->footerData->invalidate();
                        }

                        #[\Override]
                        public function render(int $width): array
                        {
                            $usage = $this->ctx->getContextUsage();
                            $pct = max(0.0, min(100.0, (float) ($usage->percent ?? 0.0)));
                            $filled = (int) round($pct / 10);
                            $bar = str_repeat('█', $filled) . str_repeat('░', 10 - $filled) . ' ' . (int) round($pct) . '%';

                            $branch = $this->footerData->getGitBranch();
                            $branchStr = $branch !== null ? " ({$branch})" : '';
                            $left = $this->theme->fg('dim', $bar);
                            $right = $this->theme->fg('dim', ($this->ctx->model->id ?? 'no-model') . $branchStr);

                            $pad = str_repeat(' ', max(1, $width - Width::visible($left) - Width::visible($right)));

                            return [Width::truncate($left . $pad . $right, $width)];
                        }
                    };
                });
                $ctx->ui->notify('Custom footer enabled', 'info');
            } else {
                $ctx->ui->setFooter(null);
                $ctx->ui->notify('Default footer restored', 'info');
            }
        },
        description: 'Toggle custom footer',
    );
};
