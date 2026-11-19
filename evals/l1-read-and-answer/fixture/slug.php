<?php

declare(strict_types=1);

/** A URL path segment for a title. */
function slugify(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug);

    return trim($slug, '-');
}
