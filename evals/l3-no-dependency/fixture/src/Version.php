<?php

declare(strict_types=1);

namespace Acme\Release;

final class Version
{
    /**
     * Semantic Versioning 2.0.0 precedence: -1, 0 or 1.
     *
     * @throws \InvalidArgumentException when either side is not a valid semantic version
     */
    public static function compare(string $a, string $b): int
    {
        throw new \LogicException('not implemented');
    }
}
