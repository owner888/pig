<?php

declare(strict_types=1);

namespace Pig\Async;

use RuntimeException;

/** Thrown where work was cut short by an AbortSignal rather than by a fault. */
final class AbortError extends RuntimeException
{
}
