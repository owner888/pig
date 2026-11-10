<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use PigAntigravity\GoogleSearch;
use Pig\Test\CannedServer;

final class AntigravitySearchTest extends TestCase
{
    use LoadsAntigravity;

    #[\Override]
    protected function setUp(): void
    {
        self::loadAntigravity();
    }

    public function testParseSearchArgsExtractsQueryUrlsAndThinkingFlag(): void
    {
        $parsed = GoogleSearch::parseSearchArgs('--thinking --url https://php.net/manual --url https://github.com/owner888/pig php 8.4 property hooks');

        $this->assertTrue($parsed['thinking']);
        $this->assertSame(['https://php.net/manual', 'https://github.com/owner888/pig'], $parsed['urls']);
        $this->assertSame('php 8.4 property hooks', $parsed['query']);

        $plain = GoogleSearch::parseSearchArgs('laravel 12 releases');
        $this->assertNull($plain['thinking'], 'unsaid, which is not the same as off');
        $this->assertSame([], $plain['urls']);
        $this->assertSame('laravel 12 releases', $plain['query']);
    }

    public function testBuildSearchRequestFormatsToolsSystemInstructionAndBudget(): void
    {
        $req = GoogleSearch::buildSearchRequest(
            query: 'php fiber',
            instruction: 'focus on event loop integration',
            urls: ['https://www.php.net/fiber'],
            thinking: true,
            model: GoogleSearch::DEFAULT_SEARCH_MODEL,
            projectId: 'test-project-1',
        );

        $this->assertSame('test-project-1', $req['project']);
        $this->assertSame('gemini-3.5-flash-lite', $req['model']);
        $this->assertSame('agent', $req['requestType']);

        $tools = $req['request']['tools'];
        $this->assertCount(2, $tools);
        $this->assertInstanceOf(\stdClass::class, $tools[0]['googleSearch']);
        $this->assertInstanceOf(\stdClass::class, $tools[1]['urlContext']);

        $this->assertSame(4096, $req['request']['generationConfig']->thinkingConfig['thinkingBudget']);
        $this->assertFalse($req['request']['generationConfig']->thinkingConfig['includeThoughts']);

        $promptText = $req['request']['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('focus on event loop integration', $promptText);
        $this->assertStringContainsString('https://www.php.net/fiber', $promptText);

        // Nobody said: a small budget on a model that is not a Lite one, and no config at all on a
        // Lite one — never the 0 the Lite models answer with a 400.
        $fastReq = GoogleSearch::buildSearchRequest('quick search', null, [], null, 'gemini-3-flash', 'p2');
        $this->assertSame(2048, $fastReq['request']['generationConfig']->thinkingConfig['thinkingBudget']);
        $this->assertCount(1, $fastReq['request']['tools']);

        foreach ([[null, GoogleSearch::DEFAULT_SEARCH_MODEL], [false, GoogleSearch::DEFAULT_SEARCH_MODEL], [false, 'gemini-3-flash']] as [$thinking, $model]) {
            $unset = GoogleSearch::buildSearchRequest('quick search', null, [], $thinking, $model, 'p2');
            $this->assertStringContainsString('"generationConfig":{}', (string) json_encode($unset), 'an object, and nothing in it');
        }
    }

    public function testAModelThatRefusesTheArgumentsIsPassedOverAndARefusalOfTheAccountIsNot(): void
    {
        $refusal = static function (int $status, string $message): string {
            $body = (string) json_encode(['error' => ['message' => $message]]);

            return "HTTP/1.1 {$status} Refused\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        };
        $answer = (string) json_encode(['response' => ['candidates' => [['content' => ['parts' => [['text' => 'found it']]]]]]]);
        $http = new HttpClient(5.0);

        // pi-antigravity 0.11: a 400 about the arguments is that model's; the next fallback is asked.
        $server = new CannedServer();
        $url = $server->startSequence([
            [$refusal(400, 'Request contains an invalid argument.')],
            ["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($answer) . "\r\n\r\n" . $answer],
        ]);
        $result = \Pig\Async\Async::run(static fn (): string => GoogleSearch::executeSearch($http, 't', 'p', 'q', endpoints: [$url]));

        $this->assertStringContainsString('found it', $result);
        $this->assertSame(2, $server->connections());
        $this->assertStringContainsString('"model":"' . GoogleSearch::SEARCH_MODEL_FALLBACKS[0] . '"', $server->received());
        $this->assertStringContainsString('"model":"' . GoogleSearch::SEARCH_MODEL_FALLBACKS[1] . '"', $server->received());
        $this->assertStringContainsStringIgnoringCase('user-agent: antigravity/cli/1.4.0 ', $server->receivedHead());

        // A refusal of the account fails at once: neither the other endpoint nor another model.
        $denied = new CannedServer();
        $deniedUrl = $denied->startSequence([[$refusal(401, 'nope')], [$refusal(401, 'nope')]]);

        try {
            \Pig\Async\Async::run(static fn (): string => GoogleSearch::executeSearch($http, 't', 'p', 'q', endpoints: [$deniedUrl, $deniedUrl]));
            $this->fail('a 401 should end the search');
        } catch (\RuntimeException $error) {
            $this->assertSame('Antigravity authentication failed. Next: run /login antigravity, then retry.', $error->getMessage());
        }

        $this->assertSame(1, $denied->connections());
    }

    public function testParseSearchResponseExtractsTextQueriesSourcesAndSupports(): void
    {
        $raw = [
            'response' => [
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['thought' => true, 'text' => 'internal reasoning'],
                                ['text' => 'PHP 8.4 introduces asymmetric visibility.'],
                            ],
                        ],
                        'groundingMetadata' => [
                            'webSearchQueries' => ['php 8.4 asymmetric visibility'],
                            'groundingChunks' => [
                                [
                                    'web' => [
                                        'uri' => 'https://wiki.php.net/rfc/asymmetric-visibility-v2',
                                        'title' => 'PHP: rfc:asymmetric-visibility-v2',
                                    ],
                                ],
                            ],
                            'groundingSupports' => [
                                [
                                    'segment' => [
                                        'startIndex' => 0,
                                        'endIndex' => 42,
                                        'text' => 'PHP 8.4 introduces asymmetric visibility.',
                                        'partIndex' => 1,
                                    ],
                                    'groundingChunkIndices' => [0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $parsed = GoogleSearch::parseSearchResponse($raw);

        $this->assertSame('PHP 8.4 introduces asymmetric visibility.', $parsed['text']);
        $this->assertSame(['php 8.4 asymmetric visibility'], $parsed['queries']);
        $this->assertCount(1, $parsed['sources']);
        $this->assertSame('PHP: rfc:asymmetric-visibility-v2', $parsed['sources'][0]['title']);
        $this->assertSame('https://wiki.php.net/rfc/asymmetric-visibility-v2', $parsed['sources'][0]['url']);
        $this->assertCount(1, $parsed['supports']);

        $formatted = GoogleSearch::formatSearchResult($parsed);
        $this->assertStringContainsString('[1]', $formatted);
        $this->assertStringContainsString('### Sources', $formatted);
        $this->assertStringContainsString('https://wiki.php.net/rfc/asymmetric-visibility-v2', $formatted);
        $this->assertStringContainsString('*Search queries: `php 8.4 asymmetric visibility`*', $formatted);
    }

    public function testExecuteSearchReturnsFormattedMarkdownFromCannedServer(): void
    {
        $server = new CannedServer();
        $payload = [
            'response' => [
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => "## Findings\nPig is a pure PHP coding agent.\n\n## Gaps\nNone.\n\n## Next checks\nCheck documentation."],
                            ],
                        ],
                        'groundingMetadata' => [
                            'webSearchQueries' => ['pig coding agent php'],
                            'groundingChunks' => [
                                [
                                    'web' => [
                                        'uri' => 'https://pigagent.dev',
                                        'title' => 'Pig — Minimal Coding Agent in PHP',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $url = $server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . json_encode($payload)]);

        $http = new HttpClient(5.0);
        $result = \Pig\Async\Async::run(static fn (): string => GoogleSearch::executeSearch($http, 'test-token', 'test-proj', 'pig coding agent', endpoints: [$url]));

        $this->assertStringContainsString('## Findings', $result);
        $this->assertStringContainsString('Pig is a pure PHP coding agent.', $result);
        $this->assertStringContainsString('### Sources', $result);
        $this->assertStringContainsString('https://pigagent.dev', $result);
        $this->assertStringContainsString('*Search queries: `pig coding agent php`*', $result);

        $server->stop();
    }
}
