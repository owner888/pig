<?php

declare(strict_types=1);

/**
 * Copies pig's user documentation into the package: the site's English pages,
 * `../smart-book/app/Views/pig/docs/en/*.md`, to `docs/*.md`.
 *
 * The site's repository is where the documentation is written, and pigagent.dev/docs serves it from
 * there. The package carries a copy because the model answers a question about pig by reading
 * these pages — the system prompt's `docs` section names them (`SystemPrompt::docsSection()`) —
 * and an installed pig has no site beside it. Run before every release, as AGENTS.md says;
 * `DocsTest` fails while the two disagree on a machine that has both.
 *
 *     php scripts/sync-docs.php [<directory of the site's English pages>]
 *
 * A page the site no longer has is removed from `docs/`; `docs/dev/` is the developer's own and
 * is never touched.
 */

$source = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/smart-book/app/Views/pig/docs/en', '/');
$target = dirname(__DIR__) . '/docs';

if (!is_file($source . '/extensions.md')) {
    fwrite(STDERR, "no documentation in {$source}\n");

    exit(1);
}

$pages = array_map(basename(...), glob($source . '/*.md') ?: []);
$written = 0;

foreach ($pages as $page) {
    $content = file_get_contents("{$source}/{$page}");

    if ($content === false) {
        fwrite(STDERR, "could not read {$source}/{$page}\n");

        exit(1);
    }

    if (!is_file("{$target}/{$page}") || file_get_contents("{$target}/{$page}") !== $content) {
        if (file_put_contents("{$target}/{$page}", $content) === false) {
            fwrite(STDERR, "could not write {$target}/{$page}\n");

            exit(1);
        }

        $written++;
    }
}

$removed = 0;

foreach (array_diff(array_map(basename(...), glob($target . '/*.md') ?: []), $pages) as $gone) {
    unlink("{$target}/{$gone}");
    $removed++;
}

echo count($pages) . " pages, {$written} written, {$removed} removed: {$target}\n";
