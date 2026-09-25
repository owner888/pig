<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use RuntimeException;

/**
 * A command the agent refused, or never answered.
 *
 * It carries the command's name because the message alone rarely says which one failed — `not a
 * known model` is the same sentence whether it came from `set_model` or from a `prompt` that had to
 * resolve one. Upstream throws a plain `Error` with the server's string, and a host catching it has
 * to guess.
 *
 * Standard error is appended by the client where it has any, because a timeout's real cause is
 * almost always a line the agent wrote on its way down.
 */
final class RpcError extends RuntimeException
{
    public function __construct(
        public readonly string $command,
        string $message,
    ) {
        parent::__construct("{$command}: {$message}");
    }
}
