<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Antigravity;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Client for Antigravity's quota and model availability endpoints.
 *
 * Calls:
 * - `v1internal:loadCodeAssist` (user metadata, tier, project ID)
 * - `v1internal:retrieveUserQuotaSummary` (5h and weekly quota pools for Gemini & Claude/GPT)
 * - `v1internal:fetchAvailableModels` (runtime models and their quota remaining fraction)
 */
final class QuotaClient
{
    public const string DEFAULT_ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';

    public const array ENDPOINTS = [
        'https://daily-cloudcode-pa.googleapis.com',
        'https://cloudcode-pa.googleapis.com',
        'https://daily-cloudcode-pa.sandbox.googleapis.com',
    ];

    private const string USER_AGENT = 'antigravity/cli/1.1.23 (aidev_client; os_type=linux; arch=amd64; cl=974125021; auth_method=consumer)';

    public function __construct(
        private readonly ?HttpClient $http = null,
        private readonly ?string $endpoint = null,
    ) {
    }

    /**
     * Fetch complete account usage and quota status.
     *
     * @return array{
     *     projectId: string,
     *     endpoint: string,
     *     planLabel: ?string,
     *     groups: list<array{displayName: string, description: ?string, buckets: list<array{bucketId: string, displayName: string, window: ?string, resetTime: ?string, description: ?string, remainingFraction: float}>}>,
     *     quotaError: ?string,
     *     models: list<array{modelId: string, displayName: string, remainingFraction: ?float, resetTime: ?string}>,
     *     defaultAgentModelId: ?string,
     * }
     */
    public function fetchUsage(string $token, ?string $projectId = null): array
    {
        $http = $this->http ?? new HttpClient(15.0);
        $candidates = $this->endpoint !== null ? [$this->endpoint] : self::ENDPOINTS;

        // 1. loadCodeAssist
        $assistData = $this->postJsonSafe($http, $candidates, '/v1internal:loadCodeAssist', $token, $projectId, [
            'metadata' => [
                'ideType' => 'ANTIGRAVITY',
                'platform' => 'PLATFORM_UNSPECIFIED',
                'pluginType' => 'GEMINI',
            ],
        ]);

        $resolvedProject = $projectId;
        if (($resolvedProject === null || $resolvedProject === '') && is_array($assistData)) {
            $resolvedProject = self::extractProject($assistData);
        }
        $resolvedProject ??= 'antigravity-default';

        // 2. retrieveUserQuotaSummary
        $quotaData = null;
        $quotaError = null;
        try {
            $quotaRes = $this->postJson($http, $candidates, '/v1internal:retrieveUserQuotaSummary', $token, $resolvedProject, []);
            $quotaData = $quotaRes['data'];
            $endpointUsed = $quotaRes['endpoint'];
        } catch (Throwable $e) {
            $quotaError = $e->getMessage();
            $endpointUsed = $candidates[0];
        }

        // 3. fetchAvailableModels
        $modelsData = $this->postJsonSafe($http, $candidates, '/v1internal:fetchAvailableModels', $token, $resolvedProject, [
            'project' => $resolvedProject,
        ]);

        $groups = [];
        if (is_array($quotaData) && isset($quotaData['groups']) && is_array($quotaData['groups'])) {
            $groups = self::parseQuotaGroups($quotaData['groups']);
        }

        $models = [];
        $defaultAgentModelId = null;
        if (is_array($modelsData)) {
            [$models, $defaultAgentModelId] = self::parseAvailableModels($modelsData);
        }

        $planLabel = null;
        if (is_array($assistData)) {
            $planLabel = self::parsePlanLabel($assistData);
        }

        return [
            'projectId' => $resolvedProject,
            'endpoint' => $endpointUsed,
            'planLabel' => $planLabel,
            'groups' => $groups,
            'quotaError' => $quotaError,
            'models' => $models,
            'defaultAgentModelId' => $defaultAgentModelId,
        ];
    }

    /**
     * Format a summary of quota pools with progress bars.
     *
     * @param array{planLabel: ?string, groups: list<array{displayName: string, buckets: list<array{displayName: string, remainingFraction: float, resetTime: ?string}>}>, quotaError: ?string} $usage
     */
    public static function formatUsageSummary(array $usage): string
    {
        $lines = [];

        if (!empty($usage['planLabel'])) {
            $lines[] = $usage['planLabel'];
        }

        if (empty($usage['groups'])) {
            if (!empty($usage['quotaError'])) {
                $lines[] = self::quotaErrorNote($usage['quotaError']);
            } else {
                $lines[] = 'No quota groups returned.';
            }

            return implode("\n", $lines);
        }

        foreach ($usage['groups'] as $group) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = $group['displayName'];
            foreach ($group['buckets'] as $bucket) {
                $fraction = $bucket['remainingFraction'];
                $percent = round($fraction * 100, 1);
                $bar = self::progressBar($fraction);
                $reset = self::formatReset($bucket['resetTime'] ?? null);
                $lines[] = "  {$bar} {$bucket['displayName']}: {$percent}% left · resets {$reset}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format models list with remaining fractions.
     *
     * @param array{projectId?: string, defaultAgentModelId?: string, models: list<array{modelId: string, remainingFraction: ?float, resetTime: ?string}>} $usage
     */
    public static function formatModelsList(array $usage, bool $all = false): string
    {
        $lines = [];
        $lines[] = 'Antigravity available models';
        if (!empty($usage['projectId'])) {
            $lines[] = "project={$usage['projectId']}";
        }
        if (!empty($usage['defaultAgentModelId'])) {
            $lines[] = "defaultAgentModel={$usage['defaultAgentModelId']}";
        }
        $lines[] = '';

        $rows = $usage['models'] ?? [];
        if (!$all) {
            $rows = array_values(array_filter($rows, static fn (array $m): bool => !preg_match('/tab_|chat_/i', $m['modelId'] ?? '')));
        }

        if (empty($rows)) {
            $lines[] = 'No models returned.';

            return implode("\n", $lines);
        }

        foreach ($rows as $m) {
            $fraction = $m['remainingFraction'];
            $remStr = $fraction !== null ? sprintf('%5.1f%%', $fraction * 100) : '  n/a ';
            $reset = self::formatReset($m['resetTime'] ?? null);
            $bar = self::progressBar($fraction ?? 0.0, 10);
            $lines[] = sprintf('%-30s %s %s · resets %s', $m['modelId'], $bar, $remStr, $reset);
        }

        return implode("\n", $lines);
    }

    public static function progressBar(float $remaining, int $width = 20): string
    {
        $fraction = max(0.0, min(1.0, $remaining));
        $filled = (int) round($fraction * $width);
        $empty = $width - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('-', $empty) . ']';
    }

    public static function formatReset(?string $resetTime): string
    {
        if ($resetTime === null || $resetTime === '') {
            return 'n/a';
        }

        $ts = strtotime($resetTime);
        if ($ts === false) {
            return $resetTime;
        }

        $delta = $ts - time();
        if ($delta <= 0) {
            return 'now';
        }

        $totalMin = (int) round($delta / 60);
        $days = (int) floor($totalMin / (60 * 24));
        $hours = (int) floor(($totalMin % (60 * 24)) / 60);
        $mins = $totalMin % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    private static function quotaErrorNote(string $msg): string
    {
        if (preg_match('/SUBSCRIPTION_REQUIRED|#3501|(?:lack|missing).*license/i', $msg) === 1) {
            return "Aggregate quota summary needs a paid subscription (free-tier can't use that endpoint). Per-model usage is still available via /antigravity.models.";
        }

        return 'Aggregate quota summary unavailable: ' . substr($msg, 0, 160);
    }

    /**
     * @param list<string> $candidates
     * @return array{endpoint: string, data: mixed}
     */
    private function postJson(
        HttpClient $http,
        array $candidates,
        string $path,
        string $token,
        ?string $projectId,
        array $body,
    ): array {
        $lastError = 'No endpoint available';

        foreach ($candidates as $endpoint) {
            $url = rtrim($endpoint, '/') . $path;
            $headers = [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ];
            if ($projectId !== null && $projectId !== '') {
                $headers['X-Goog-User-Project'] = $projectId;
            }

            try {
                $encoded = json_encode($body) ?: '{}';
                $response = $http->send(new Request('POST', $url, $headers, $encoded));
                $text = $response->body->all();
                $data = json_decode($text, true);

                if ($response->isSuccessful()) {
                    return ['endpoint' => $endpoint, 'data' => $data ?? $text];
                }

                $errMsg = is_array($data) && isset($data['error']['message'])
                    ? (string) $data['error']['message']
                    : $text;

                $lastError = "{$path} failed ({$response->status}): " . substr($errMsg, 0, 300);

                // For fatal client errors (not 403/404/429/5xx), don't keep hammering other candidates
                if (!in_array($response->status, [403, 404, 429, 500, 502, 503, 504], true)) {
                    throw new RuntimeException($lastError);
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * @param list<string> $candidates
     */
    private function postJsonSafe(
        HttpClient $http,
        array $candidates,
        string $path,
        string $token,
        ?string $projectId,
        array $body,
    ): mixed {
        try {
            $res = $this->postJson($http, $candidates, $path, $token, $projectId, $body);

            return $res['data'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractProject(array $data): ?string
    {
        if (isset($data['cloudaistatus']['project']) && is_string($data['cloudaistatus']['project'])) {
            return $data['cloudaistatus']['project'];
        }
        if (isset($data['project']) && is_string($data['project'])) {
            return $data['project'];
        }

        return null;
    }

    /**
     * @param list<mixed> $rawGroups
     * @return list<array{displayName: string, description: ?string, buckets: list<array{bucketId: string, displayName: string, window: ?string, resetTime: ?string, description: ?string, remainingFraction: float}>}>
     */
    private static function parseQuotaGroups(array $rawGroups): array
    {
        $groups = [];

        foreach ($rawGroups as $rawGroup) {
            if (!is_array($rawGroup)) {
                continue;
            }

            $buckets = [];
            $rawBuckets = $rawGroup['buckets'] ?? [];
            if (is_array($rawBuckets)) {
                foreach ($rawBuckets as $rawBucket) {
                    if (!is_array($rawBucket)) {
                        continue;
                    }
                    $fraction = isset($rawBucket['remainingFraction']) && is_numeric($rawBucket['remainingFraction'])
                        ? (float) $rawBucket['remainingFraction']
                        : 0.0;
                    $buckets[] = [
                        'bucketId' => (string) ($rawBucket['bucketId'] ?? $rawBucket['displayName'] ?? 'unknown'),
                        'displayName' => (string) ($rawBucket['displayName'] ?? $rawBucket['bucketId'] ?? 'Limit'),
                        'window' => isset($rawBucket['window']) ? (string) $rawBucket['window'] : null,
                        'resetTime' => isset($rawBucket['resetTime']) ? (string) $rawBucket['resetTime'] : null,
                        'description' => isset($rawBucket['description']) ? (string) $rawBucket['description'] : null,
                        'remainingFraction' => max(0.0, min(1.0, $fraction)),
                    ];
                }
            }

            if ($buckets !== [] || isset($rawGroup['displayName'])) {
                $groups[] = [
                    'displayName' => (string) ($rawGroup['displayName'] ?? 'Quota group'),
                    'description' => isset($rawGroup['description']) ? (string) $rawGroup['description'] : null,
                    'buckets' => $buckets,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: list<array{modelId: string, displayName: string, remainingFraction: ?float, resetTime: ?string}>, 1: ?string}
     */
    private static function parseAvailableModels(array $data): array
    {
        $models = [];
        $rawModels = $data['models'] ?? [];

        if (is_array($rawModels)) {
            foreach ($rawModels as $modelId => $info) {
                if (!is_array($info)) {
                    continue;
                }

                $fraction = null;
                $resetTime = null;
                if (isset($info['quotaInfo']) && is_array($info['quotaInfo'])) {
                    if (isset($info['quotaInfo']['remainingFraction']) && is_numeric($info['quotaInfo']['remainingFraction'])) {
                        $fraction = max(0.0, min(1.0, (float) $info['quotaInfo']['remainingFraction']));
                    }
                    if (isset($info['quotaInfo']['resetTime']) && is_string($info['quotaInfo']['resetTime'])) {
                        $resetTime = $info['quotaInfo']['resetTime'];
                    }
                }

                $models[] = [
                    'modelId' => (string) $modelId,
                    'displayName' => (string) ($info['displayName'] ?? $modelId),
                    'remainingFraction' => $fraction,
                    'resetTime' => $resetTime,
                ];
            }
        }

        $defaultAgentModelId = isset($data['defaultAgentModelId']) && is_string($data['defaultAgentModelId'])
            ? $data['defaultAgentModelId']
            : null;

        return [$models, $defaultAgentModelId];
    }

    /**
     * @param array<string, mixed> $assistData
     */
    private static function parsePlanLabel(array $assistData): ?string
    {
        $paid = $assistData['paidTier'] ?? null;
        if (is_array($paid) && (!empty($paid['name']) || !empty($paid['id']))) {
            $name = $paid['name'] ?? $paid['id'] ?? '';
            $id = $paid['id'] ?? '';

            return $id !== '' && $id !== $name ? "{$name} ({$id})" : $name;
        }

        $current = $assistData['currentTier'] ?? null;
        if (is_array($current) && (!empty($current['name']) || !empty($current['id']))) {
            $name = $current['name'] ?? $current['id'] ?? '';
            $id = $current['id'] ?? '';

            return $id !== '' && $id !== $name ? "{$name} ({$id})" : $name;
        }

        return null;
    }
}
