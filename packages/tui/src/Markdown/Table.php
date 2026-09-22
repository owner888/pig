<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/** A GFM table: one header row and some body rows. */
final readonly class Table implements BlockToken
{
    /**
     * @param list<TableCell>       $header
     * @param list<list<TableCell>> $rows
     * @param string                $raw    the source, for when the terminal is too narrow to draw it
     */
    public function __construct(public array $header, public array $rows, public string $raw = '')
    {
    }
}
