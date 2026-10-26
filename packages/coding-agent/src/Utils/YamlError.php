<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

/** YAML that `Yaml::parse()` refuses, with the line it was found on. */
final class YamlError extends \RuntimeException
{
    public function __construct(string $reason, public readonly int $lineNumber)
    {
        parent::__construct("{$reason} at line {$lineNumber}");
    }
}
