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
 * Antigravity CLI's current one. `GoogleGeminiCli` still carries the old pair; that is a separate
 * change and this script does not depend on it — the endpoint is overridable with `--endpoint` so
 * the two can be compared rather than argued about.
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

/** Where the catalogue answers. No `sandbox`, unlike `GoogleGeminiCli::SANDBOX_ENDPOINT`. */
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
$models = is_array($decoded) ? ($decoded['models'] ?? $decoded) : null;

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

echo "\n", Style::yellow(
    "Rows, not a rewrite: `ANTIGRAVITY_MODELS` has no generated markers, so paste these in and\n"
    . "read the diff. What this cannot know is the routing — the ids above are the logical ones,\n"
    . "and which runtime id each thinking level sends is a separate table.\n",
);
