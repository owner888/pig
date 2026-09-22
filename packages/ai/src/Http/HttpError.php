<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use RuntimeException;

/** A response that could not be framed, parsed, or completed. */
final class HttpError extends RuntimeException
{
}
