<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Usage\UsageReport;
use Pig\CodingAgent\Usage\UsageSource;
use Pig\CodingAgent\Usage\UsageView;
use Throwable;

/**
 * The footer's usage lines: one per source registered with `status: true`, asked at start and
 * every `refreshSeconds` after, each in a fiber of its own so a slow provider holds up nothing.
 * The terminal only — the web page has the Usage panel, and a print or JSON run is over before a
 * refresh would come round.
 */
final class UsageStatus
{
    public const string KEY_PREFIX = 'usage:';

    /** @var array<string, string> timer by source id */
    private array $timers = [];

    /** @param Closure(string, ?string): void $setStatus the footer's `setStatus()`, the line already plain */
    public function __construct(private readonly Closure $setStatus)
    {
    }

    /** Begin again with the sources registered now — at start, and after `/reload`. */
    public function start(): void
    {
        $this->stop();

        foreach (ExtensionApi::usageSources() as $source) {
            if ($source->status) {
                $this->tick($source);
            }
        }
    }

    /** Cancel the refreshes and take the lines off the footer. */
    public function stop(): void
    {
        foreach ($this->timers as $id => $timer) {
            Loop::get()->cancel($timer);
            ($this->setStatus)(self::KEY_PREFIX . $id, null);
        }

        $this->timers = [];
    }

    /** A report fetched elsewhere (`/usage`) is the footer's newest too. */
    public function show(UsageSource $source, UsageReport $report): void
    {
        if ($source->status) {
            ($this->setStatus)(self::KEY_PREFIX . $source->id, UsageView::statusLine($source->titleFor(), $report));
        }
    }

    private function tick(UsageSource $source): void
    {
        Async::spawn(fn () => $this->refresh($source));
        $this->timers[$source->id] = Loop::get()->delay($source->refreshSeconds, fn () => $this->tick($source));
    }

    /** Never throws: it runs in a fiber nobody awaits, and a failure is the line's text. */
    public function refresh(UsageSource $source): void
    {
        try {
            $this->show($source, $source->fetch());
        } catch (Throwable $error) {
            Logger::debug("Usage '{$source->id}' could not be fetched: {$error->getMessage()}");
            ($this->setStatus)(self::KEY_PREFIX . $source->id, UsageView::statusError($source->titleFor(), $error->getMessage()));
        }
    }
}
