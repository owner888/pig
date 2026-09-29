<?php
declare(strict_types=1);

/**
 * Copy Antigravity's OAuth client pair out of smart-book and into pig's settings.
 *
 * Neither value is printed. pig refuses to carry them in the repository — see
 * `Auth::antigravityClient()` for why — so they live in `~/.pig/agent/settings.json`, which is
 * where that method looks first after the environment.
 */
$source = ($_SERVER['HOME'] ?? '') . '/Development/owner/smart-book/src/AI/Antigravity/AntigravityAuth.php';
$settingsPath = ($_SERVER['HOME'] ?? '') . '/.pig/agent/settings.json';

if (!is_file($source)) {
    exit("No AntigravityAuth.php at {$source}\n");
}

$php = (string) file_get_contents($source);

preg_match("/CLIENT_ID\s*=\s*'([^']+)'/", $php, $id);
preg_match("/CLIENT_SECRET_B64\s*=\s*'([^']+)'/", $php, $secret);

if (($id[1] ?? '') === '' || ($secret[1] ?? '') === '') {
    exit("Could not find CLIENT_ID / CLIENT_SECRET_B64 in that file.\n");
}

$decoded = base64_decode($secret[1], true);

if (!is_string($decoded) || $decoded === '') {
    exit("CLIENT_SECRET_B64 did not decode.\n");
}

$settings = is_file($settingsPath)
    ? (json_decode((string) file_get_contents($settingsPath), true) ?: [])
    : [];

if (!is_array($settings)) {
    exit("{$settingsPath} is not a JSON object; leaving it alone.\n");
}

// Merged, not replaced: everything else in there stays.
$settings['antigravity'] = ['clientId' => $id[1], 'clientSecret' => $decoded]
    + (is_array($settings['antigravity'] ?? null) ? $settings['antigravity'] : []);

@mkdir(dirname($settingsPath), 0o700, true);

file_put_contents(
    $settingsPath,
    json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);
chmod($settingsPath, 0o600);

printf(
    "Written to %s — clientId ends %s, secret is %d characters. Neither was printed.\n",
    $settingsPath,
    substr($id[1], -14),
    strlen($decoded),
);
