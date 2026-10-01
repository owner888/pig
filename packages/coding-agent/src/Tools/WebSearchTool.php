<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Pure PHP web search agent tool powered by DuckDuckGo HTML scraping.
 *
 * Zero external dependencies, zero API keys, fully asynchronous via HttpClient.
 */
final class WebSearchTool implements AgentTool
{
    private const string ENDPOINT = 'https://html.duckduckgo.com/html/';

    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

    public function __construct(
        private readonly string $cwd,
        private readonly ?HttpClient $http = null,
    ) {
    }

    #[\Override]
    public function definition(): Tool
    {
        return new Tool(
            'web_search',
            'Search the web for real-time information, documentation, news, or technical references using DuckDuckGo. '
                . 'Returns top search results with titles, snippets, and source URLs.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query (e.g. "laravel 11 releases", "anthropic adaptive thinking api doc")',
                    ],
                    'maxResults' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results to return (default 5, max 10)',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Web Search';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            throw new AgentError('Query must not be empty');
        }

        $maxResults = max(1, min(10, (int) ($arguments['maxResults'] ?? 5)));

        $http = $this->http ?? new HttpClient(15.0);
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];

        $request = new Request(
            'POST',
            self::ENDPOINT,
            $headers,
            'q=' . urlencode($query),
        );

        try {
            $response = $http->follow($request, $signal);
        } catch (Throwable $e) {
            throw new AgentError("Web search failed for '{$query}': " . $e->getMessage());
        }

        if ($response->status !== 200) {
            throw new AgentError("DuckDuckGo returned HTTP status {$response->status}");
        }

        $html = $response->body->all();
        $results = $this->parseHtmlResults($html, $maxResults);

        if ($results === []) {
            return new AgentToolResult(
                [new TextContent("No search results found for \"{$query}\". Try refined keywords.")],
                ['query' => $query, 'results' => []],
            );
        }

        $markdown = "Search results for \"{$query}\":\n\n";
        foreach ($results as $i => $item) {
            $num = $i + 1;
            $markdown .= "{$num}. [{$item['title']}]({$item['url']})\n";
            if ($item['snippet'] !== '') {
                $markdown .= "   {$item['snippet']}\n";
            }
            $markdown .= "\n";
        }

        return new AgentToolResult(
            [new TextContent(trim($markdown))],
            ['query' => $query, 'results' => $results],
        );
    }

    /**
     * Parse raw DuckDuckGo HTML into structured search items.
     *
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public function parseHtmlResults(string $html, int $limit): array
    {
        $results = [];

        // Match result blocks: DuckDuckGo HTML puts titles in h2.result__title a and snippets in a.result__snippet
        preg_match_all(
            '#<h2[^>]*class="[^"]*result__title[^"]*"[^>]*>.*?<a[^>]*class="[^"]*result__url[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is',
            $html,
            $titles,
        );

        preg_match_all(
            '#<a[^>]*class="[^"]*result__snippet[^"]*"[^>]*>(.*?)</a>#is',
            $html,
            $snippets,
        );

        $urls = $titles[1] ?? [];
        $rawTitles = $titles[2] ?? [];
        $rawSnippets = $snippets[1] ?? [];

        $count = min(count($urls), $limit);

        for ($i = 0; $i < $count; $i++) {
            $rawUrl = $urls[$i];
            $cleanUrl = $this->cleanUrl($rawUrl);
            $cleanTitle = html_entity_decode(trim(strip_tags($rawTitles[$i] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanSnippet = html_entity_decode(trim(strip_tags($rawSnippets[$i] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($cleanUrl !== '' && !str_starts_with($cleanUrl, 'javascript:')) {
                $results[] = [
                    'title' => $cleanTitle !== '' ? $cleanTitle : $cleanUrl,
                    'url' => $cleanUrl,
                    'snippet' => $cleanSnippet,
                ];
            }
        }

        return $results;
    }

    /**
     * DuckDuckGo wraps target URLs in /l/?uddg=... redirect links; extract the destination.
     */
    private function cleanUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parsed = parse_url($url);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['uddg']) && is_string($query['uddg'])) {
                return $query['uddg'];
            }
        }

        return $url;
    }
}
