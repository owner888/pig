<?php

declare(strict_types=1);

/**
 * Ask Antigravity what it sells, and print it as rows for `Ai\Models::ANTIGRAVITY_MODELS`.
 *
 *   php scripts/fetch-antigravity-models.php [--raw] [--endpoint <url>]
 *
 * **This replaces `scripts/probe-antigravity.php`, which existed because this endpoint was
 * thought not to exist.** It does: `POST /v1internal:fetchAvailableModels` with the Cloud project
 * in the body, the same token a turn uses. Probing ids one at a time was the right method for a
 * deployment with no catalogue and the wrong answer for one that has it.
 *
 * `ANTIGRAVITY_MODELS` is hand-written because models.dev does not carry a subscription
 * deployment's catalogue and upstream deleted its Antigravity support altogether — see the
 * CLAUDE.md entry. Hand-written does not have to mean invented, and this is where the rows come
 * from.
 *
 * **Two constants here are the developer's working values rather than pig's**, because pig's
 * demonstrably drifted: the endpoint has no `sandbox` in it, and the User-Agent is the
 * Antigravity CLI's current one. Both are what `Providers\Antigravity` sends now, and the
 * endpoint stays overridable with `--endpoint` so a host can be compared rather than argued about.
 *
 * `--raw` prints what came back instead of the rows, which is the only way to see a field this
 * does not read yet.
 *
 * `--from <file>` reads a saved response instead of asking, for the same reason
 * `generate-models.php` has one: this endpoint needs a signed-in account and is unreachable from
 * some of the machines this repository is worked on, so a run from somebody else's saved payload
 * is the only way the rendering is checkable at all.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Proxy;
use Pig\Ai\Http\Request;
use Pig\Ai\Models;
use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Settings;
use Pig\Tui\Style;

/** Where the catalogue answers. Same host as `Providers\Antigravity::ENDPOINT`. */
const ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';

/** The CLI's own, which is what this deployment checks for. */
const USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

$options = getopt('', ['raw', 'endpoint:', 'from:']) ?: [];
$endpoint = isset($options['endpoint']) && is_string($options['endpoint'])
    ? rtrim($options['endpoint'], '/')
    : ENDPOINT;

$saved = isset($options['from']) && is_string($options['from']) ? $options['from'] : null;

if ($saved !== null) {
    if (!is_file($saved)) {
        fwrite(STDERR, Style::red("No such file: {$saved}\n"));

        exit(1);
    }

    $status = 200;
    $raw = (string) file_get_contents($saved);
}

$settings = Settings::load(getcwd() ?: '.');

$proxy = Proxy::fromEnvironment(getenv())?->bypassing($settings->proxyBypass());

if ($proxy === null && $settings->proxyUrl() !== null) {
    $proxy = Proxy::parse($settings->proxyUrl(), $settings->proxyBypass());
}

if ($saved === null) {
    HttpClient::useProxy($proxy);
}

$key = $saved !== null ? null : Auth::discover(settings: $settings)->apiKey(Models::ANTIGRAVITY);
$credentials = $key === null ? null : json_decode($key, true);
$token = is_array($credentials) ? ($credentials['token'] ?? null) : null;
$project = is_array($credentials) ? ($credentials['projectId'] ?? null) : null;

if ($saved === null && (!is_string($token) || $token === '' || !is_string($project) || $project === '')) {
    fwrite(STDERR, Style::red(
        "No Antigravity token and project. `bin/pig-ai login` stores both; an ordinary Gemini\n"
        . "API key is not enough for this endpoint.\n",
    ));

    exit(1);
}

$body = $saved !== null ? [200, $raw] : Async::run(static function () use ($endpoint, $token, $project): array {
    $response = (new HttpClient())->send(new Request(
        'POST',
        $endpoint . '/v1internal:fetchAvailableModels',
        [
            'authorization' => "Bearer {$token}",
            'content-type' => 'application/json',
            'user-agent' => USER_AGENT,
        ],
        json_encode(['project' => $project], JSON_UNESCAPED_SLASHES) ?: '{}',
    ));

    return [$response->status, $response->body->all()];
});

[$status, $raw] = $body;

if ($status !== 200) {
    fwrite(STDERR, Style::red("fetchAvailableModels answered {$status}:\n") . mb_substr($raw, 0, 800) . "\n");

    exit(1);
}

if (isset($options['raw'])) {
    $pretty = json_decode($raw, true);

    echo $pretty === null ? $raw : json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

    exit(0);
}

$decoded = json_decode($raw, true);

if (!is_array($decoded)) {
    fwrite(STDERR, Style::red("That is not JSON. Run again with --raw to see it.\n"));

    exit(1);
}

/**
 * The three tables, wherever they are sitting.
 *
 * Deliberately forgiving about the shape, because there are already three of them in the wild:
 * the endpoint's own reply, the `antigravity-model-catalog.json` cache (`models` + `routing`, no
 * enums), and the `models-store.json` blob, where the enums are a *sibling* of the catalogue
 * rather than inside it. Guessing wrong prints an empty table; looking in all three places costs
 * six lines.
 */
$find = static function (array $tree, string $key, int $depth = 6) use (&$find): ?array {
    if (is_array($tree[$key] ?? null)) {
        return $tree[$key];
    }

    if ($depth <= 0) {
        return null;
    }

    // Breadth first, so a shallower match wins over a deeper one of the same name. Counting
    // levels by hand is what this replaced: the store nests one deeper than the cache and the
    // first version quietly found nothing.
    foreach ($tree as $value) {
        if (is_array($value) && ($found = $find($value, $key, $depth - 1)) !== null) {
            return $found;
        }
    }

    return null;
};

$models = $find($decoded, 'models') ?? (array_is_list($decoded) ? $decoded : null);
$routing = $find($decoded, 'routing');
$enums = $find($decoded, 'modelEnums');

if (!is_array($models) || $models === []) {
    fwrite(STDERR, Style::yellow(
        "Nothing that looks like a model list came back. Run again with --raw to see the shape;\n"
        . "this script reads `models`, and the field may have been renamed.\n",
    ));

    exit(1);
}

echo '// ', count($models), " models, from {$endpoint}/v1internal:fetchAvailableModels on ", date('Y-m-d'), "\n";
echo "// [name, context window, max output, reasoning, accepts images]\n";

foreach ($models as $model) {
    if (!is_array($model)) {
        continue;
    }

    $id = $model['id'] ?? $model['name'] ?? null;

    if (!is_string($id) || $id === '') {
        continue;
    }

    $input = $model['input'] ?? ['text'];
    $images = is_array($input) && in_array('image', $input, true);

    printf(
        "    '%s' => ['%s', %s, %s, %s, %s],\n",
        $id,
        // A single quote in a model's display name would end the string it is being printed
        // into. Nothing in the catalogue has one today, and a generator that breaks the file it
        // writes the first time one appears is not worth the two characters saved.
        str_replace(["\\", "'"], ["\\\\", "\\'"], (string) ($model['name'] ?? $id)),
        number_format((int) ($model['contextWindow'] ?? 0), 0, '.', '_'),
        number_format((int) ($model['maxTokens'] ?? 0), 0, '.', '_'),
        ($model['reasoning'] ?? false) ? 'true' : 'false',
        $images ? 'true' : 'false',
    );
}

// ---- the two tables `Antigravity\Routing` carries between its generated markers -------------

if ($routing === null) {
    echo "\n", Style::yellow(
        "No `routing` in this payload, so the two tables in `Ai\\Antigravity\\Routing` cannot be\n"
        . "regenerated from it. The model rows above are still good.\n",
    );

    exit(0);
}

/** Only what routing can reach: the catalogue also enumerates the IDE's own models. */
$reachable = [];

foreach ($routing as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    foreach ([$entry['off'] ?? null, $entry['defaultRequestId'] ?? null, ...array_values($entry['routing'] ?? [])] as $target) {
        if (is_string($target) && $target !== '') {
            $reachable[$target] = true;
        }
    }
}

$quote = static fn (string $text): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $text) . "'";

echo "\n// ---- Routing::ROUTING ", str_repeat('-', 60), "\n";
ksort($routing);

foreach ($routing as $logical => $entry) {
    if (!is_array($entry) || !is_string($entry['defaultRequestId'] ?? null)) {
        continue;
    }

    $parts = ["'default' => " . $quote($entry['defaultRequestId'])];

    // Absent rather than null for a model that cannot be turned off — `Routing` reads the
    // difference, so this has to preserve it.
    if (is_string($entry['off'] ?? null)) {
        $parts[] = "'off' => " . $quote($entry['off']);
    }

    $levels = [];
    $byLevel = is_array($entry['routing'] ?? null) ? $entry['routing'] : [];
    ksort($byLevel);

    foreach ($byLevel as $level => $target) {
        if (is_string($target)) {
            $levels[] = $quote((string) $level) . ' => ' . $quote($target);
        }
    }

    $parts[] = "'levels' => [" . implode(', ', $levels) . ']';

    echo '        ', $quote((string) $logical), ' => [', implode(', ', $parts), "],\n";
}

if ($enums === null) {
    echo "\n", Style::yellow(
        "No `modelEnums` in this payload — in a `models-store.json` they sit beside the catalogue\n"
        . "rather than in it, so check there. Without them `Routing` cannot name a model on the\n"
        . "wire, and the table it already carries is the one to keep.\n",
    );

    exit(0);
}

echo "\n// ---- Routing::ENUMS ", str_repeat('-', 62), "\n";
$kept = array_intersect_key($enums, $reachable);
ksort($kept);

foreach ($kept as $runtime => $enum) {
    if (is_string($enum)) {
        echo '        ', $quote((string) $runtime), ' => ', $quote($enum), ",\n";
    }
}

$orphans = array_diff_key($reachable, $kept);

if ($orphans !== []) {
    fwrite(STDERR, "\n" . Style::red(
        'Routing targets with no enum: ' . implode(', ', array_keys($orphans)) . "\n"
        . "`Routing::resolve()` refuses these rather than guessing, so a table pasted in with them\n"
        . "missing turns those levels into an error. Fetch both tables from the same payload.\n",
    ));

    exit(1);
}

echo "\n", Style::yellow(
    "Rows, not a rewrite. The model rows go in `Ai\\Models::ANTIGRAVITY_MODELS`, which has no\n"
    . "generated markers, and the two tables above go between the markers in\n"
    . "`Ai\\Antigravity\\Routing`. Read the diff: a routing change moves which model a thinking\n"
    . "level actually asks for, and every request still succeeds afterwards.\n",
);
