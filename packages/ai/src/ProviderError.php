<?php

declare(strict_types=1);

namespace Pig\Ai;

use RuntimeException;

/** A provider refused the request, or sent a stream that does not add up. */
final class ProviderError extends RuntimeException
{
}
