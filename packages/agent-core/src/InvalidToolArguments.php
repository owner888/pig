<?php

declare(strict_types=1);

namespace Pig\Agent;

use RuntimeException;

/** The model called a tool with arguments its schema does not allow. */
final class InvalidToolArguments extends RuntimeException
{
}
