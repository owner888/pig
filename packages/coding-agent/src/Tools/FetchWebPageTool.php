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
 * Fetch a web page by URL, stripped of scripts, styles, and navigational chrome.
 *
 * Provides deep reading capability for technical documentation and articles.
 */
final class FetchWebPageTool implements AgentTool
{
    private const int DEFAULT_MAX_LENGTH = 30000;
    private const int ABSOLUTE_MAX_LENGTH = 60000;

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
            'fetch_web_page',
            'Fetch the readable text content of a web page by URL, stripped of scripts, navigation, and styles. '
                . 'Useful for reading documentation, API references, or articles found via web_search.',
            [
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
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Fetch Web Page';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $url = trim((string) ($arguments['url'] ?? ''));
        if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            throw new AgentError("Invalid web page URL '{$url}'. URL must start with http:// or https://");
        }

        $maxLength = max(1000, min(self::ABSOLUTE_MAX_LENGTH, (int) ($arguments['maxLength'] ?? self::DEFAULT_MAX_LENGTH)));

        $http = $this->http ?? new HttpClient(15.0);
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];

        try {
            $response = $http->follow(new Request('GET', $url, $headers), $signal);
        } catch (Throwable $e) {
            throw new AgentError("Failed to fetch '{$url}': " . $e->getMessage());
        }

        if ($response->status < 200 || $response->status >= 400) {
            throw new AgentError("Web server responded with HTTP status {$response->status} for {$url}");
        }

        $rawBody = $response->body->all();
        $contentType = strtolower($response->header('content-type') ?? '');

        // If JSON or plain text, return directly
        if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/plain')) {
            $text = $rawBody;
            $title = basename(parse_url($url, PHP_URL_PATH) ?: 'document');
        } else {
            [$title, $text] = $this->extractCleanText($rawBody);
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
    }

    /**
     * @return array{0: string, 1: string} [title, cleanText]
     */
    public function extractCleanText(string $html): array
    {
        $title = 'Web Page';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 1. Remove non-content tags completely
        $stripTags = ['script', 'style', 'svg', 'noscript', 'nav', 'header', 'footer', 'iframe', 'canvas'];
        foreach ($stripTags as $tag) {
            $html = preg_replace("#<{$tag}[^>]*>.*?</{$tag}>#is", ' ', $html) ?? $html;
        }

        // 2. Convert common semantic block elements into spacing
        $blockTags = ['p', 'div', 'article', 'section', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr'];
        foreach ($blockTags as $tag) {
            $html = preg_replace("#<{$tag}[^>]*>#i", "\n", $html) ?? $html;
            $html = preg_replace("#</{$tag}>#i", "\n", $html) ?? $html;
        }

        // 3. Strip remaining tags and decode HTML entities
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Normalize spaces: collapse multiple empty lines into at most two newlines
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
    }
}
