<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What came of asking to leave this conversation for another one.
 *
 * `switched` is false only when a hook said no — which is an answer rather than a failure,
 * and the reason this is a returned object instead of an exception: the terminal says "a
 * hook stopped that" and stays put, and RPC answers `{"cancelled": true}`. Everything that
 * can genuinely go wrong (no such file, a new one that cannot be created) still throws.
 *
 * `previous` is the file being left, which is what both the `session_switch` hook and the
 * custom tools are told, and it is null for a session that was never being written down.
 * `messages` is how many came back, for a caller that says so on screen.
 */
final readonly class SessionSwitch
{
    public function __construct(
        public bool $switched,
        public ?string $previous = null,
        public int $messages = 0,
    ) {
    }
}
