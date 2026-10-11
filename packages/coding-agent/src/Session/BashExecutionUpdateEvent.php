<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * More output from a `!` command — upstream's `bash_execution_update`.
 *
 * `delta` is what arrived, not everything so far: a host appends it, as it appends a `text_delta`.
 * `id` is the one the command was started with — RPC's `bash` passes its own command id, so a host
 * running two of them in turn can tell whose output this is — and null when nobody gave one.
 */
final readonly class BashExecutionUpdateEvent implements AgentEvent
{
    public function __construct(
        public ?string $id,
        public string $delta,
    ) {
    }
}
