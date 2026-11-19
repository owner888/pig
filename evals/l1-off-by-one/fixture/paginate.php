<?php

declare(strict_types=1);

/**
 * One page of a list.
 *
 * @param list<mixed> $items
 * @param int $page 1-based
 * @return list<mixed>
 */
function paginate(array $items, int $page, int $perPage): array
{
    return array_slice($items, $page * $perPage, $perPage);
}
