<?php

declare(strict_types=1);

namespace Pig\Codemode;

use RuntimeException;

/** The script's input was not a script: empty, or an `@options` line that does not parse. */
final class SourceError extends RuntimeException
{
}
