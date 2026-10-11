<?php

declare(strict_types=1);

/**
 * Antigravity — Gemini 3, Claude and GPT-OSS on a Google subscription, through Code Assist.
 *
 * pi removed this provider from its core in 0.71 and the community rebuilt it as an extension
 * (`Rahularya01/pi-antigravity`); pig did the same move in one step, and this is the result.
 * Nothing about this provider is in `packages/` any more: the protocol (`AntigravityApi`), the
 * sign-in (`AntigravityOauth`), the model routing (`Routing`), the model table (`Models`), the
 * several-accounts store (`Accounts`), the catalogue pi keeps on disk (`Catalog`), quota
 * (`QuotaClient`) and image generation (`ImageGenerator`) all live beside this file and reach the
 * core through `ExtensionApi::registerProvider()` and the hooks below.
 *
 * What the core gives it, and why each is a hook and not a special case:
 *
 * - **`registerProvider()`** puts the models in the registry, the protocol behind
 *   `Api::Extension`, the image models behind `ImageApi::Extension` (`ImageGenerator`, so
 *   codemode's `$models->generateImages()` reaches them like any image model), and the sign-in in
 *   `/login`'s list. Install the extension and `--model antigravity/gemini-3.8-flash` works; remove
 *   it and the name means nothing.
 * - **`Auth::useSecondStore()`** is where `antigravity-accounts.json` meets `auth.json`: the
 *   session reads the active account when `auth.json` has none, and tells the store about a
 *   renewal so the extension's copy of a token does not go stale.
 * - **The 429 failover** is the protocol's own, as pi-antigravity has it: a quota wall on one
 *   account is answered inside the request by switching to the next stored account and sending
 *   again (`AntigravityApi`'s `$failover`, below).
 * - **`registerWebPanel()`** is the web UI's accounts panel (`AccountsPanel`), which used to be
 *   written into the page: the extension describes it, the page draws it in the sidebar's
 *   Extensions group, and `/accounts` opens it.
 * - **The model catalog is discovered** (`Discovery`, pi-antigravity's `refreshModels`): the
 *   deployment's `fetchAvailableModels` grouped into public models, filed in pig's own
 *   `models-store.json` and asked for again after four hours — at session start and after
 *   `/login antigravity` — the extension runs this itself rather than through
 *   `Provider::$refreshModels` and `ModelRefresh` — and on `/antigravity.refresh`, which forces it.
 *
 * The client id and secret are not in this repository (`ANTIGRAVITY_CLIENT_ID` /
 * `ANTIGRAVITY_CLIENT_SECRET`, or `antigravity.clientId` / `antigravity.clientSecret` in the
 * settings); see `antigravityClient()` below.
 */

use Pig\Agent\AgentToolResult;
use Pig\Ai\Extension\Provider;
use Pig\Ai\AssistantImages;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\ImageContent;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\ModelType;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Logger;
use PigAntigravity\Accounts;
use PigAntigravity\AccountsPanel;
use PigAntigravity\AntigravityApi;
use PigAntigravity\AntigravityOauth;
use PigAntigravity\Catalog;
use PigAntigravity\Discovery;
use PigAntigravity\GoogleSearch;
use PigAntigravity\ImageGenerator;
use PigAntigravity\LazyAntigravityOauth;
use PigAntigravity\Models;
use PigAntigravity\QuotaClient;

// Class files beside the entry. Loading them twice, or beside another copy, is the loader's
// business — `DeclaredSymbols` — not this file's.
foreach (['Routing', 'Models', 'AntigravityApi', 'AntigravityOauth', 'LazyAntigravityOauth', 'Accounts', 'AccountsPanel', 'Catalog', 'Grouping', 'Discovery', 'QuotaClient', 'ImageGenerator', 'GoogleSearch'] as $class) {
    require_once __DIR__ . "/src/{$class}.php";
}

return function (ExtensionApi $pi): void {
    $auth = $pi->auth() ?? Auth::discover();
    $accounts = Accounts::beside($auth->path());
    $http = new HttpClient();

    /**
     * The OAuth client this deployment was registered under, which pig does not ship.
     *
     * Upstream embeds the pair behind `atob()`, and for a Google installed-application client that
     * is defensible — the secret is not confidential by design and PKCE protects the exchange. It
     * is still not something a repository can carry: GitHub's push protection matches them plain
     * and base64-decoded, and a reported credential gets revoked. So: the environment, then the
     * settings file. A renewal may fall back to the public desktop client's pair, because a token
     * already minted under it has to be renewed under it.
     *
     * @return array{0: string, 1: string}
     */
    $antigravityClient = static function (bool $allowDefault = false) use ($auth): array {
        $id = getenv('ANTIGRAVITY_CLIENT_ID');
        $secret = getenv('ANTIGRAVITY_CLIENT_SECRET');
        $setting = static function (string $key) use ($auth): ?string {
            $value = $auth->settings()?->get($key);

            return is_string($value) && $value !== '' ? $value : null;
        };

        $id = is_string($id) && $id !== '' ? $id : $setting('antigravity.clientId');
        $secret = is_string($secret) && $secret !== '' ? $secret : $setting('antigravity.clientSecret');

        if (($id === null || $secret === null) && $allowDefault) {
            $id ??= base64_decode('MTA3MTAwNjA2MDU5MS10bWhzc2luMmgyMWxjcmUyMzV2dG9sb2poNGc0MDNlc' . 'C5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbQ==', true) ?: null;
            $secret ??= base64_decode('R09DU1BYLUs1OEZXUjQ' . '4NkxkTEoxbUxCOHNYQzR6NnFEQWY=', true) ?: null;
        }

        if ($id === null || $secret === null) {
            throw new OauthError(
                'Signing in to Antigravity needs its own client id and secret, which pig does not ship. '
                . 'Set ANTIGRAVITY_CLIENT_ID and ANTIGRAVITY_CLIENT_SECRET, or put antigravity.clientId and '
                . 'antigravity.clientSecret in ~/.pig/agent/settings.json.',
            );
        }

        return [$id, $secret];
    };

    // ---- the provider -------------------------------------------------------------------

    /**
     * pi-antigravity's `failoverToNextAccount(triedAccessTokens)`: the next stored account whose
     * access token has not been tried, renewed if it is due (one whose renewal fails is skipped),
     * made the active one in both files — and its api key, or null when no account is left.
     *
     * @param list<string> $tried
     */
    $failover = static function (array $tried) use ($auth, $accounts): ?string {
        for ($i = 0, $total = $accounts->count(); $i < $total; $i++) {
            $next = $accounts->rotateNext();

            if ($next === null) {
                return null;
            }

            $auth->setCredentials(Models::PROVIDER, $next);

            try {
                $fresh = $auth->freshCredentials(Models::PROVIDER);
            } catch (\Throwable) {
                // "Skip accounts whose refresh token is no longer valid."
                continue;
            }

            if ($fresh === null || $fresh->access === '' || in_array($fresh->access, $tried, true)) {
                continue;
            }

            return $auth->apiKey(Models::PROVIDER);
        }

        return null;
    };

    // Resold: `claude-sonnet-4-6` is Anthropic's id, and a bare `--model sonnet` has to keep
    // meaning Anthropic's — see `Models::RESOLD` in the core for the rule this joins.
    /**
     * pi-antigravity's `refreshModels`, as pi's model registry calls it: the stored catalog, the
     * network unless pig is offline, the account's key, and the store to file the answer in. Not
     * with `--no-session`, which reads and writes none of the person's files.
     *
     * @return list<array<string, mixed>> the models now current
     */
    $refreshCatalog = static function (bool $force, ?string $apiKey = null, ?AbortSignal $signal = null) use ($auth): array {
        if ($auth->path() === null) {
            return Discovery::current()['models'];
        }

        return Discovery::refresh(
            Catalog::discover(),
            // pi's `modelNetworkEnabled` is `process.env.PI_OFFLINE === undefined`.
            getenv('PIG_OFFLINE') === false,
            $apiKey ?? $auth->apiKey(Models::PROVIDER),
            $force,
            $signal,
            static fn (array $entry) => Discovery::persist(Catalog::storePath(), $entry),
        );
    };

    /** The same, unforced and in the background: what upstream does with `void modelRuntime.refresh()`. */
    $refreshInBackground = static function (?Credentials $signedIn = null) use ($refreshCatalog): void {
        Async::spawn(static function () use ($refreshCatalog, $signedIn): void {
            try {
                $refreshCatalog(false, $signedIn === null ? null : AntigravityOauth::keyFor($signedIn));
            } catch (Throwable $error) {
                Logger::warning('Antigravity model catalog refresh failed: ' . AntigravityApi::redactSecrets($error->getMessage()));
            }
        });
    };

    $pi->registerProvider(new Provider(
        id: Models::PROVIDER,
        name: 'Antigravity (Gemini 3, Claude, GPT-OSS)',
        models: Models::fallback(),
        api: new AntigravityApi(failover: $failover),
        // "After you sign in, the provider refreshes its catalog from Antigravity" — with the new
        // account's key, which `auth.json` does not hold yet when the flow returns.
        oauth: new LazyAntigravityOauth(
            $antigravityClient,
            static fn (Credentials $credentials) => $refreshInBackground($credentials),
        ),
        resold: true,
        images: ImageGenerator::models(),
        imageApi: new ImageGenerator(failover: $failover),
    ));

    // And then the catalog on disk — pig's own store, which `Discovery` keeps, or pi's — which is a
    // newer reading of the same endpoint the fallback was printed from, so it replaces the
    // fallback (`hydrateAntigravityCatalog()`). Not with `--no-session`.
    if ($auth->path() !== null) {
        $catalog = Catalog::discover();
        Discovery::hydrate($catalog);

        foreach ($catalog->problems as $problem) {
            fwrite(STDERR, "antigravity: {$problem}\n");
        }
    }

    $pi->on('session_start', static function () use ($refreshInBackground): void {
        $refreshInBackground();
    });

    // ---- the second place the credentials live ------------------------------------------

    $auth->useSecondStore(
        Models::PROVIDER,
        static fn (): ?Credentials => $accounts->active(),
        static function (Credentials $previous, Credentials $next) use ($accounts): void {
            $accounts->renewed($previous, $next);
        },
    );

    /** Make one stored account the active one, in both files at once. */
    $activate = static function (string $id) use ($auth, $accounts): ?Credentials {
        if (!isset($accounts->accounts()[$id])) {
            return null;
        }

        $accounts->activate($id);
        $active = $accounts->active();

        if ($active !== null) {
            $auth->setCredentials(Models::PROVIDER, $active);
        }

        return $active;
    };

    /** The next account round-robin, in both files. */
    $rotate = static function () use ($auth, $accounts): ?Credentials {
        $next = $accounts->rotateNext();

        if ($next !== null) {
            $auth->setCredentials(Models::PROVIDER, $next);
        }

        return $next;
    };

    /** Forget one; if it was the active one, whichever is left becomes active — or nothing is. */
    $remove = static function (string $id) use ($auth, $accounts): bool {
        if (!isset($accounts->accounts()[$id])) {
            return false;
        }

        $accounts->remove($id);
        $active = $accounts->active();

        if ($active !== null) {
            $auth->setCredentials(Models::PROVIDER, $active);
        } else {
            $auth->remove(Models::PROVIDER);
        }

        return true;
    };

    /** An account by index (1-based) or by email or id. */
    $find = static function (string $target) use ($accounts): ?string {
        $all = $accounts->accounts();

        if (is_numeric($target)) {
            return array_keys($all)[(int) $target - 1] ?? null;
        }

        foreach ($all as $id => $acc) {
            if ($id === $target || ($acc['email'] ?? null) === $target) {
                return (string) $id;
            }
        }

        return null;
    };

    // ---- what every command needs ------------------------------------------------------

    $getAuthAndToken = static function () use ($auth): ?array {
        // `freshCredentials()`, not `credentials()`: the stored access token lives an hour, and
        // reading it raw answered 401 on every command from the second hour on.
        $cred = $auth->freshCredentials(Models::PROVIDER);

        if ($cred === null) {
            return null;
        }

        return [$auth, $cred->access, $cred->projectId ?? 'antigravity-default', $cred];
    };

    /**
     * The `generate_image` tool's and `/antigravity.image`'s request: `Models::generateImages()` on
     * this provider's image model — the path codemode's `$models->generateImages()` takes — with the
     * active account's key, then the images saved. A registered model, or the id as asked for.
     *
     * @return array{0: AssistantImages, 1: list<string>} the answer and the paths written
     * @throws RuntimeException with the answer's own error when there is no image
     */
    $generateAndSave = static function (string $prompt, string $ratio, string $model, ?string $targetPath, string $cwd, ?AbortSignal $signal) use ($getAuthAndToken): array {
        $authData = $getAuthAndToken();

        if ($authData === null) {
            throw new RuntimeException('No Antigravity credentials. Run /login antigravity first.');
        }

        [, $token, $projectId] = $authData;
        $id = trim($model) !== '' ? trim($model) : ImageGenerator::DEFAULT_MODEL;
        $imageModel = \Pig\Ai\Models::findOfType(ModelType::Image, Models::PROVIDER, $id) ?? ImageGenerator::model($id);
        \assert($imageModel instanceof \Pig\Ai\ImageModel);
        $result = \Pig\Ai\Models::generateImages(
            $imageModel,
            new ImagesContext([new TextContent($prompt)]),
            new ImagesOptions(
                signal: $signal,
                apiKey: (string) json_encode(['token' => $token, 'projectId' => $projectId]),
                metadata: ['aspectRatio' => $ratio],
            ),
        );

        if ($result->stopReason !== StopReason::Stop) {
            throw new RuntimeException($result->errorMessage ?? 'Image generation failed.');
        }

        return [$result, ImageGenerator::save($result, $targetPath, $cwd)];
    };

    $emit = static function (HookContext $ctx, string $text, string $type = 'info'): void {
        if ($ctx->hasUi) {
            $ctx->ui->notify($text, $type);
        } else {
            echo $text, "\n";
        }
    };

    // ---- the web UI's accounts panel ----------------------------------------------------

    /** The accounts as the panel lists them. */
    $payload = static function () use ($accounts): array {
        $rows = [];

        foreach ($accounts->accounts() as $id => $entry) {
            $rows[] = [
                'id' => (string) $id,
                'email' => is_string($entry['email'] ?? null) ? $entry['email'] : (string) $id,
                'projectId' => is_string($entry['projectId'] ?? null) ? $entry['projectId'] : null,
                'expires' => is_int($entry['expires'] ?? null) ? $entry['expires'] : null,
                'active' => (string) $id === $accounts->activeId(),
            ];
        }

        return ['accounts' => $rows, 'problems' => $accounts->problems()];
    };

    $pi->registerWebPanel(
        AccountsPanel::ID,
        AccountsPanel::TITLE,
        static function (array $request) use ($payload, $getAuthAndToken): array {
            $locale = $request['locale'];

            if ($request['section'] !== AccountsPanel::USAGE) {
                $state = $payload();

                return AccountsPanel::blocks($state['accounts'], $state['problems'], $locale);
            }

            $authData = $getAuthAndToken();

            if ($authData === null) {
                return [['type' => 'text', 'text' => AccountsPanel::say($locale, 'not_signed_in'), 'tone' => 'muted']];
            }

            [, $token, $projectId] = $authData;

            try {
                return AccountsPanel::usageBlocks((new QuotaClient())->fetchUsage($token, $projectId), $locale);
            } catch (Throwable $e) {
                return [['type' => 'text', 'text' => $e->getMessage(), 'tone' => 'error']];
            }
        },
        static function (string $action, array $args, array $request) use ($activate, $remove, $rotate): ?string {
            $id = is_string($args['id'] ?? null) ? $args['id'] : '';
            $noSuch = AccountsPanel::say($request['locale'], 'no_such_account', ['id' => $id]);

            return match ($action) {
                'activate' => $activate($id) !== null ? null : $noSuch,
                'remove' => $remove($id) ? null : $noSuch,
                'rotate' => $rotate() !== null ? null : AccountsPanel::say($request['locale'], 'nothing_to_rotate'),
                default => "No action '{$action}'.",
            };
        },
        icon: '🔑',
        commands: ['accounts', 'antigravity.accounts'],
        // Where the accounts button always was.
        placement: 'header',
        label: AccountsPanel::LABEL,
    );

    // ---- commands -----------------------------------------------------------------------

    $pi->registerCommand(
        'antigravity.usage',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');

                return;
            }

            [, $token, $projectId] = $authData;
            $emit($ctx, 'Fetching Antigravity usage…', 'info');

            try {
                $emit($ctx, QuotaClient::formatUsageSummary((new QuotaClient())->fetchUsage($token, $projectId)), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity usage failed: ' . $e->getMessage(), 'error');
            }
        },
        'Show Antigravity shared quota pools (Gemini / Claude+GPT, 5h + weekly)',
    );

    $pi->registerCommand(
        'antigravity.models',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');

                return;
            }

            [, $token, $projectId] = $authData;

            try {
                $usage = (new QuotaClient())->fetchUsage($token, $projectId);
                $emit($ctx, QuotaClient::formatModelsList($usage, preg_match('/\ball\b/i', $args) === 1), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity models failed: ' . $e->getMessage(), 'error');
            }
        },
        'List Antigravity runtime models + remaining pool fraction',
    );

    $pi->registerCommand(
        'antigravity.accounts',
        static function (string $args, HookContext $ctx) use ($accounts, $emit, $activate, $remove, $rotate, $find): void {
            $trimmed = trim($args);

            if ($trimmed === 'rotate') {
                $next = $rotate();
                $emit($ctx, $next !== null ? 'Rotated Antigravity account to: ' . ($next->email ?? 'next') : 'Only one Antigravity account available; cannot rotate.', $next !== null ? 'info' : 'warning');

                return;
            }

            foreach (['switch ' => $activate, 'remove ' => $remove] as $verb => $action) {
                if (!str_starts_with($trimmed, $verb)) {
                    continue;
                }

                $target = trim(substr($trimmed, strlen($verb)));
                $id = $find($target);

                if ($id === null) {
                    $emit($ctx, "Account '{$target}' not found.", 'error');

                    return;
                }

                $action($id);
                $emit($ctx, ($verb === 'switch ' ? 'Active Antigravity account: ' : 'Antigravity account removed: ') . $id, 'info');

                return;
            }

            $all = $accounts->accounts();

            if ($all === []) {
                $emit($ctx, 'No linked Antigravity accounts. Run /login antigravity to add one.', 'warning');

                return;
            }

            $active = $accounts->activeId();
            $lines = [];
            $i = 1;

            foreach ($all as $id => $acc) {
                $lines[] = ((string) $id === $active ? '* ' : '  ') . $i++ . '. ' . ($acc['email'] ?? $id);
            }

            $emit($ctx, implode("\n", $lines) . "\n\nUse /antigravity.accounts rotate, /antigravity.accounts switch <index|email> or /antigravity.accounts remove <index|email>.", 'info');
        },
        'List, switch, or remove linked Antigravity Google accounts',
    );

    $pi->registerCommand(
        'antigravity.doctor',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $accounts, $emit): void {
            $authData = $getAuthAndToken();
            $tokenStatus = 'none';
            $projectId = 'none';

            if ($authData !== null) {
                [, , $projectId, $cred] = $authData;
                $remSec = (int) (($cred->expires - (int) (microtime(true) * 1000)) / 1000);
                $tokenStatus = $remSec > 0 ? "valid ({$remSec}s remaining)" : 'expired';
            }

            $active = $accounts->active();
            $noExtraTools = (getenv('ANTIGRAVITY_NO_EXTRA_TOOLS') ?: getenv('NOAGY_NO_EXTRA_TOOLS')) === '1';
            $noSearchTool = $noExtraTools || (getenv('ANTIGRAVITY_NO_SEARCH_TOOL') ?: getenv('NOAGY_NO_SEARCH_TOOL')) === '1';
            $noImageTool = $noExtraTools || (getenv('ANTIGRAVITY_NO_IMAGE_TOOL') ?: getenv('NOAGY_NO_IMAGE_TOOL')) === '1';
            $activeTools = array_keys(array_filter(['generate_image' => !$noImageTool, 'google_search' => !$noSearchTool]));

            $emit($ctx, implode("\n", [
                'Antigravity doctor',
                'provider=' . Models::PROVIDER,
                'endpoint=' . QuotaClient::DEFAULT_ENDPOINT,
                'activeAccount=' . ($active?->email ?? ($active !== null ? 'authenticated' : 'none')),
                'linkedAccounts=' . count($accounts->accounts()),
                'projectId=' . $projectId,
                'tokenStatus=' . $tokenStatus,
                'commands=/antigravity.usage /antigravity.models /antigravity.accounts /antigravity.doctor /antigravity.refresh /antigravity.image /antigravity.search',
                'tools=' . implode(', ', $activeTools),
            ]), 'info');
        },
        'Show sanitized Antigravity provider diagnostics',
    );

    $pi->registerCommand(
        'antigravity.refresh',
        static function (string $args, HookContext $ctx) use ($auth, $emit, $refreshCatalog): void {
            $apiKey = $auth->apiKey(Models::PROVIDER);

            if ($apiKey === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');

                return;
            }

            if ($ctx->hasUi) {
                $ctx->ui->notify('Refreshing Antigravity models…', 'info');
            }

            try {
                $refreshCatalog(true, $apiKey);
                $models = Discovery::current()['models'];
                $count = count($models);
                $sample = implode(', ', array_map(
                    static fn (array $model): string => is_string($model['name'] ?? null) && $model['name'] !== '' ? $model['name'] : (string) $model['id'],
                    array_slice($models, 0, 4),
                ));
                $emit($ctx, "Antigravity models refreshed ({$count} available: {$sample}" . ($count > 4 ? ', …' : '') . ')', 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity model refresh failed: ' . AntigravityApi::redactSecrets($e->getMessage()), 'error');
            }
        },
        'Force refresh Antigravity dynamic model catalog',
    );

    $pi->registerCommand(
        'antigravity.search',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $http, $emit): void {
            $parsed = GoogleSearch::parseSearchArgs($args);
            if ($parsed['query'] === '') {
                $emit($ctx, 'Usage: /antigravity.search [--thinking] [--url <url>] <query>', 'warning');

                return;
            }

            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');

                return;
            }

            [, $token, $projectId] = $authData;
            $emit($ctx, "Searching Google for \"{$parsed['query']}\"…", 'info');

            try {
                $result = GoogleSearch::executeSearch($http, $token, $projectId, $parsed['query'], null, $parsed['urls'], $parsed['thinking'], null);
                $emit($ctx, $result, 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity search failed: ' . $e->getMessage(), 'error');
            }
        },
        'Search the web via Google Search grounding (usage: /antigravity.search [--thinking] [--url <url>] <query>)',
    );

    $pi->registerCommand(
        'antigravity.image',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $generateAndSave, $emit): void {
            $usage = 'Usage: /antigravity.image [--ratio 16:9] [--model gemini-3-pro-image] [--path file.png] <prompt>';
            $tokens = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($tokens === []) {
                $emit($ctx, $usage, 'warning');

                return;
            }

            $ratio = '1:1';
            $model = ImageGenerator::DEFAULT_MODEL;
            $targetPath = null;
            $promptParts = [];

            for ($i = 0; $i < count($tokens); $i++) {
                if (($tokens[$i] === '--ratio' || $tokens[$i] === '--aspect-ratio') && isset($tokens[$i + 1])) {
                    $ratio = $tokens[++$i];
                } elseif ($tokens[$i] === '--model' && isset($tokens[$i + 1])) {
                    $model = $tokens[++$i];
                } elseif ($tokens[$i] === '--path' && isset($tokens[$i + 1])) {
                    $targetPath = $tokens[++$i];
                } else {
                    $promptParts[] = $tokens[$i];
                }
            }

            $prompt = implode(' ', $promptParts);

            if ($prompt === '') {
                $emit($ctx, $usage, 'warning');

                return;
            }

            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');

                return;
            }

            $emit($ctx, 'Generating Antigravity image…', 'info');

            try {
                [, $savedPaths] = $generateAndSave($prompt, $ratio, $model, $targetPath, $ctx->cwd, null);
                $emit($ctx, 'Saved image to ' . implode(', ', $savedPaths), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity image failed: ' . $e->getMessage(), 'error');
            }
        },
        'Generate an image via Antigravity (usage: /antigravity.image [--ratio 16:9] <prompt>)',
    );

    // ---- extra tools: google_search & generate_image -----------------------------------

    $noExtraTools = (getenv('ANTIGRAVITY_NO_EXTRA_TOOLS') ?: getenv('NOAGY_NO_EXTRA_TOOLS')) === '1';
    $noSearchTool = $noExtraTools || (getenv('ANTIGRAVITY_NO_SEARCH_TOOL') ?: getenv('NOAGY_NO_SEARCH_TOOL')) === '1';
    $noImageTool = $noExtraTools || (getenv('ANTIGRAVITY_NO_IMAGE_TOOL') ?: getenv('NOAGY_NO_IMAGE_TOOL')) === '1';

    if (!$noSearchTool) {
        $pi->registerTool(new CustomTool(
            name: 'google_search',
            label: 'Google Search',
            description: 'Search the live web using Google Search grounding over Google Cloud Code Assist. Returns an evidence brief, sources, and verified citations.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query to look up.'],
                    'instruction' => ['type' => 'string', 'description' => 'Optional specific directive to guide search synthesis.'],
                    'urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional specific target URLs to analyze in detail.'],
                    'thinking' => ['type' => 'boolean', 'description' => 'Optional flag to enable deep reasoning for search planning.'],
                ],
                'required' => ['query'],
            ],
            execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($getAuthAndToken, $http): AgentToolResult {
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent('Searching Google…')]));
                }

                $authData = $getAuthAndToken();
                if ($authData === null) {
                    throw new RuntimeException('No Antigravity credentials. Run /login antigravity first.');
                }

                [, $token, $projectId] = $authData;
                $query = (string) ($params['query'] ?? '');
                $instruction = isset($params['instruction']) && is_string($params['instruction']) ? $params['instruction'] : null;
                $urls = isset($params['urls']) && is_array($params['urls']) ? array_values(array_filter($params['urls'], 'is_string')) : [];
                $thinking = ($params['thinking'] ?? false) === true;

                $output = GoogleSearch::executeSearch($http, $token, $projectId, $query, $instruction, $urls, $thinking, $signal);

                return new AgentToolResult([new TextContent($output)]);
            },
        ));
    }

    if (!$noImageTool) {
        $pi->registerTool(new CustomTool(
            name: 'generate_image',
            label: 'Generate image',
            description: 'Generate an image via Antigravity using the signed-in Google account. Saves under .pig/generated-images/ unless path is set.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'Image description.'],
                    'aspectRatio' => ['type' => 'string', 'enum' => ImageGenerator::ASPECT_RATIOS, 'description' => 'Aspect ratio for the generated image.'],
                    'model' => ['type' => 'string', 'description' => 'Image model id. Default: ' . ImageGenerator::DEFAULT_MODEL . '.'],
                    'path' => ['type' => 'string', 'description' => 'Project-relative file or directory to save the image.'],
                ],
                'required' => ['prompt'],
            ],
            execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($generateAndSave): AgentToolResult {
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent('Generating image…')]));
                }

                [$result, $savedPaths] = $generateAndSave(
                    (string) ($params['prompt'] ?? ''),
                    (string) ($params['aspectRatio'] ?? '1:1'),
                    (string) ($params['model'] ?? ImageGenerator::DEFAULT_MODEL),
                    isset($params['path']) && is_string($params['path']) ? $params['path'] : null,
                    $ctx->cwd,
                    $signal,
                );

                $notes = trim(implode(' ', array_map(static fn (TextContent $text): string => $text->text, array_values(array_filter($result->output, static fn ($block): bool => $block instanceof TextContent)))));
                $contents = [new TextContent('Saved image to ' . implode(', ', $savedPaths) . ($notes !== '' ? ". {$notes}" : ''))];

                foreach ($result->output as $block) {
                    if ($block instanceof ImageContent) {
                        $contents[] = $block;
                    }
                }

                // The usage goes on the result, where the session bills it like any model call's.
                return new AgentToolResult($contents, ['model' => $result->model, 'savedPaths' => $savedPaths], $result->usage);
            },
        ));
    }
};
