<?php

declare(strict_types=1);

/**
 * Ask Antigravity what it sells, and print it as rows for the extension's `PigAntigravity\Models`.
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
use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionLoader;
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

if ($proxy === null && $settings->httpProxy() !== null) {
    $proxy = Proxy::parse($settings->httpProxy(), $settings->proxyBypass());
}

if ($saved === null) {
    HttpClient::useProxy($proxy);
}

// The provider is the extension's now, so load it: `apiKey()` asks the registry how to turn
// the stored credential into a key, and the registry is empty until the extension says. It is
// also where `Grouping` lives, which a saved endpoint reply needs as much as a fresh one.
//
// This checkout's copy and nothing else (`discover: false`): an installed copy under
// `~/.pig/agent/extensions` declares the same classes, and the loader refuses the second one.
Async::run(static function (): void {
    [, $problems] = ExtensionLoader::load(
        getcwd() ?: '.',
        cliPaths: [__DIR__ . '/../extensions/pig-antigravity/index.php'],
        discover: false,
    );

    foreach ($problems as $problem) {
        fwrite(STDERR, Style::yellow("extension {$problem->toText()}\n"));
    }
});

// `apiKey()` renews an expired token over the network, so it has to run inside the coroutine
// too — outside one it works for exactly as long as the stored token is fresh, which is how
// this script passed every run until the day it did not.
$body = $saved !== null ? [200, $raw] : Async::run(static function () use ($endpoint, $settings): array {
    $key = Auth::discover(settings: $settings)->apiKey('antigravity');
    $credentials = $key === null ? null : json_decode($key, true);
    $token = is_array($credentials) ? ($credentials['token'] ?? null) : null;
    $project = is_array($credentials) ? ($credentials['projectId'] ?? null) : null;

    if (!is_string($token) || $token === '' || !is_string($project) || $project === '') {
        fwrite(STDERR, Style::red(
            "No Antigravity token and project. `bin/pig-ai login` stores both; an ordinary Gemini\n"
            . "API key is not enough for this endpoint.\n",
        ));

        exit(1);
    }

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

// The endpoint's own reply: `models` is runtime id => info, with no public ids, no routing and
// the enums inside each entry. That is the input of the extension's `Discovery::discover()`, so
// it goes through the same `Grouping` the extension runs at refresh — printing the runtime ids
// as rows would offer models nobody can ask for by name.
if (is_array($models) && $models !== [] && !array_is_list($models) && $routing === null) {
    $enums = [];

    foreach ($models as $runtime => $info) {
        if (is_array($info) && is_string($info['model'] ?? null) && $info['model'] !== '') {
            $enums[(string) $runtime] = $info['model'];
        }
    }

    // The shipped models as templates only — their limits and prices for a public id the reply
    // still serves — and none of their routing. Given the routing too, `Grouping` keeps a shipped
    // row's routing as it stands instead of reading the reply's, and appends every shipped model
    // the deployment no longer serves; both are right for a refresh that must not lose a model,
    // and wrong for a table that is meant to be what the deployment sells today.
    $catalog = \PigAntigravity\Grouping::buildAntigravityCatalog(
        $models,
        ['models' => \PigAntigravity\Discovery::fallbackCatalog()['models'], 'routing' => []],
    );
    $models = $catalog['models'];
    $routing = $catalog['routing'];
}

if (!is_array($models) || $models === []) {
    fwrite(STDERR, Style::yellow(
        "Nothing that looks like a model list came back. Run again with --raw to see the shape;\n"
        . "this script reads `models`, and the field may have been renamed.\n",
    ));

    exit(1);
}

echo '// ', count($models), " models, from {$endpoint}/v1internal:fetchAvailableModels on ", date('Y-m-d'), "\n";
echo "// [name, context window, max output, reasoning, accepts images, thinkingLevelMap]\n";

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

    // Named from the public id, not from the reply's `displayName`: the deployment answers the
    // ids it has retired with the model it now serves in their place, so `gemini-2.5-flash`,
    // `gemini-2.5-flash-lite` and `gemini-3.1-flash-lite` all came back as "Gemini 3.5 Flash Lite"
    // — three rows of one name in the picker. The id is the one thing that tells them apart, and
    // the shipped table has always been named after it.
    $name = \PigAntigravity\Grouping::humanizePublicId($id) . ' (Antigravity)';

    $levels = [];

    foreach (is_array($model['thinkingLevelMap'] ?? null) ? $model['thinkingLevelMap'] : [] as $level => $value) {
        $levels[] = "'{$level}' => " . (is_string($value) ? "'{$value}'" : 'null');
    }

    printf(
        "        '%s' => ['%s', %s, %s, %s, %s, [%s]],\n",
        $id,
        // A single quote in a model's display name would end the string it is being printed
        // into. Nothing in the catalogue has one today, and a generator that breaks the file it
        // writes the first time one appears is not worth the two characters saved.
        str_replace(["\\", "'"], ["\\\\", "\\'"], $name),
        number_format((int) ($model['contextWindow'] ?? 0), 0, '.', '_'),
        number_format((int) ($model['maxTokens'] ?? 0), 0, '.', '_'),
        ($model['reasoning'] ?? false) ? 'true' : 'false',
        $images ? 'true' : 'false',
        implode(', ', $levels),
    );
}

// ---- the two tables `PigAntigravity\Routing` carries between its generated markers -------------

if ($routing === null) {
    echo "\n", Style::yellow(
        "No `routing` in this payload, so the two tables in `PigAntigravity\\Routing` cannot be\n"
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
    "Rows, not a rewrite. The model rows go in `extensions/pig-antigravity/src/Models.php`, which\n"
    . "has no generated markers, and the two tables above go between the markers in\n"
    . "`extensions/pig-antigravity/src/Routing.php`. Read the diff: a routing change moves which model a thinking\n"
    . "level actually asks for, and every request still succeeds afterwards.\n",
);
