<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * "No argument was passed" sentinel for EventStream::end().
 *
 * Upstream checks `result !== undefined`; PHP has no undefined, and null is a legal
 * result, so the default value is an instance of this instead.
 */
final class NoResult
{
}
