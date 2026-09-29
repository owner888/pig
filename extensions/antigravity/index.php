<?php

declare(strict_types=1);

use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Antigravity\Accounts;
use Pig\CodingAgent\Antigravity\Catalog;
use Pig\CodingAgent\Antigravity\ImageGenerator;
use Pig\CodingAgent\Antigravity\QuotaClient;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;

return function (ExtensionApi $pi): void {
    $getAuthAndToken = static function () use ($pi): ?array {
        $auth = $pi->auth() ?? Auth::discover();
        $cred = $auth->credentials(Provider::Antigravity);
        if ($cred === null) {
            return null;
        }

        return [$auth, $cred->access, $cred->projectId ?? 'antigravity-default', $cred];
    };

    $emit = static function (HookContext $ctx, string $text, string $type = 'info'): void {
        if ($ctx->hasUi) {
            $ctx->ui->notify($text, $type);
        } else {
            echo $text, "\n";
        }
    };

    // 1. /antigravity.usage
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
                $client = new QuotaClient();
                $usage = $client->fetchUsage($token, $projectId);
                $emit($ctx, QuotaClient::formatUsageSummary($usage), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity usage failed: ' . $e->getMessage(), 'error');
            }
        },
        'Show Antigravity shared quota pools (Gemini / Claude+GPT, 5h + weekly)',
    );

    // 2. /antigravity.models
    $pi->registerCommand(
        'antigravity.models',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');
                return;
            }

            [, $token, $projectId] = $authData;
            $all = preg_match('/\ball\b/i', $args) === 1;

            try {
                $client = new QuotaClient();
                $usage = $client->fetchUsage($token, $projectId);
                $emit($ctx, QuotaClient::formatModelsList($usage, $all), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity models failed: ' . $e->getMessage(), 'error');
            }
        },
        'List Antigravity runtime models + remaining pool fraction',
    );

    // 3. /antigravity.accounts
    $pi->registerCommand(
        'antigravity.accounts',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $auth = Auth::discover();
            $accounts = Accounts::beside($auth->path());
            $trimmed = trim($args);

            if (str_starts_with($trimmed, 'switch ')) {
                $target = trim(substr($trimmed, 7));
                $all = $accounts->accounts();
                $foundId = null;

                if (is_numeric($target)) {
                    $idx = (int) $target - 1;
                    $keys = array_keys($all);
                    $foundId = $keys[$idx] ?? null;
                } else {
                    foreach ($all as $id => $acc) {
                        if ($id === $target || ($acc['email'] ?? null) === $target) {
                            $foundId = $id;
                            break;
                        }
                    }
                }

                if ($foundId === null) {
                    $emit($ctx, "Account '{$target}' not found.", 'error');
                    return;
                }

                $accounts->activate($foundId);
                $active = $accounts->active();
                if ($active !== null) {
                    $auth->setCredentials(Provider::Antigravity, $active);
                }
                $emit($ctx, "Active Antigravity account: {$foundId}", 'info');
                return;
            }

            if (str_starts_with($trimmed, 'remove ')) {
                $target = trim(substr($trimmed, 7));
                $all = $accounts->accounts();
                $foundId = null;

                if (is_numeric($target)) {
                    $idx = (int) $target - 1;
                    $keys = array_keys($all);
                    $foundId = $keys[$idx] ?? null;
                } else {
                    foreach ($all as $id => $acc) {
                        if ($id === $target || ($acc['email'] ?? null) === $target) {
                            $foundId = $id;
                            break;
                        }
                    }
                }

                if ($foundId === null) {
                    $emit($ctx, "Account '{$target}' not found.", 'error');
                    return;
                }

                $accounts->remove($foundId);
                $active = $accounts->active();
                if ($active !== null) {
                    $auth->setCredentials(Provider::Antigravity, $active);
                } else {
                    $auth->remove(Provider::Antigravity->value);
                }
                $emit($ctx, "Antigravity account removed: {$foundId}", 'info');
                return;
            }

            $all = $accounts->accounts();
            if ($all === []) {
                $emit($ctx, 'No linked Antigravity accounts. Run /login antigravity to add one.', 'warning');
                return;
            }

            $activeCred = $accounts->active();
            $lines = [];
            $i = 1;
            foreach ($all as $id => $acc) {
                $email = $acc['email'] ?? $id;
                $isActive = $activeCred !== null && ($activeCred->email === $email || $id === ($acc['accountId'] ?? ''));
                $mark = $isActive ? '* ' : '  ';
                $lines[] = "{$mark}{$i}. {$email}";
                $i++;
            }

            $emit($ctx, implode("\n", $lines) . "\n\nUse /antigravity.accounts switch <index|email> or /antigravity.accounts remove <index|email>.", 'info');
        },
        'List, switch, or remove linked Antigravity Google accounts',
    );

    // 4. /antigravity.doctor
    $pi->registerCommand(
        'antigravity.doctor',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $authData = $getAuthAndToken();
            $auth = Auth::discover();
            $accounts = Accounts::beside($auth->path());
            $all = $accounts->accounts();
            $activeCred = $accounts->active();

            $tokenStatus = 'none';
            $projectId = 'none';
            if ($authData !== null) {
                [, $token, $projectId, $cred] = $authData;
                $now = (int) (microtime(true) * 1000);
                $remSec = (int) (($cred->expires - $now) / 1000);
                $tokenStatus = $remSec > 0 ? "valid ({$remSec}s remaining)" : 'expired';
            }

            $activeLabel = $activeCred?->email ?? ($activeCred !== null ? 'authenticated' : 'none');
            $lines = [
                'Antigravity doctor',
                'provider=' . Provider::Antigravity->value,
                'endpoint=' . QuotaClient::DEFAULT_ENDPOINT,
                'activeAccount=' . $activeLabel,
                'linkedAccounts=' . count($all),
                'projectId=' . $projectId,
                'tokenStatus=' . $tokenStatus,
                'commands=/antigravity.usage /antigravity.models /antigravity.accounts /antigravity.doctor /antigravity.refresh /antigravity.image',
                'tools=generate_image',
            ];

            $emit($ctx, implode("\n", $lines), 'info');
        },
        'Show sanitized Antigravity provider diagnostics',
    );

    // 5. /antigravity.refresh
    $pi->registerCommand(
        'antigravity.refresh',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');
                return;
            }

            [, $token, $projectId] = $authData;
            $emit($ctx, 'Refreshing Antigravity models…', 'info');

            try {
                $catalog = Catalog::discover();
                $count = count($catalog->models);
                $emit($ctx, "Antigravity models catalog inspected ({$count} models loaded)", 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity model refresh failed: ' . $e->getMessage(), 'error');
            }
        },
        'Force inspect and refresh Antigravity model catalog',
    );

    // 6. /antigravity.image
    $pi->registerCommand(
        'antigravity.image',
        static function (string $args, HookContext $ctx) use ($getAuthAndToken, $emit): void {
            $tokens = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                $emit($ctx, 'Usage: /antigravity.image [--ratio 16:9] [--model gemini-3-pro-image] [--path file.png] <prompt>', 'warning');
                return;
            }

            $ratio = '1:1';
            $model = ImageGenerator::DEFAULT_MODEL;
            $targetPath = null;
            $promptParts = [];

            for ($i = 0; $i < count($tokens); $i++) {
                if (($tokens[$i] === '--ratio' || $tokens[$i] === '--aspect-ratio') && isset($tokens[$i + 1])) {
                    $ratio = $tokens[++$i];
                    continue;
                }
                if ($tokens[$i] === '--model' && isset($tokens[$i + 1])) {
                    $model = $tokens[++$i];
                    continue;
                }
                if ($tokens[$i] === '--path' && isset($tokens[$i + 1])) {
                    $targetPath = $tokens[++$i];
                    continue;
                }
                $promptParts[] = $tokens[$i];
            }

            $prompt = implode(' ', $promptParts);
            if ($prompt === '') {
                $emit($ctx, 'Usage: /antigravity.image [--ratio 16:9] [--model gemini-3-pro-image] [--path file.png] <prompt>', 'warning');
                return;
            }

            $authData = $getAuthAndToken();
            if ($authData === null) {
                $emit($ctx, 'No Antigravity credentials. Run /login antigravity first.', 'warning');
                return;
            }

            [, $token, $projectId] = $authData;
            $emit($ctx, 'Generating Antigravity image…', 'info');

            try {
                $generator = new ImageGenerator();
                $result = $generator->generate($token, $projectId, $prompt, $ratio, $model, $targetPath, $ctx->cwd);
                $emit($ctx, 'Saved image to ' . implode(', ', $result->savedPaths), 'info');
            } catch (Throwable $e) {
                $emit($ctx, 'Antigravity image failed: ' . $e->getMessage(), 'error');
            }
        },
        'Generate an image via Antigravity (usage: /antigravity.image [--ratio 16:9] <prompt>)',
    );

    // 7. Tool: generate_image
    $pi->registerTool(new CustomTool(
        name: 'generate_image',
        label: 'Generate image',
        description: 'Generate an image via Antigravity using the signed-in Google account. Saves under .pi/generated-images/ unless path is set.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Image description.',
                ],
                'aspectRatio' => [
                    'type' => 'string',
                    'enum' => ImageGenerator::ASPECT_RATIOS,
                    'description' => 'Aspect ratio for the generated image.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Image model id. Default: ' . ImageGenerator::DEFAULT_MODEL . '.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Project-relative file or directory to save the image.',
                ],
            ],
            'required' => ['prompt'],
        ],
        execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($getAuthAndToken): AgentToolResult {
            $authData = $getAuthAndToken();
            if ($authData === null) {
                throw new RuntimeException('No Antigravity credentials. Run /login antigravity first.');
            }

            [, $token, $projectId] = $authData;

            if ($onUpdate !== null) {
                $onUpdate(new AgentToolResult([new TextContent('Generating image…')]));
            }

            $generator = new ImageGenerator();
            $result = $generator->generate(
                $token,
                $projectId,
                (string) ($params['prompt'] ?? ''),
                (string) ($params['aspectRatio'] ?? '1:1'),
                (string) ($params['model'] ?? ImageGenerator::DEFAULT_MODEL),
                isset($params['path']) && is_string($params['path']) ? $params['path'] : null,
                $ctx->cwd,
                $signal,
            );

            $notes = trim(implode(' ', $result->text));
            $savedNotice = 'Saved image to ' . implode(', ', $result->savedPaths) . ($notes !== '' ? ". {$notes}" : '');

            $contents = [new TextContent($savedNotice)];
            foreach ($result->images as $img) {
                $contents[] = new ImageContent($img['data'], $img['mimeType']);
            }

            return new AgentToolResult($contents, [
                'model' => $result->model,
                'savedPaths' => $result->savedPaths,
            ]);
        },
    ));
};
