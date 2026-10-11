<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\Agent\AgentToolResult;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use RuntimeException;
use Throwable;

final class GoogleSearch
{
    /** Default search model: fast grounding model for interactive tool calls. */
    public const string DEFAULT_SEARCH_MODEL = 'gemini-3.5-flash-lite';

    /** Fallback runtime candidates if the primary model is missing or out of capacity. */
    public const array SEARCH_MODEL_FALLBACKS = [
        self::DEFAULT_SEARCH_MODEL,
        'gemini-3.1-flash-lite',
        'gemini-3-flash',
    ];

    public const string SYSTEM_INSTRUCTION = "You are a careful research assistant using Google Search grounding.\nReturn a compact evidence brief with exactly these sections:\n## Findings\n## Gaps\n## Next checks\n\nRules:\n- Verify each claim inside the specific item, function, or section it refers to. Do not copy an attribute from a neighboring entry on the same page.\n- Prefer primary documentation and exact names, versions, and constraints over marketing summaries.\n- If a fact is not supported by a retrieved source, put it under Gaps instead of stating it as a finding.\n- Keep the brief short enough for an interactive agent tool call.";

    /**
     * Parse arguments for the interactive `/antigravity.search` command.
     * Supports:
     *   --thinking: enable deep reasoning for search planning
     *   --url <url>: target URL for context analysis (repeatable)
     *
     * @return array{query: string, urls: list<string>, thinking: bool}
     */
    public static function parseSearchArgs(string $args): array
    {
        $tokens = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = ['query' => '', 'urls' => [], 'thinking' => false];
        $rest = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if ($token === '--thinking') {
                $out['thinking'] = true;
                continue;
            }

            if ($token === '--url' && isset($tokens[$i + 1])) {
                $out['urls'][] = $tokens[++$i];
                continue;
            }

            $rest[] = $token;
        }

        $out['query'] = implode(' ', $rest);

        return $out;
    }

    /**
     * Builds the Antigravity wire request payload for Gemini Google Search Grounding.
     *
     * @param list<string> $urls
     * @return array<string, mixed>
     */
    public static function buildSearchRequest(
        string $query,
        ?string $instruction,
        array $urls,
        bool $thinking,
        string $model,
        string $projectId,
    ): array {
        $systemText = self::SYSTEM_INSTRUCTION;
        if ($instruction !== null && trim($instruction) !== '') {
            $trimmed = trim($instruction);
            $systemText .= "\n\n[SPECIFIC DIRECTIVE FROM LEAD AGENT]:\n{$trimmed}\nYou MUST strictly follow this directive during search query formulation and synthesis.";
        }

        $prompt = trim($query);
        if ($instruction !== null && trim($instruction) !== '') {
            $trimmed = trim($instruction);
            $prompt = "[Lead Agent Directive]: {$trimmed}\n\nSearch Query: {$prompt}";
        }
        if ($urls !== []) {
            $prompt .= "\n\nURLs to analyze in detail:\n" . implode("\n", $urls);
        }

        $tools = [['googleSearch' => new \stdClass()]];
        if ($urls !== []) {
            $tools[] = ['urlContext' => new \stdClass()];
        }

        $thinkingBudget = $thinking ? 4096 : 0;

        return [
            'project' => $projectId,
            'model' => $model,
            'request' => [
                'systemInstruction' => [
                    'role' => 'user',
                    'parts' => [['text' => $systemText]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'tools' => $tools,
                'generationConfig' => [
                    'thinkingConfig' => [
                        'thinkingBudget' => $thinkingBudget,
                        'includeThoughts' => false,
                    ],
                ],
            ],
            'requestType' => 'agent',
            'userAgent' => 'antigravity',
            'requestId' => sprintf(
                'agent/%s/%d/%s/1',
                bin2hex(random_bytes(8)),
                Timestamp::nowMs(),
                bin2hex(random_bytes(16)),
            ),
        ];
    }

    /**
     * Parse visible candidate text and grounding metadata from an Antigravity response.
     *
     * @param array<string, mixed> $data
     * @return array{
     *   text: string,
     *   sources: list<array{index?: int, title: string, url: string}>,
     *   queries: list<string>,
     *   parts?: list<array{index: int, text: string}>,
     *   supports?: list<array{text: string, startIndex?: int|null, endIndex?: int|null, partIndex?: int|null, sourceIndices: list<int>}>
     * }
     */
    public static function parseSearchResponse(array $data): array
    {
        $result = ['text' => '', 'sources' => [], 'queries' => [], 'parts' => [], 'supports' => []];
        $responseObj = is_array($data['response'] ?? null) ? $data['response'] : $data;
        $candidates = is_array($responseObj['candidates'] ?? null) ? $responseObj['candidates'] : [];
        $candidate = $candidates[0] ?? null;

        if (!is_array($candidate)) {
            $msg = $data['error']['message'] ?? $responseObj['error']['message'] ?? 'No candidate returned from Antigravity Search';
            $result['text'] = "Error: {$msg}";

            return $result;
        }

        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : null;
        if (is_array($content['parts'] ?? null)) {
            foreach ($content['parts'] as $index => $part) {
                if (is_array($part) && !($part['thought'] ?? false) && is_string($part['text'] ?? null) && $part['text'] !== '') {
                    $result['parts'][] = ['index' => $index, 'text' => $part['text']];
                }
            }
            $result['text'] = implode("\n\n", array_column($result['parts'], 'text'));
        }

        $grounding = is_array($candidate['groundingMetadata'] ?? null) ? $candidate['groundingMetadata'] : null;
        if ($grounding !== null) {
            if (is_array($grounding['webSearchQueries'] ?? null)) {
                foreach ($grounding['webSearchQueries'] as $q) {
                    if (is_string($q)) {
                        $result['queries'][] = $q;
                    }
                }
            }

            if (is_array($grounding['groundingChunks'] ?? null)) {
                foreach ($grounding['groundingChunks'] as $index => $chunk) {
                    if (is_array($chunk) && is_array($chunk['web'] ?? null) && is_string($chunk['web']['uri'] ?? null)) {
                        $title = is_string($chunk['web']['title'] ?? null) && trim($chunk['web']['title']) !== ''
                            ? trim($chunk['web']['title'])
                            : $chunk['web']['uri'];
                        $result['sources'][] = [
                            'index' => $index,
                            'title' => $title,
                            'url' => $chunk['web']['uri'],
                        ];
                    }
                }
            }

            if (is_array($grounding['groundingSupports'] ?? null)) {
                $sourceIndices = [];
                foreach ($result['sources'] as $src) {
                    if (isset($src['index'])) {
                        $sourceIndices[$src['index']] = true;
                    }
                }
                $textPartIndices = [];
                foreach ($result['parts'] as $p) {
                    $textPartIndices[$p['index']] = true;
                }

                foreach ($grounding['groundingSupports'] as $support) {
                    if (!is_array($support) || !is_array($support['segment'] ?? null)) {
                        continue;
                    }
                    $segText = $support['segment']['text'] ?? null;
                    $chunkIndices = $support['groundingChunkIndices'] ?? null;
                    if (!is_string($segText) || !is_array($chunkIndices)) {
                        continue;
                    }
                    $partIndex = $support['segment']['partIndex'] ?? 0;
                    if (!isset($textPartIndices[$partIndex])) {
                        continue;
                    }
                    $validIndices = [];
                    foreach ($chunkIndices as $ci) {
                        if (is_int($ci) && isset($sourceIndices[$ci])) {
                            $validIndices[] = $ci;
                        }
                    }
                    if ($validIndices === []) {
                        continue;
                    }
                    $result['supports'][] = [
                        'text' => $segText,
                        'startIndex' => is_int($support['segment']['startIndex'] ?? null) ? $support['segment']['startIndex'] : null,
                        'endIndex' => is_int($support['segment']['endIndex'] ?? null) ? $support['segment']['endIndex'] : null,
                        'partIndex' => is_int($support['segment']['partIndex'] ?? null) ? $support['segment']['partIndex'] : null,
                        'sourceIndices' => $validIndices,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Google's offsets are UTF-8 bytes within a content Part, not JS character indices.
     *
     * @param array<string, mixed> $res
     */
    public static function citeSearchText(array $res): string
    {
        if (empty($res['supports'])) {
            return (string) ($res['text'] ?? '');
        }

        $textParts = !empty($res['parts']) ? $res['parts'] : [['index' => 0, 'text' => (string) ($res['text'] ?? '')]];
        if (implode("\n\n", array_column($textParts, 'text')) !== ($res['text'] ?? '')) {
            return (string) ($res['text'] ?? '');
        }

        $sourceIndices = [];
        foreach ($res['sources'] ?? [] as $idx => $s) {
            $sourceIndices[$s['index'] ?? $idx] = true;
        }

        $citedParts = [];
        foreach ($textParts as $part) {
            $bytes = (string) $part['text'];
            $byteLen = strlen($bytes);
            $insertions = [];

            foreach ($res['supports'] ?? [] as $support) {
                $start = $support['startIndex'] ?? 0;
                $end = $support['endIndex'] ?? null;
                $partIndex = $support['partIndex'] ?? 0;

                if ($partIndex !== $part['index']
                    || $end === null
                    || $start < 0
                    || $end <= $start
                    || $end > $byteLen
                    || substr($bytes, $start, $end - $start) !== $support['text']
                ) {
                    continue;
                }

                $insertions[$end] ??= [];
                foreach ($support['sourceIndices'] as $si) {
                    if (isset($sourceIndices[$si])) {
                        $insertions[$end][$si] = true;
                    }
                }
            }

            krsort($insertions, SORT_NUMERIC);
            foreach ($insertions as $pos => $indicesMap) {
                $refs = '';
                foreach (array_keys($indicesMap) as $idx) {
                    $refs .= '[' . ($idx + 1) . ']';
                }
                $bytes = substr($bytes, 0, $pos) . $refs . substr($bytes, $pos);
            }

            $citedParts[] = $bytes;
        }

        return implode("\n\n", $citedParts) ?: (string) ($res['text'] ?? '');
    }

    /**
     * @param array<string, mixed> $res
     */
    public static function formatSearchResult(array $res): string
    {
        $sections = [];
        $cited = self::citeSearchText($res);
        if ($cited !== '') {
            $sections[] = $cited;
        }

        if (!empty($res['sources'])) {
            $hasSupports = !empty($res['supports']);
            $list = [];
            foreach ($res['sources'] as $index => $source) {
                $prefix = $hasSupports ? '[' . (($source['index'] ?? $index) + 1) . '] ' : '';
                $list[] = "- {$prefix}[{$source['title']}]({$source['url']})";
            }
            $sections[] = "### Sources\n" . implode("\n", $list);
        }

        if (!empty($res['queries'])) {
            $queries = array_map(static fn (string $q): string => "`{$q}`", $res['queries']);
            $sections[] = '*Search queries: ' . implode(', ', $queries) . '*';
        }

        return implode("\n\n", $sections);
    }

    /**
     * Execute real-time Google Search grounding via Antigravity backend with automatic fallback.
     *
     * @param list<string> $urls
     */
    public static function executeSearch(
        HttpClient $http,
        string $token,
        string $projectId,
        string $query,
        ?string $instruction = null,
        array $urls = [],
        bool $thinking = false,
        ?AbortSignal $signal = null,
        ?array $endpoints = null,
    ): string {
        $trimmed = trim($query);
        if ($trimmed === '') {
            throw new RuntimeException('Search query is required.');
        }

        $candidates = $endpoints ?? [
            'https://daily-cloudcode-pa.googleapis.com',
            'https://daily-cloudcode-pa.sandbox.googleapis.com',
            'https://cloudcode-pa.googleapis.com',
        ];

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'User-Agent' => 'antigravity',
        ];

        $lastError = 'No endpoint available';

        foreach (self::SEARCH_MODEL_FALLBACKS as $model) {
            $body = self::buildSearchRequest($trimmed, $instruction, $urls, $thinking, $model, $projectId);
            $json = json_encode($body) ?: '{}';

            foreach ($candidates as $endpoint) {
                if ($signal?->aborted() ?? false) {
                    throw new RuntimeException('Search request was aborted');
                }

                try {
                    $url = rtrim($endpoint, '/') . '/v1internal:generateContent';
                    $response = $http->send(new Request('POST', $url, $headers, $json), $signal);

                    if (!$response->isSuccessful()) {
                        $errText = $response->body->all();
                        $lastError = AntigravityApi::friendlyAntigravityError($response->status, $errText);

                        if ($response->status === 404 || in_array($response->status, [429, 500, 502, 503, 504], true)) {
                            continue;
                        }

                        throw new RuntimeException($lastError);
                    }

                    $data = json_decode($response->body->all(), true);
                    if (!is_array($data)) {
                        $lastError = 'Invalid JSON response from Antigravity Search';
                        continue;
                    }

                    $cand = $data['response']['candidates'][0] ?? $data['candidates'][0] ?? null;
                    $candText = $cand['content']['parts'][0]['text'] ?? null;
                    if (is_string($candText) && str_contains($candText, 'is no longer available. Please switch')) {
                        $lastError = "Model {$model} deprecated";
                        break;
                    }

                    $parsed = self::parseSearchResponse($data);

                    return self::formatSearchResult($parsed);
                } catch (Throwable $e) {
                    if ($signal?->aborted() ?? false) {
                        throw $e;
                    }
                    $lastError = $e->getMessage();
                }
            }
        }

        throw new RuntimeException("Antigravity Google Search failed: {$lastError}");
    }
}
