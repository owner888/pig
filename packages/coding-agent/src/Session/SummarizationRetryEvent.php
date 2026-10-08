<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * A summary's request failed in a way worth trying again, or its retry started or ended —
 * upstream's three `summarization_retry_scheduled`, `summarization_retry_attempt_start` and
 * `summarization_retry_finished` session events, as one class with a `$phase`, emitted by the
 * `RetryCallbacks` a compaction or a branch summary hands `Retry::retryAssistantCall()`.
 *
 * Like `RetryStartEvent`, the session's own event, announced to its listeners between runs.
 */
final readonly class SummarizationRetryEvent implements AgentEvent
{
    public const string SCHEDULED = 'scheduled';

    public const string ATTEMPT_START = 'attempt_start';

    public const string FINISHED = 'finished';

    /**
     * @param string      $phase      one of the three constants
     * @param string      $source     `compaction` or `branchSummary`, what is being summarised
     * @param string|null $reason     a compaction's `manual`, `threshold` or `overflow`
     * @param int         $attempt    1-indexed, for `scheduled`
     * @param int         $maxAttempts the retry budget, for `scheduled`
     * @param float       $delaySeconds the backoff before the attempt, for `scheduled`
     * @param string|null $error      the error being retried, for `scheduled`
     */
    public function __construct(
        public string $phase,
        public string $source = 'compaction',
        public ?string $reason = null,
        public int $attempt = 0,
        public int $maxAttempts = 0,
        public float $delaySeconds = 0.0,
        public ?string $error = null,
    ) {
    }
}
