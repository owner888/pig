<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Timestamp;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\CacheWarmingDecisionEvent;
use Throwable;

/**
 * Keeps one prompt cache entry alive by re-sending its request with a one-token output cap before
 * the entry expires — upstream's `core/cache-warmer.ts`, line for line where PHP allows.
 *
 * A provider's prompt cache lives for its `promptCache` TTL (Anthropic: five minutes). A tool that
 * runs past that — a six-minute build — means the next request writes the whole prompt to the cache
 * again at full price, where a read would have cost a tenth. So each real request starts a run: at
 * 90% of the TTL the same request goes out again with `maxTokens: 1`, when the expected saving is
 * worth it, and each refresh is written to the session file as a `usage` entry so it is on the bill.
 *
 * Three modes (`cacheWarming`, global setting only): `off`, `streaming` (the default — only while
 * the run that sent the request is still going) and `idle` (between runs too, at a 15% chance of
 * another request, for at most 30 minutes). `start()` replaces any previous run; a refresh never
 * extends the safety windows.
 *
 * One thing PHP changes: upstream `unref()`s its timer, so Node exits with one pending. pig's loop
 * has no unref and a pending timer keeps `Loop::isIdle()` false, so the warmer is opt-in —
 * `AgentSession::enableCacheWarming()`, which the terminal and RPC modes call and `-p` does not,
 * since a print run ends when its answer does and there is no next request to save.
 */
final class CacheWarmer
{
    /** Streaming warming never continues past this long after the real request that started it. */
    private const int MAX_WARMING_AGE_MS = 60 * 60_000;

    /** Idle warming uses a shorter horizon because continuation estimates become less reliable with age. */
    private const int MAX_IDLE_WARMING_AGE_MS = 30 * 60_000;

    /** A refresh is sent only when it is expected to save at least this many dollars. */
    public const float MINIMUM_EXPECTED_SAVINGS = 0.05;

    /**
     * Chance that a real request arrives before the cache entry expires while the agent sits idle —
     * upstream's constant, "measured from our own usage; per-session estimates were not better".
     */
    private const float IDLE_CONTINUATION_PROBABILITY = 0.15;

    /**
     * @var array{
     *     model: Model, context: TranscriptContext, options: SimpleStreamOptions,
     *     isCurrent: Closure(): bool, ttlMs: int, delayMs: int, refreshDeadlineAt: int,
     *     startedAt: int, controller: AbortController, phase: string, nextWarmAt: int,
     *     extensionOverride: bool, timer: string|null,
     * }|null
     */
    private ?array $run = null;

    private CacheWarmingStatus $inactive;

    /**
     * @param Closure(Model, TranscriptContext, SimpleStreamOptions): mixed $stream the provider call,
     *        not the agent's stream function — that one would start a run of its own for the refresh
     * @param Closure(): string $mode `off`, `streaming` or `idle`, read every time
     * @param Closure(CacheWarmingDecisionEvent): string $decide a hook's say over each refresh
     * @param Closure(): int $lastPromptTokens the prompt size of the latest real request, as the
     *        provider reported it
     * @param Closure(string, string, string, Usage, ?string): ?UsageEntry $record writes the refresh down
     *        and announces it
     * @param (Closure(): int)|null $now milliseconds since the epoch, for a test
     */
    public function __construct(
        private readonly Closure $stream,
        private readonly Closure $mode,
        private readonly Closure $decide,
        private readonly Closure $lastPromptTokens,
        private readonly Closure $record,
        private readonly ?Closure $now = null,
    ) {
        $this->inactive = new CacheWarmingStatus('inactive', 'waiting for first request');
    }

    /** Refresh at 90% of the TTL while preserving at least ten seconds of margin. */
    public static function delayMs(int $ttlMs): ?int
    {
        if ($ttlMs <= 10_000) {
            return null;
        }

        return max(1, (int) floor(min($ttlMs * 0.9, $ttlMs - 10_000)));
    }

    /**
     * Lifetime of the prompt cache entry a request writes, from the model's `promptCache` tier for
     * the retention the request used. Null when the model has no lifetime for that tier or caching
     * is off.
     */
    public static function ttlMs(Model $model, SimpleStreamOptions $options): ?int
    {
        $retention = $options->resolvedCacheRetention();

        if ($retention === 'none') {
            return null;
        }

        $seconds = $model->promptCache[$retention] ?? null;

        return $seconds === null ? null : (int) ($seconds * 1000);
    }

    /**
     * Whether replaying the request with a one-token output cap leaves its cache entry untouched.
     * Anthropic's budget-based thinking derives `budget_tokens` from `max_tokens`; the replay would
     * get a different budget, which Anthropic keys the message cache on, and the model could still
     * think for thousands of tokens.
     */
    public static function isReplayable(Model $model, SimpleStreamOptions $options): bool
    {
        if ($options->reasoning === null || $model->api !== Api::AnthropicMessages) {
            return true;
        }

        return $model->compat instanceof AnthropicCompat && $model->compat->forceAdaptiveThinking === true;
    }

    public function status(): CacheWarmingStatus
    {
        if (($this->mode)() === 'off') {
            return new CacheWarmingStatus('inactive', 'cache warming disabled');
        }

        $run = $this->run;

        if ($run === null) {
            return $this->inactive;
        }

        if (!($run['isCurrent'])()) {
            return new CacheWarmingStatus('inactive', 'conversation context changed');
        }

        $decision = $this->evaluate($run);
        $refreshing = $run['timer'] === null;

        if (!$decision->economicsAvailable && !$refreshing) {
            return new CacheWarmingStatus('inactive', 'cache economics unavailable');
        }

        return new CacheWarmingStatus(
            $refreshing ? 'refreshing' : 'scheduled',
            null,
            $run['nextWarmAt'],
            $decision,
            $run['extensionOverride'],
        );
    }

    /**
     * Keep the prompt cache entry the request writes warm while `$isCurrent` holds.
     *
     * @param Closure(): bool $isCurrent
     */
    public function start(Model $model, TranscriptContext $context, SimpleStreamOptions $options, Closure $isCurrent): void
    {
        $this->clearRun();

        if (($this->mode)() === 'off') {
            $this->stop('cache warming disabled');

            return;
        }

        if (!self::isReplayable($model, $options)) {
            $this->stop('request cannot be replayed safely');

            return;
        }

        $ttlMs = self::ttlMs($model, $options);

        if ($ttlMs === null) {
            $this->stop($options->cacheRetention === 'none' ? 'request disabled prompt caching' : 'cache lifetime unavailable');

            return;
        }

        $delayMs = self::delayMs($ttlMs);

        if ($delayMs === null) {
            $this->stop('cache lifetime unavailable');

            return;
        }

        $this->run = [
            'model' => $model,
            'context' => $context,
            'options' => $options,
            'isCurrent' => $isCurrent,
            'ttlMs' => $ttlMs,
            'delayMs' => $delayMs,
            'refreshDeadlineAt' => 0,
            'startedAt' => $this->now(),
            'controller' => new AbortController(),
            'phase' => 'streaming',
            'nextWarmAt' => 0,
            'extensionOverride' => false,
            'timer' => null,
        ];
        $this->schedule();
    }

    public function onAgentSettled(): void
    {
        if ($this->run === null) {
            return;
        }

        if (($this->mode)() === 'streaming') {
            $this->stop('agent run settled');

            return;
        }

        $this->run['phase'] = 'idle';
        $deadline = $this->run['startedAt'] + self::MAX_IDLE_WARMING_AGE_MS;

        if ($this->run['nextWarmAt'] > $deadline || $this->now() >= $deadline) {
            $this->stop('30-minute idle safety limit reached');
        }
    }

    /** Reconcile an active run after the warming mode changes. */
    public function onModeChanged(): void
    {
        if ($this->run === null) {
            return;
        }

        $reason = $this->modeStopReason();

        if ($reason !== null) {
            $this->stop($reason);
        }
    }

    public function cancel(): void
    {
        $this->stop('inactive');
    }

    private function now(): int
    {
        return $this->now !== null ? ($this->now)() : Timestamp::nowMs();
    }

    private function clearRun(): void
    {
        $run = $this->run;

        if ($run === null) {
            return;
        }

        $this->run = null;

        if ($run['timer'] !== null) {
            Loop::get()->cancel($run['timer']);
        }

        $run['controller']->abort();
    }

    private function stop(string $reason, ?CacheWarmingDecision $decision = null, bool $extensionOverride = false): void
    {
        $this->clearRun();
        $this->inactive = new CacheWarmingStatus('inactive', $reason, null, $decision, $extensionOverride);
    }

    private function schedule(): void
    {
        $run = &$this->run;
        $run['extensionOverride'] = false;
        $run['nextWarmAt'] = $this->now() + $run['delayMs'];
        // A timer can run late after sleep or a blocked loop. Keep half of the planned pre-expiry
        // margin for that delay and request dispatch; a late refresh is likely a full-price cache
        // write, not a cache warm.
        $run['refreshDeadlineAt'] = $run['nextWarmAt'] + intdiv($run['ttlMs'] - $run['delayMs'], 2);
        $deadline = $run['startedAt'] + ($run['phase'] === 'idle' ? self::MAX_IDLE_WARMING_AGE_MS : self::MAX_WARMING_AGE_MS);

        if ($run['nextWarmAt'] > $deadline || $this->now() >= $deadline) {
            $phase = $run['phase'];
            unset($run);
            $this->stop($phase === 'idle' ? '30-minute idle safety limit reached' : 'one-hour safety limit reached');

            return;
        }

        $controller = $run['controller'];
        $run['timer'] = Loop::get()->delay(
            max(0, $run['nextWarmAt'] - $this->now()) / 1000,
            // The refresh awaits a provider, so it needs a fiber of its own.
            fn () => Async::spawn(fn () => $this->refresh($controller)),
        );
        unset($run);
    }

    /** The run this refresh was scheduled for, identified by its controller, or null when it is gone. */
    private function refresh(AbortController $controller): void
    {
        if ($this->run === null || $this->run['controller'] !== $controller) {
            return;
        }

        $this->run['timer'] = null;

        if (!$this->validate($controller) || $this->refreshDeadlineMissed()) {
            return;
        }

        $decision = $this->evaluate($this->run);
        $action = ($this->decide)(new CacheWarmingDecisionEvent(
            $decision->warmCost,
            $decision->missCost,
            $decision->continuationProbability,
            $decision->action,
        ));

        if (!$this->validate($controller) || $this->refreshDeadlineMissed()) {
            return;
        }

        $extensionOverride = $action !== $decision->action;

        if ($action === 'stop') {
            $this->stop(
                $extensionOverride ? 'stopped by extension' : ($decision->economicsAvailable ? 'expected savings below threshold' : 'cache economics unavailable'),
                $decision,
                $extensionOverride,
            );

            return;
        }

        $this->run['extensionOverride'] = $extensionOverride;
        $run = $this->run;

        try {
            $response = ($this->stream)($run['model'], $run['context'], new SimpleStreamOptions(...[
                ...$run['options']->baseArgs(),
                'reasoning' => $run['options']->reasoning,
                'toolChoice' => $run['options']->toolChoice,
                'maxTokens' => 1,
                'maxRetries' => 0,
                'signal' => $controller->signal,
            ]));

            foreach ($response as $ignored) {
                // Nothing to show: the answer is one token nobody reads.
            }

            $message = $response->result()->await();

            if (!$this->validate($controller)) {
                return;
            }

            if ($message instanceof AssistantMessage && $message->stopReason !== StopReason::Error && $message->stopReason !== StopReason::Aborted) {
                // The session announces it (`CacheWarmedEvent`), for the terminal's "Cache warmed"
                // line behind `showCacheMissNotices`.
                ($this->record)(
                    'cache_warm',
                    $message->provider,
                    $message->responseModel ?? $message->model,
                    $message->usage,
                    $extensionOverride ? 'extension override' : null,
                );
            }
        } catch (Throwable) {
            // Upstream's rule, and the one swallowed throw in the session: cache warming is best
            // effort and must not affect the active agent run. A refused refresh costs nothing.
        }

        if ($this->run !== null && $this->run['controller'] === $controller) {
            $this->schedule();
        }
    }

    private function refreshDeadlineMissed(): bool
    {
        if ($this->now() <= $this->run['refreshDeadlineAt']) {
            return false;
        }

        $this->stop('cache refresh deadline missed');

        return true;
    }

    private function validate(AbortController $controller): bool
    {
        if ($this->run === null || $this->run['controller'] !== $controller) {
            return false;
        }

        $reason = $this->modeStopReason() ?? (!($this->run['isCurrent'])() ? 'conversation context changed' : null);

        if ($reason === null) {
            return true;
        }

        $this->stop($reason);

        return false;
    }

    private function modeStopReason(): ?string
    {
        $mode = ($this->mode)();

        if ($mode === 'off') {
            return 'cache warming disabled';
        }

        if ($mode === 'streaming' && $this->run !== null && $this->run['phase'] === 'idle') {
            return 'agent run settled';
        }

        return null;
    }

    /** @param array{model: Model, phase: string} $run */
    private function evaluate(array $run): CacheWarmingDecision
    {
        $model = $run['model'];
        $promptTokens = ($this->lastPromptTokens)();
        $price = static fn (Usage $usage): float => $usage->withCost($model)->cost->total;
        $cacheHitCost = $price(new Usage(cacheRead: $promptTokens));
        $cacheMissCost = $price($model->pricing->cacheWrite > 0 ? new Usage(cacheWrite: $promptTokens) : new Usage(input: $promptTokens));
        $warmCost = $price(new Usage(output: 1, cacheRead: $promptTokens));
        $missCost = max(0.0, $cacheMissCost - $cacheHitCost);
        $continuationProbability = $run['phase'] === 'idle' ? self::IDLE_CONTINUATION_PROBABILITY : 1.0;
        $economicsAvailable = $promptTokens > 0 && ($cacheHitCost > 0 || $cacheMissCost > 0);
        $expectedSavings = $continuationProbability * $missCost - $warmCost;

        return new CacheWarmingDecision(
            $run['phase'],
            $warmCost,
            $missCost,
            $continuationProbability,
            $expectedSavings,
            $economicsAvailable,
            $expectedSavings >= self::MINIMUM_EXPECTED_SAVINGS ? 'warm' : 'stop',
        );
    }

    /** Upstream's `formatCacheWarmingUsage()`: `Cache warmed (note): $0.0153`, trailing zeros past three places dropped. */
    public static function formatUsage(UsageEntry $entry): string
    {
        $note = $entry->note !== null && $entry->note !== '' ? " ({$entry->note})" : '';
        $cost = (string) preg_replace('/(\.\d{3}\d*?)0+$/', '$1', sprintf('%.6f', $entry->usage->cost->total));

        return "Cache warmed{$note}: \${$cost}";
    }

    /** One line for `/session` — upstream's `formatCacheWarmingStatus()`. */
    public static function formatStatus(CacheWarmingStatus $status, ?int $now = null): string
    {
        $now ??= Timestamp::nowMs();
        $decision = $status->decision;

        // A decision is attached once pig (or a hook) acted on it; "inactive" without one never
        // got that far.
        if ($decision === null || ($status->state === 'inactive' && !$decision->economicsAvailable && !$status->extensionOverride)) {
            return 'Inactive (' . ($status->reason ?? 'unknown reason') . ')';
        }

        $details = $status->extensionOverride
            ? 'extension override, ' . self::economics($decision)
            : self::economics($decision) . ' -> ' . $decision->action;

        return match ($status->state) {
            'inactive' => "Stopped ({$details})",
            'refreshing' => "Warming cache ({$details})",
            default => self::decisionTime($status->nextWarmAt, $now) . " ({$details})",
        };
    }

    private static function economics(CacheWarmingDecision $decision): string
    {
        if (!$decision->economicsAvailable) {
            return 'cache economics unavailable';
        }

        $probability = (int) round($decision->continuationProbability * 100);
        $probabilityText = $decision->phase === 'streaming'
            ? "{$probability}% continuation probability while agent is running"
            : "{$probability}% continuation probability";
        $comparison = $decision->action === 'warm' ? '>=' : '<';

        return sprintf('%s, expected savings %s %s $%.3f', $probabilityText, self::dollars($decision->expectedSavings), $comparison, self::MINIMUM_EXPECTED_SAVINGS);
    }

    private static function dollars(float $value): string
    {
        return $value < 0 ? sprintf('-$%.3f', abs($value)) : sprintf('$%.3f', $value);
    }

    private static function decisionTime(?int $nextWarmAt, int $now): string
    {
        if ($nextWarmAt === null || $nextWarmAt <= $now) {
            return 'Decision now';
        }

        $remaining = (int) ceil(($nextWarmAt - $now) / 1000);
        $hours = intdiv($remaining, 3600);
        $remaining %= 3600;
        $minutes = intdiv($remaining, 60);
        $seconds = $remaining % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        if ($seconds > 0 || $parts === []) {
            $parts[] = "{$seconds}s";
        }

        return 'Decision in ' . implode(' ', $parts);
    }
}
