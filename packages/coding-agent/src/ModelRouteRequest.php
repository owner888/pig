<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;
use Pig\Async\AbortSignal;

/** What a virtual model's router is asked — upstream's `ModelRouteRequest`. */
final readonly class ModelRouteRequest
{
    /** First request after a message the person wrote: a prompt, a steer, or a follow-up. */
    public const string USER = 'user';

    /** Any other request of the agent loop — after tool results, or an extension's messages. */
    public const string CONTINUATION = 'continuation';

    /** An automatic retry of a failed request, compaction for an overflow included. */
    public const string RETRY = 'retry';

    /** A request outside the agent loop: a compaction or branch summary. */
    public const string DIRECT = 'direct';

    /**
     * @param Model $model the selected virtual model
     * @param ThinkingLevel $thinkingLevel the selected level; its meaning is up to the router
     * @param self::USER|self::CONTINUATION|self::RETRY|self::DIRECT $reason
     * @param RoutedModel|null $previous physical model of the latest successful response in $messages
     * @param RoutedModel|null $failed for `retry`: the failed request, which $messages no longer
     *        contains; its `message` carries the stop reason and error. Null when the router
     *        itself failed
     * @param mixed $state router state last returned on this session branch; null before the
     *        first and for `direct` requests
     * @param list<mixed> $messages the conversation for this request, system messages included
     */
    public function __construct(
        public Model $model,
        public ThinkingLevel $thinkingLevel,
        public string $reason,
        public ?RoutedModel $previous,
        public ?RoutedModel $failed,
        public mixed $state,
        public array $messages,
        public ?AbortSignal $signal,
    ) {
    }
}
