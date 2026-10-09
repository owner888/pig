<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

use RuntimeException;

/** Something about a package that stops the command: an unsupported source, a failed clone, a path outside the install root. */
final class PackageError extends RuntimeException
{
}
