<?php

declare(strict_types=1);

use Pig\Agent\AgentToolResult;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use PigWebSearch\HeadlessBrowser;

// A class file beside the entry. Guarded by *class* and not by `require_once`, which dedups by
// path: a global copy under `~/.pig/agent/extensions` and a project copy are two paths holding
// one class, and the second `require` of either is a fatal redeclaration. Whichever loaded first
// serves both; the entry files are deduplicated by name upstream of this anyway.
if (!class_exists(HeadlessBrowser::class, false)) {
    require __DIR__ . '/HeadlessBrowser.php';
}

/**
 * Pure PHP web search and web page reading extension for pig.
 *
 * Provides real-time documentation retrieval and web content fetching
 * with zero external dependencies and zero API keys.
 */
return function (ExtensionApi $pi): void {
    $cleanDuckDuckGoUrl = static function (string $url): string {
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
    };

    $parseDuckDuckGoHtml = static function (string $html, int $limit) use ($cleanDuckDuckGoUrl): array {
        $results = [];

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
            $cleanUrl = $cleanDuckDuckGoUrl($urls[$i]);
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
    };

    $extractCleanText = static function (string $html): array {
        $title = 'Web Page';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $stripTags = ['script', 'style', 'svg', 'noscript', 'nav', 'header', 'footer', 'iframe', 'canvas'];
        foreach ($stripTags as $tag) {
            $html = preg_replace("#<{$tag}[^>]*>.*?</{$tag}>#is", ' ', $html) ?? $html;
        }

        $blockTags = ['p', 'div', 'article', 'section', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr'];
        foreach ($blockTags as $tag) {
            $html = preg_replace("#<{$tag}[^>]*>#i", "\n", $html) ?? $html;
            $html = preg_replace("#</{$tag}>#i", "\n", $html) ?? $html;
        }

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = explode("\n", $text);
        $cleanLines = [];
        $lastEmpty = false;

        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? '');
            if ($trimmed === '') {
                if (!$lastEmpty) {
                    $cleanLines[] = '';
                    $lastEmpty = true;
                }
            } else {
                $cleanLines[] = $trimmed;
                $lastEmpty = false;
            }
        }

        return [$title, implode("\n", $cleanLines)];
    };

    // 1. Tool: web_search
    $pi->registerTool(new CustomTool(
        name: 'web_search',
        label: 'Web Search',
        description: 'Search the web for real-time information, documentation, news, or technical references using DuckDuckGo. '
            . 'Returns top search results with titles, snippets, and source URLs.',
        parameters: [
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
        execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($parseDuckDuckGoHtml): AgentToolResult {
            $signal?->throwIfAborted();

            $query = trim((string) ($params['query'] ?? ''));
            if ($query === '') {
                throw new \InvalidArgumentException('Query must not be empty');
            }

            $maxResults = max(1, min(10, (int) ($params['maxResults'] ?? 5)));

            $http = new HttpClient(15.0);
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept-Language' => 'en-US,en;q=0.9',
            ];

            $request = new Request(
                'POST',
                'https://html.duckduckgo.com/html/',
                $headers,
                'q=' . urlencode($query),
            );

            try {
                $response = $http->follow($request, $signal);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Web search failed for '{$query}': " . $e->getMessage());
            }

            if ($response->status !== 200) {
                throw new \RuntimeException("DuckDuckGo returned HTTP status {$response->status}");
            }

            $html = $response->body->all();
            $results = $parseDuckDuckGoHtml($html, $maxResults);

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
        },
    ));

    // 2. Tool: fetch_web_page
    $pi->registerTool(new CustomTool(
        name: 'fetch_web_page',
        label: 'Fetch Web Page',
        description: 'Fetch the readable text content of a web page by URL, stripped of scripts, navigation, and styles. '
            . 'Useful for reading documentation, API references, or articles found via web_search. '
            . 'Reads what the server sends: if the result is empty or a "enable JavaScript" shell, use browse_web_page instead.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The full HTTP or HTTPS URL of the page to fetch',
                ],
                'maxLength' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters of text to return (default 30000, max 60000)',
                ],
            ],
            'required' => ['url'],
        ],
        execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($extractCleanText): AgentToolResult {
            $signal?->throwIfAborted();

            $url = trim((string) ($params['url'] ?? ''));
            if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
                throw new \InvalidArgumentException("Invalid web page URL '{$url}'. URL must start with http:// or https://");
            }

            $maxLength = max(1000, min(60000, (int) ($params['maxLength'] ?? 30000)));

            $http = new HttpClient(15.0);
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ];

            try {
                $response = $http->follow(new Request('GET', $url, $headers), $signal);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Failed to fetch '{$url}': " . $e->getMessage());
            }

            if ($response->status < 200 || $response->status >= 400) {
                throw new \RuntimeException("Web server responded with HTTP status {$response->status} for {$url}");
            }

            $rawBody = $response->body->all();
            $contentType = strtolower($response->header('content-type') ?? '');

            if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/plain')) {
                $text = $rawBody;
                $title = basename(parse_url($url, PHP_URL_PATH) ?: 'document');
            } else {
                [$title, $text] = $extractCleanText($rawBody);
            }

            $truncated = false;
            if (mb_strlen($text) > $maxLength) {
                $text = mb_substr($text, 0, $maxLength) . "\n\n... [Content truncated at {$maxLength} characters]";
                $truncated = true;
            }

            $output = "# {$title}\nSource: {$url}\n\n" . trim($text);

            return new AgentToolResult(
                [new TextContent($output)],
                ['url' => $url, 'title' => $title, 'truncated' => $truncated],
            );
        },
    ));

    // 3. Tool: browse_web_page — the same page, rendered by the Chrome on this machine
    $pi->registerTool(new CustomTool(
        name: 'browse_web_page',
        label: 'Browse Web Page',
        description: 'Render a web page in a headless Chrome and return the text of the live DOM after its JavaScript has run. '
            . 'For single-page apps, dashboards and docs sites that fetch_web_page returns empty or as a loading shell. '
            . 'Slower than fetch_web_page (a few seconds) and needs Chrome installed; prefer fetch_web_page for plain pages.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The full HTTP or HTTPS URL of the page to render',
                ],
                'maxLength' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters of text to return (default 30000, max 60000)',
                ],
                'waitSeconds' => [
                    'type' => 'number',
                    'description' => 'How long to wait for the page to load before reading it (default 25, max 60)',
                ],
            ],
            'required' => ['url'],
        ],
        execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal): AgentToolResult {
            $signal?->throwIfAborted();

            $url = trim((string) ($params['url'] ?? ''));
            if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
                throw new \InvalidArgumentException("Invalid web page URL '{$url}'. URL must start with http:// or https://");
            }

            $maxLength = max(1000, min(60000, (int) ($params['maxLength'] ?? 30000)));
            $wait = max(1.0, min(60.0, (float) ($params['waitSeconds'] ?? 25.0)));

            if ($onUpdate !== null) {
                $onUpdate(new AgentToolResult([new TextContent("Starting Chrome and loading {$url}…")]));
            }

            $browser = HeadlessBrowser::launch($signal);

            try {
                $page = $browser->read($url, $wait, $signal);
            } finally {
                $browser->close();
            }

            $text = $page['text'];
            $truncated = false;

            if (mb_strlen($text) > $maxLength) {
                $text = mb_substr($text, 0, $maxLength) . "\n\n... [Content truncated at {$maxLength} characters]";
                $truncated = true;
            }

            $title = $page['title'] !== '' ? $page['title'] : 'Web Page';
            $output = "# {$title}\nSource: {$page['url']}\nRendered by: headless Chrome\n\n" . ($text !== '' ? $text : '(the page rendered no visible text)');

            return new AgentToolResult(
                [new TextContent($output)],
                ['url' => $page['url'], 'title' => $title, 'truncated' => $truncated, 'rendered' => true],
            );
        },
    ));

    // 4. Command: /search <query>
    $pi->registerCommand(
        'search',
        static function (string $query, HookContext $ctx) use ($pi): void {
            $query = trim($query);
            if ($query === '') {
                if ($ctx->hasUi) {
                    $ctx->ui->notify('Usage: /search <query>', 'warning');
                } else {
                    echo "Usage: /search <query>\n";
                }
                return;
            }

            if ($ctx->hasUi) {
                $ctx->ui->notify("Searching web for \"{$query}\"…", 'info');
            }

            $tool = $pi->tool('web_search');
            if ($tool === null) {
                return;
            }

            $res = $tool->execute('cmd_search', ['query' => $query, 'maxResults' => 5], $ctx->signal);
            $text = $res->content[0]->text ?? 'No results.';

            if ($ctx->hasUi) {
                $ctx->ui->notify($text, 'info');
            } else {
                echo $text, "\n";
            }
        },
        description: 'Search the web using DuckDuckGo: /search <query>',
    );
};
