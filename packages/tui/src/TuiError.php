<?php

declare(strict_types=1);

namespace Pig\Tui;

use RuntimeException;

/** Something went wrong drawing or measuring. */
final class TuiError extends RuntimeException
{
}
