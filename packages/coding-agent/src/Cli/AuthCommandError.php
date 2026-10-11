<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

/** Upstream's `AuthCommandError`: a `pig auth` mistake whose message is shown as it is. */
final class AuthCommandError extends \RuntimeException
{
}
