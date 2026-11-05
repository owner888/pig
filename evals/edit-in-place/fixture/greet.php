<?php

declare(strict_types=1);

function greet(string $name): string
{
    return "Helo, {$name}!";
}

echo greet('world'), "\n";
