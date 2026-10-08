<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierBoolAnswer;
use Pig\Ai\ClassifierBoolQuestion;
use Pig\Ai\ClassifierChoiceAnswer;
use Pig\Ai\ClassifierChoiceQuestion;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\ClassifierScoreAnswer;
use Pig\Ai\ClassifierScoreQuestion;
use Pig\Ai\Models;
use Pig\Ai\Providers\LlamaCppClassify;
use Pig\Ai\StopReason;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\ScriptedServer;

/**
 * Upstream's `llama-cpp-classify.test.ts`, its fake `llama-server` behind a socket: `/tokenize`
 * gives one token per character (its code point) unless a test tokenizes otherwise,
 * `/apply-template` renders `<|role|>\n<content>\n` per message, and `/completion` answers the
 * test's next-token log-probabilities as `top_logprobs`.
 *
 * Label tokens are cached per server root and model, so each test's model sits under a path of
 * its own (`/sN/v1`), as upstream gives each a fresh host.
 */
final class LlamaCppClassifyTest extends TestCase
{
    private static int $serverCount = 0;

    private ?ScriptedServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testChoiceBoolAndScoreQuestionsAreAnsweredFromLabelLogProbabilities(): void
    {
        $model = $this->fakeServer(next: self::answerByPrompt(...), tokenize: self::wordTokens(...));

        $result = self::classify($model, self::context(), new ClassifierOptions(apiKey: 'local'));

        $this->assertNull($result->errorMessage);
        $this->assertSame(StopReason::Stop, $result->stopReason);
        $choice = LlamaCppClassify::labelProbabilities([-1.5, -0.3, -3.0], 1.0);
        $this->assertEquals(new ClassifierChoiceAnswer('technical', ['billing' => $choice[0], 'technical' => $choice[1], 'sales' => $choice[2]], LlamaCppClassify::peakConfidence($choice)), $result->answers['team']);
        $this->assertEquals(new ClassifierBoolAnswer(LlamaCppClassify::labelProbabilities([-0.05, -3.0], 1.0)[0]), $result->answers['urgent']);
        $levels = LlamaCppClassify::labelProbabilities([-4.0, -1.8, -0.2], 1.0);
        $this->assertEquals(new ClassifierScoreAnswer($levels[1] + 2 * $levels[2], LlamaCppClassify::peakConfidence($levels)), $result->answers['severity']);

        $root = LlamaCppClassify::llamaServerRoot($model->baseUrl);
        $prefix = (string) parse_url($root, PHP_URL_PATH);

        foreach ($this->server()->requests as $request) {
            $this->assertStringStartsWith($prefix . '/', $request['path']);
            $this->assertSame('qwen', json_decode($request['body'], true)['model']);
            $this->assertSame('Bearer local', $request['headers']['authorization'] ?? null);
        }

        $completion = $this->bodyAt('/completion');
        $this->assertSame([1, 256, false, true], [$completion['n_predict'], $completion['n_probs'], $completion['post_sampling_probs'], $completion['cache_prompt']]);
        $this->assertSame(['enable_thinking' => false], $this->bodyAt('/apply-template')['chat_template_kwargs']);
    }

    public function testTheStateIsRepeatedAroundEveryQuestionAndThisQuestionsLabelsComeLast(): void
    {
        $rendered = LlamaCppClassify::renderQuestion(self::context(), 'team');
        $state = "State:\n{\n \"message\": \"Help! My payouts have been failing for 3 days.\"\n}";

        $this->assertSame(['A', 'B', 'C'], $rendered['labels']);
        $this->assertSame(['billing', 'technical', 'sales'], $rendered['keys']);
        $this->assertSame(implode("\n", [
            $state,
            '',
            'Task: answer each of the following questions about the state.',
            '',
            'Question: Which team should handle this?',
            '',
            'Options:',
            '- billing: Payments and refunds',
            '- technical: Bugs and outages',
            '- sales',
            '',
            'Question: Does this convey urgency?',
            '',
            'Yes means: The user needs help soon',
            'No means: No time pressure',
            '',
            'Question: How severe is this?',
            '',
            'Levels:',
            '0. low',
            '1. medium',
            '2. high',
            '',
            $state,
            '',
            'Question: Which team should handle this?',
            '',
            'Options:',
            'A. billing: Payments and refunds',
            'B. technical: Bugs and outages',
            'C. sales',
            '',
            'Answer with one letter.',
        ]), $rendered['content']);
    }

    public function testEverythingBeforeTheFinalQuestionIsSharedAcrossTheRequest(): void
    {
        $prefix = static function (string $id): string {
            $content = LlamaCppClassify::renderQuestion(self::context(), $id)['content'];

            return substr($content, 0, (int) strrpos($content, 'Question:'));
        };

        $this->assertSame($prefix('team'), $prefix('urgent'));
        $this->assertSame($prefix('team'), $prefix('severity'));
        $this->assertStringEndsWith("No means: No time pressure\n\nAnswer Yes or No.", LlamaCppClassify::renderQuestion(self::context(), 'urgent')['content']);
        $this->assertStringEndsWith("2. high\n\nAnswer with one level number.", LlamaCppClassify::renderQuestion(self::context(), 'severity')['content']);
    }

    public function testTheTemperatureDividesTheLabelLogProbabilities(): void
    {
        $model = $this->fakeServer(next: static fn (): array => ['A' => -0.1, 'B' => -2.5]);

        $result = self::classify($model, self::pick(), new ClassifierOptions(temperature: 2.0));

        $expected = LlamaCppClassify::labelProbabilities([-0.1 / 2, -2.5 / 2], 1.0);
        $this->assertInstanceOf(ClassifierChoiceAnswer::class, $result->answers['pick'] ?? null);
        $this->assertSame(['a' => $expected[0], 'b' => $expected[1]], $result->answers['pick']->probabilities);
        $this->assertSame($expected, LlamaCppClassify::labelProbabilities([-0.1, -2.5], 2.0));
    }

    public function testANonPositiveTemperatureIsRefusedBeforeAnyRequest(): void
    {
        $model = $this->fakeServer();

        $result = self::classify($model, self::context(), new ClassifierOptions(temperature: 0.0));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('Temperature must be a positive number, got 0', (string) $result->errorMessage);
        $this->assertSame([], $this->server()->requests);
    }

    public function testAMissingLabelIsAskedForDeeperAndThenFailsWithoutInventingZeros(): void
    {
        $deep = $this->fakeServer(next: static fn (string $prompt, int $depth): array => $depth < 4096 ? ['A' => -0.1] : ['A' => -0.1, 'B' => -9.0]);
        $recovered = self::classify($deep, self::pick(), new ClassifierOptions());
        $this->assertSame(StopReason::Stop, $recovered->stopReason, (string) $recovered->errorMessage);
        $this->assertSame([256, 4096], $this->completionDepths());

        $never = $this->fakeServer(next: static fn (): array => ['A' => -0.1]);
        $failed = self::classify($never, self::pick(), new ClassifierOptions());
        $this->assertSame(StopReason::Error, $failed->stopReason);
        $this->assertSame([], $failed->answers);
        $this->assertStringContainsString('did not rank labels B for pick within the top 32768 tokens', (string) $failed->errorMessage);
        $this->assertSame([256, 4096, 32768], $this->completionDepths());
    }

    public function testAReasoningBlockTheTemplateLeavesOpenIsClosed(): void
    {
        $model = $this->fakeServer(template: static fn (): string => "<|assistant|>\n<think>");

        self::classify($model, self::pick(), new ClassifierOptions());

        $this->assertSame("<|assistant|>\n<think></think>", $this->bodyAt('/completion')['prompt']);
    }

    public function testLabelsAreReadInReplyPositionAndAMultiTokenLabelIsRefused(): void
    {
        // "A tokenizer that merges a newline with a following letter falls back to the label alone."
        $merging = $this->fakeServer(tokenize: static fn (string $content): array => str_starts_with($content, "\n") && mb_strlen($content) > 1 ? [1000] : self::charTokens($content));
        $this->assertSame(StopReason::Stop, self::classify($merging, self::pick(), new ClassifierOptions())->stopReason);

        // "The default fake tokenizer splits "Yes" into three tokens."
        $split = $this->fakeServer();
        $result = self::classify($split, new ClassifierContext([], ['ok' => new ClassifierBoolQuestion('OK?', ['true' => '', 'false' => ''])]), new ClassifierOptions());
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('Label "Yes" is not a single token for qwen', (string) $result->errorMessage);
    }

    public function testLabelTokensAreCachedPerServerAndModel(): void
    {
        $model = $this->fakeServer();

        self::classify($model, self::pick(), new ClassifierOptions());
        $first = count($this->requestsTo('/tokenize'));
        self::classify($model, self::pick(), new ClassifierOptions());

        $this->assertGreaterThan(0, $first);
        $this->assertCount($first, $this->requestsTo('/tokenize'));
    }

    public function testOptionCountsAreCheckedBeforeAnyRequest(): void
    {
        $model = $this->fakeServer();
        $criteria = [];

        for ($index = 0; $index < 63; $index++) {
            $criteria["option{$index}"] = '';
        }

        $tooMany = self::classify($model, new ClassifierContext([], ['pick' => new ClassifierChoiceQuestion('Pick', $criteria)]), new ClassifierOptions());
        $tooFew = self::classify($model, new ClassifierContext([], ['rate' => new ClassifierScoreQuestion('Rate', ['only'])]), new ClassifierOptions());

        $this->assertStringContainsString('A choice question needs 2 to 62 options, got 63', (string) $tooMany->errorMessage);
        $this->assertStringContainsString('A score question needs 2 to 10 levels, got 1', (string) $tooFew->errorMessage);
        $this->assertSame([], $this->server()->requests);
    }

    public function testTheCompletionPayloadAndResponseGoThroughTheRequestHooks(): void
    {
        $model = $this->fakeServer();
        $payloads = [];
        $statuses = [];

        self::classify($model, self::pick(), new ClassifierOptions(
            onPayload: static function (mixed $payload) use (&$payloads): array {
                $payloads[] = $payload;

                return [...$payload, 'id_slot' => 1];
            },
            onResponse: static function (array $response) use (&$statuses): void {
                $statuses[] = $response['status'];
            },
        ));

        $this->assertCount(1, $payloads);
        $this->assertSame(1, $payloads[0]['n_predict']);
        $this->assertSame([200], $statuses);
        $this->assertSame(1, $this->bodyAt('/completion')['id_slot'] ?? null);
    }

    public function testServerErrorsAndCancellationAreReported(): void
    {
        $failingModel = $this->modelAt($this->serve(static fn (): array => [400, [], '{"error":{"message":"context overflow"}}']));
        $failing = self::classify($failingModel, self::context(), new ClassifierOptions(maxRetries: 0));

        $this->assertSame(StopReason::Error, $failing->stopReason);
        $this->assertStringContainsString('llama.cpp error (400)', (string) $failing->errorMessage);
        $this->assertStringContainsString('context overflow', (string) $failing->errorMessage);

        $controller = new AbortController();
        $controller->abort();
        $aborted = self::classify($this->fakeServer(), self::context(), new ClassifierOptions(signal: $controller->signal));
        $this->assertSame(StopReason::Aborted, $aborted->stopReason);
    }

    public function testAModelForAnotherClassifierApiIsRefused(): void
    {
        $model = $this->fakeServer();
        $other = new ClassifierModel($model->id, $model->name, ClassifierApi::TypesafeSystemOne, $model->provider, $model->baseUrl, $model->contextWindow);

        $result = Async::run(static fn (): ClassifierResult => (new LlamaCppClassify())->classify($other, self::context()));

        $this->assertStringContainsString('Unsupported classifier API: typesafe-system-one', (string) $result->errorMessage);
        $this->assertSame([], $this->server()->requests);
    }

    public function testTheServerRootIsTheOpenAiCompatibleBaseUrlWithoutV1(): void
    {
        $this->assertSame('http://127.0.0.1:8080', LlamaCppClassify::llamaServerRoot('http://127.0.0.1:8080/v1/'));
        $this->assertSame('https://example.com/prefix', LlamaCppClassify::llamaServerRoot('https://example.com/prefix/v1'));
        $this->assertSame('http://127.0.0.1:8080', LlamaCppClassify::llamaServerRoot('http://127.0.0.1:8080'));
    }

    public function testTypesafesConfidenceAndTheExpectedScore(): void
    {
        $this->assertEqualsWithDelta(0.835, LlamaCppClassify::peakConfidence([0.89, 0.06, 0.05]), 1e-9);
        $this->assertSame(0.0, LlamaCppClassify::peakConfidence([0.5, 0.5]));
        $this->assertSame(1.0, LlamaCppClassify::peakConfidence([1.0, 0.0, 0.0]));
        $answer = LlamaCppClassify::answerFromProbabilities(new ClassifierScoreQuestion('', ['a', 'b', 'c']), ['0', '1', '2'], [0.2, 0.3, 0.5]);
        $this->assertInstanceOf(ClassifierScoreAnswer::class, $answer);
        $this->assertEqualsWithDelta(1.3, $answer->score, 1e-12);
        $this->assertSame(LlamaCppClassify::peakConfidence([0.2, 0.3, 0.5]), $answer->confidence);
    }

    // ---- the fake server ----------------------------------------------------------------------

    /**
     * @param (Closure(string $prompt, int $depth): array<string, float>)|null $next
     * @param (Closure(list<array{role: string, content: string}> $messages): string)|null $template
     * @param (Closure(string $content): list<int>)|null $tokenize
     */
    private function fakeServer(?Closure $next = null, ?Closure $template = null, ?Closure $tokenize = null): ClassifierModel
    {
        return $this->modelAt($this->serve(static function (array $request) use ($next, $template, $tokenize): array {
            $body = json_decode($request['body'], true);
            $path = (string) preg_replace('#^/s\d+#', '', $request['path']);

            if ($path === '/tokenize') {
                return [200, [], json_encode(['tokens' => ($tokenize ?? self::charTokens(...))((string) $body['content'])])];
            }

            if ($path === '/apply-template') {
                $messages = $body['messages'];
                $prompt = $template !== null
                    ? $template($messages)
                    : implode('', array_map(static fn (array $message): string => "<|{$message['role']}|>\n{$message['content']}\n", $messages)) . "<|assistant|>\n";

                return [200, [], json_encode(['prompt' => $prompt])];
            }

            if ($path === '/completion') {
                $probabilities = $next !== null ? $next((string) $body['prompt'], (int) $body['n_probs']) : ['A' => -0.1, 'B' => -2.5];
                $top = [];

                foreach ($probabilities as $token => $logprob) {
                    $top[] = ['id' => mb_ord((string) $token), 'token' => (string) $token, 'bytes' => [], 'logprob' => $logprob];
                }

                return [200, [], json_encode(['content' => 'A', 'completion_probabilities' => [['id' => 65, 'token' => 'A', 'top_logprobs' => $top]]])];
            }

            return [404, ['content-type' => 'text/plain'], 'not found'];
        }));
    }

    private function serve(Closure $answer): string
    {
        $this->server?->stop();
        $this->server = new ScriptedServer();

        return $this->server->start($answer);
    }

    private function modelAt(string $base): ClassifierModel
    {
        self::$serverCount++;

        return new ClassifierModel('qwen', 'qwen', ClassifierApi::LlamaCppClassify, 'llama.cpp', $base . '/s' . self::$serverCount . '/v1', 32_768);
    }

    private function server(): ScriptedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }

    /** @return list<array<string, mixed>> */
    private function requestsTo(string $path): array
    {
        return array_values(array_filter($this->server()->requests, static fn (array $request): bool => str_ends_with($request['path'], $path)));
    }

    /** @return array<string, mixed> the first request's body to that path */
    private function bodyAt(string $path): array
    {
        $requests = $this->requestsTo($path);
        $this->assertNotSame([], $requests, $path);

        return json_decode($requests[0]['body'], true);
    }

    /** @return list<int> */
    private function completionDepths(): array
    {
        return array_map(static fn (array $request): int => (int) json_decode($request['body'], true)['n_probs'], $this->requestsTo('/completion'));
    }

    private static function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult
    {
        return Async::run(static fn (): ClassifierResult => Models::classify($model, $context, $options));
    }

    /** "Token IDs: one per character, the character code." @return list<int> */
    private static function charTokens(string $content): array
    {
        return array_map(mb_ord(...), mb_str_split($content));
    }

    /** "Maps multi-character labels to single tokens, as a real vocabulary would." @return list<int> */
    private static function wordTokens(string $content): array
    {
        $words = ['Yes' => 89, 'No' => 78];
        $tokens = [];

        foreach (preg_split('/(\n)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            $tokens = [...$tokens, ...(isset($words[$part]) ? [$words[$part]] : self::charTokens($part))];
        }

        return $tokens;
    }

    /** @return array<string, float> */
    private static function answerByPrompt(string $prompt): array
    {
        if (str_contains($prompt, 'Answer Yes or No.')) {
            return ['Y' => -0.05, 'N' => -3.0];
        }

        if (str_contains($prompt, 'Answer with one level number.')) {
            return ['2' => -0.2, '1' => -1.8, '0' => -4.0];
        }

        return ['B' => -0.3, 'A' => -1.5, 'C' => -3.0];
    }

    private static function context(): ClassifierContext
    {
        return new ClassifierContext(['message' => 'Help! My payouts have been failing for 3 days.'], [
            'team' => new ClassifierChoiceQuestion('Which team should handle this?', ['billing' => 'Payments and refunds', 'technical' => 'Bugs and outages', 'sales' => '']),
            'urgent' => new ClassifierBoolQuestion('Does this convey urgency?', ['true' => 'The user needs help soon', 'false' => 'No time pressure']),
            'severity' => new ClassifierScoreQuestion('How severe is this?', ['low', 'medium', 'high']),
        ]);
    }

    private static function pick(): ClassifierContext
    {
        return new ClassifierContext([], ['pick' => new ClassifierChoiceQuestion('Pick one', ['a' => '', 'b' => ''])]);
    }
}
