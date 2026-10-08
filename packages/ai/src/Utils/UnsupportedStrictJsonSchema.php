<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `UnsupportedStrictJsonSchemaError`: a schema with no strict form, which a `prefer`
 * tool survives and a `require` tool does not.
 *
 * @internal
 */
final class UnsupportedStrictJsonSchema extends \RuntimeException
{
}
