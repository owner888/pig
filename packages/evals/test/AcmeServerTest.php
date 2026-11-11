<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Evals\AcmeServer;

/**
 * Ported from upstream's `packages/evals/test/acme-server.test.ts`.
 */
final class AcmeServerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function fixtures(): array
    {
        return [
            'openai' => [[
                'mode' => 'openai',
                'url' => static fn (AcmeServer $server): string => "{$server->baseUrl()}/chat/completions",
                'headers' => ['authorization' => 'Bearer resolved-acme-key'],
                'unauthorizedHeaders' => ['authorization' => 'Bearer wrong-key'],
                'model' => AcmeServer::OPENAI_MODEL_ID,
                'prompt' => AcmeServer::OPENAI_PROBE_PROMPT,
                'response' => AcmeServer::OPENAI_PROBE_RESPONSE,
                'contentType' => 'text/event-stream',
                'unauthorized' => 'Invalid Acme credential',
                'invalid' => 'Invalid OpenAI-compatible request',
            ]],
            'stream' => [[
                'mode' => 'stream',
                'url' => static fn (AcmeServer $server): string => "{$server->origin()}/generate",
                'headers' => ['x-acme-key' => 'resolved-stream-key'],
                'unauthorizedHeaders' => ['x-acme-key' => 'wrong-key'],
                'model' => AcmeServer::STREAM_MODEL_ID,
                'prompt' => AcmeServer::STREAM_PROBE_PROMPT,
                'response' => AcmeServer::STREAM_PROBE_RESPONSE,
                'contentType' => 'application/x-ndjson',
                'unauthorized' => 'Invalid Acme Stream credential',
                'invalid' => 'Invalid Acme Stream request',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtures')]
    public function testAcceptsTheProbeRequestAndRecordsIt(array $fixture): void
    {
        $server = AcmeServer::create($fixture['mode']);
        $server->start();

        try {
            $url = ($fixture['url'])($server);
            [$status, $headers, $raw] = $this->post($url, $fixture['headers'], self::body($fixture['model'], $fixture['prompt']));

            $this->assertSame(200, $status);
            $this->assertStringContainsString($fixture['contentType'], $headers['content-type'] ?? '');
            $this->assertSame($fixture['response'], self::streamedText($fixture['mode'], $raw));
            $this->assertTrue($server->validRequestReceived());
        } finally {
            $server->stop();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtures')]
    public function testRejectsABadCredentialWith401AndDoesNotRecordAProbe(array $fixture): void
    {
        $server = AcmeServer::create($fixture['mode']);
        $server->start();

        try {
            $url = ($fixture['url'])($server);
            [$status, , $raw] = $this->post($url, $fixture['unauthorizedHeaders'], self::body($fixture['model'], $fixture['prompt']));

            $this->assertSame(401, $status);
            $this->assertSame($fixture['unauthorized'], $raw);
            $this->assertFalse($server->validRequestReceived());
        } finally {
            $server->stop();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtures')]
    public function testRejectsAMalformedRequestWith422AndDoesNotRecordAProbe(array $fixture): void
    {
        $server = AcmeServer::create($fixture['mode']);
        $server->start();

        try {
            $url = ($fixture['url'])($server);
            [$status, , $raw] = $this->post($url, $fixture['headers'], self::body($fixture['model'], $fixture['prompt'], false));

            $this->assertSame(422, $status);
            $this->assertSame($fixture['invalid'], $raw);
            $this->assertFalse($server->validRequestReceived());
        } finally {
            $server->stop();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtures')]
    public function testDoesNotTreatANonProbeSuccessAsTheProbe(array $fixture): void
    {
        $server = AcmeServer::create($fixture['mode']);
        $server->start();

        try {
            $url = ($fixture['url'])($server);
            [$status] = $this->post($url, $fixture['headers'], self::body($fixture['model'], 'hello'));

            $this->assertSame(200, $status);
            $this->assertFalse($server->validRequestReceived());
        } finally {
            $server->stop();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('fixtures')]
    public function testClearsTheProbeFlagOnReset(array $fixture): void
    {
        $server = AcmeServer::create($fixture['mode']);
        $server->start();

        try {
            $url = ($fixture['url'])($server);
            $this->post($url, $fixture['headers'], self::body($fixture['model'], $fixture['prompt']));
            $this->assertTrue($server->validRequestReceived());

            $server->reset();
            $this->assertFalse($server->validRequestReceived());
        } finally {
            $server->stop();
        }
    }

    /**
     * @return array{model: string, messages: list<array{role: string, content: string}>, stream: bool}
     */
    private static function body(string $model, string $prompt, bool $stream = true): array
    {
        return [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => $stream,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function post(string $url, array $headers, array $payload): array
    {
        return Async::run(static function () use ($url, $headers, $payload): array {
            $client = new HttpClient();
            $response = $client->send(new Request(
                'POST',
                $url,
                ['content-type' => 'application/json', ...$headers],
                json_encode($payload, JSON_THROW_ON_ERROR),
            ));

            return [$response->status, $response->headers, $response->body->all()];
        });
    }

    private static function streamedText(string $mode, string $raw): string
    {
        if ($mode === 'stream') {
            $text = '';

            foreach (explode("\n", $raw) as $line) {
                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);

                if (is_array($event) && ($event['type'] ?? null) === 'text_delta') {
                    $text .= (string) ($event['text'] ?? '');
                }
            }

            return $text;
        }

        $text = '';

        foreach (explode("\n", $raw) as $line) {
            if (!str_starts_with($line, 'data: ') || $line === 'data: [DONE]') {
                continue;
            }

            $chunk = json_decode(substr($line, 6), true);

            if (is_array($chunk)) {
                $text .= (string) ($chunk['choices'][0]['delta']['content'] ?? '');
            }
        }

        return $text;
    }
}
