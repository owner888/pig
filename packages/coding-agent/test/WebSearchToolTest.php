<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Async\Async;
use Pig\CodingAgent\Tools\FetchWebPageTool;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\CodingAgent\Tools\WebSearchTool;
use Pig\Test\CannedServer;

final class WebSearchToolTest extends TestCase
{
    public function testEmptyQueryThrowsAgentError(): void
    {
        $tool = new WebSearchTool('/tmp');
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('Query must not be empty');
        $tool->execute('call_1', ['query' => '   ']);
    }

    public function testParseHtmlResultsExtractsTitlesUrlsAndSnippets(): void
    {
        $tool = new WebSearchTool('/tmp');

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <div class="result">
                <h2 class="result__title">
                    <a class="result__url" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fdocs&rut=1">
                        <b>Example</b> Documentation
                    </a>
                </h2>
                <a class="result__snippet">This is the official documentation for the example project.</a>
            </div>
            <div class="result">
                <h2 class="result__title">
                    <a class="result__url" href="https://php.net/manual">PHP Manual</a>
                </h2>
                <a class="result__snippet">Official PHP documentation &amp; references.</a>
            </div>
        </body>
        </html>
        HTML;

        $results = $tool->parseHtmlResults($html, 5);

        $this->assertCount(2, $results);
        $this->assertSame('Example Documentation', $results[0]['title']);
        $this->assertSame('https://example.com/docs', $results[0]['url']);
        $this->assertSame('This is the official documentation for the example project.', $results[0]['snippet']);

        $this->assertSame('PHP Manual', $results[1]['title']);
        $this->assertSame('https://php.net/manual', $results[1]['url']);
        $this->assertSame('Official PHP documentation & references.', $results[1]['snippet']);
    }

    public function testFetchWebPageRequiresHttpOrHttps(): void
    {
        $tool = new FetchWebPageTool('/tmp');
        $this->expectException(AgentError::class);
        $this->expectExceptionMessage('URL must start with http:// or https://');
        $tool->execute('call_2', ['url' => 'ftp://files.example.com']);
    }

    public function testExtractCleanTextStripsScriptsStylesAndExtractsTitle(): void
    {
        $tool = new FetchWebPageTool('/tmp');

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <title>My Awesome Library - Docs</title>
            <style>body { color: red; }</style>
            <script>console.log("noisy tracking script");</script>
        </head>
        <body>
            <nav><a href="/home">Home</a></nav>
            <h1>Introduction</h1>
            <p>Welcome to <b>My Awesome Library</b>.</p>
            <p>It provides high performance async networking.</p>
            <footer>Copyright 2026</footer>
        </body>
        </html>
        HTML;

        [$title, $text] = $tool->extractCleanText($html);

        $this->assertSame('My Awesome Library - Docs', $title);
        $this->assertStringNotContainsString('noisy tracking script', $text);
        $this->assertStringNotContainsString('color: red', $text);
        $this->assertStringNotContainsString('Copyright 2026', $text);
        $this->assertStringContainsString('Introduction', $text);
        $this->assertStringContainsString('Welcome to My Awesome Library', $text);
        $this->assertStringContainsString('It provides high performance async networking.', $text);
    }

    public function testToolSetIncludesNewSearchTools(): void
    {
        $this->assertContains('web_search', ToolSet::ALL);
        $this->assertContains('fetch_web_page', ToolSet::ALL);

        $searchTool = ToolSet::one('/tmp', 'web_search');
        $this->assertInstanceOf(WebSearchTool::class, $searchTool);

        $fetchTool = ToolSet::one('/tmp', 'fetch_web_page');
        $this->assertInstanceOf(FetchWebPageTool::class, $fetchTool);
    }

    public function testFetchWebPageFetchesAndCleansContent(): void
    {
        $server = new CannedServer();
        $url = $server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n"
            . "<html><head><title>Test Title</title></head><body><h1>Heading</h1><p>Paragraph content</p></body></html>"
        ]);

        $tool = new FetchWebPageTool('/tmp');

        Async::run(function () use ($tool, $url) {
            $result = $tool->execute('call_test', ['url' => $url]);
            $this->assertNotEmpty($result->content);
            $text = $result->content[0]->text;
            $this->assertStringContainsString('# Test Title', $text);
            $this->assertStringContainsString('Heading', $text);
            $this->assertStringContainsString('Paragraph content', $text);
        });

        $server->stop();
    }
}
