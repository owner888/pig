<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

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
use Pig\Ai\Http\HttpClient;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use stdClass;
use Throwable;

/**
 * Upstream's `api/openai-decisions.ts`: "OpenAI's Decisions API: `POST /v1/decisions` with
 * `{ model, input, questions }`. The state is sent as JSON text. With images, the input becomes one
 * user message with the state as `input_text` followed by `input_image` data URLs. Questions map to
 * Decisions types: `choice` to `choice`, `score` to `score`, and `bool` to `predicate`. Predicates
 * have no criteria field, so the meanings of true and false are appended to the instructions. Only
 * OpenAI API keys work: Sign in with ChatGPT tokens are rejected on this route."
 *
 * The HTTP half is `SystemOneShared`'s — what upstream moved into `classifier-shared.ts` for this
 * API — so a refusal reads `OpenAI Decisions error (<status>): <body>` like a System One one, and a
 * gateway timeout (504) is said in its own words and not retried.
 */
final class OpenAiDecisions
{
    public const string LABEL = 'OpenAI Decisions';

    /** "The endpoint accepts at most this many image parts per request." */
    public const int MAX_IMAGES = 128;

    /**
     * "Cloudflare in front of api.openai.com answers 504 with an HTML page when a request runs
     * longer than about five seconds. Large inputs, currently above roughly 600K tokens, hit this
     * limit, and retrying the same input runs into it again, so 504 is not retried."
     */
    private const array NO_RETRY_STATUSES = [504];

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** "Classification through OpenAI's Decisions API." */
    public function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        $timestamp = Timestamp::nowMs();
        $usage = null;

        try {
            if ($model->api !== ClassifierApi::OpenAiDecisions) {
                throw new ProviderError("Unsupported classifier API: {$model->api->value}");
            }

            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $questions = [];

            foreach ($context->questions as $id => $question) {
                $questions[] = self::wireQuestion((string) $id, $question);
            }

            $payload = ['model' => $model->id, 'input' => self::wireInput($context), 'questions' => $questions];
            $transformed = $options?->onPayload !== null ? ($options->onPayload)($payload, $model) : null;

            if ($transformed !== null) {
                $payload = $transformed;
            }

            $headers = SystemOneShared::requestHeaders($model, $apiKey, $options?->headers);
            $body = SystemOneShared::encode($payload);
            $url = rtrim($model->baseUrl, '/') . '/decisions';
            $http = $this->http;
            [$response, $decoded] = ProviderRetry::retryProviderRequest(
                static fn (): array => SystemOneShared::postJson($http, $url, $headers, $body, $options?->signal, $options?->timeoutMs, self::LABEL),
                $options?->maxRetries ?? 2,
                $options?->maxRetryDelayMs,
                $options?->signal,
                self::NO_RETRY_STATUSES,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            if (!$decoded instanceof stdClass) {
                throw new ProviderError(self::LABEL . ' returned an unexpected response');
            }

            // "Set before parsing answers: a request with malformed or refused answers was still billed."
            $usage = SystemOneShared::parseUsage($decoded->usage ?? null, $model);
            $answers = self::parseAnswers($decoded->answers ?? null, $context);

            return new ClassifierResult($model->api, $model->provider, $model->id, $answers, StopReason::Stop, $timestamp, $usage);
        } catch (Throwable $error) {
            return new ClassifierResult(
                $model->api,
                $model->provider,
                $model->id,
                [],
                ($options?->signal?->aborted() ?? false) ? StopReason::Aborted : StopReason::Error,
                $timestamp,
                $usage,
                self::errorMessage($error),
            );
        }
    }

    /** Upstream's `predicateInstructions()`: the meanings of true and false, appended. */
    private static function predicateInstructions(ClassifierBoolQuestion $question): string
    {
        $meanings = [];

        if (($question->criteria['true'] ?? '') !== '') {
            $meanings[] = 'True means: ' . $question->criteria['true'];
        }

        if (($question->criteria['false'] ?? '') !== '') {
            $meanings[] = 'False means: ' . $question->criteria['false'];
        }

        return $meanings !== [] ? $question->instructions . "\n\n" . implode("\n", $meanings) : $question->instructions;
    }

    /** @return array<string, mixed> */
    private static function wireQuestion(string $name, ClassifierChoiceQuestion|ClassifierScoreQuestion|ClassifierBoolQuestion $question): array
    {
        if ($question instanceof ClassifierChoiceQuestion) {
            $choices = [];

            foreach ($question->criteria as $value => $description) {
                $choices[] = (string) $description !== '' ? ['value' => (string) $value, 'description' => $description] : ['value' => (string) $value];
            }

            return ['type' => 'choice', 'name' => $name, 'instructions' => $question->instructions, 'choices' => $choices];
        }

        if ($question instanceof ClassifierScoreQuestion) {
            return [
                'type' => 'score',
                'name' => $name,
                'instructions' => $question->instructions,
                'levels' => array_map(static fn (string $label): array => ['label' => $label], array_values($question->criteria)),
            ];
        }

        return ['type' => 'predicate', 'name' => $name, 'instructions' => self::predicateInstructions($question)];
    }

    /** Upstream's `wireInput()`: the state as JSON text, or one user message with the images after it. */
    private static function wireInput(ClassifierContext $context): string|array
    {
        $state = SystemOneShared::encode($context->stateForJson());

        if ($context->images === []) {
            return $state;
        }

        if (count($context->images) > self::MAX_IMAGES) {
            throw new ProviderError(self::LABEL . ' accepts at most ' . self::MAX_IMAGES . ' images, got ' . count($context->images));
        }

        $content = [['type' => 'input_text', 'text' => $state]];

        foreach ($context->images as $image) {
            $content[] = ['type' => 'input_image', 'image_url' => "data:{$image->mimeType};base64,{$image->data}"];
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /** @return array<string, float> */
    private static function choiceProbabilities(mixed $value, string $id): array
    {
        if (!is_array($value)) {
            throw new ProviderError(self::LABEL . " returned invalid probabilities for {$id}");
        }

        $out = [];

        foreach ($value as $entry) {
            if (!$entry instanceof stdClass || !is_string($entry->value ?? null)) {
                throw new ProviderError(self::LABEL . " returned invalid probabilities for {$id}");
            }

            $out[$entry->value] = SystemOneShared::requiredNumber(self::LABEL, $entry->probability ?? null, "probability for {$id}.{$entry->value}");
        }

        return $out;
    }

    private static function parseAnswer(string $id, ClassifierChoiceQuestion|ClassifierScoreQuestion|ClassifierBoolQuestion $question, stdClass $answer): ClassifierChoiceAnswer|ClassifierScoreAnswer|ClassifierBoolAnswer
    {
        $type = $answer->type ?? null;

        if ($type === 'refusal') {
            throw new ProviderError(self::LABEL . " refused to answer {$id}");
        }

        if ($question instanceof ClassifierChoiceQuestion) {
            if ($type !== 'choice' || !is_string($answer->choice ?? null)) {
                throw new ProviderError(self::LABEL . " did not return a choice answer for {$id}");
            }

            return new ClassifierChoiceAnswer(
                $answer->choice,
                self::choiceProbabilities($answer->probabilities ?? null, $id),
                SystemOneShared::requiredNumber(self::LABEL, $answer->confidence ?? null, "confidence for {$id}"),
            );
        }

        if ($question instanceof ClassifierScoreQuestion) {
            if ($type !== 'score') {
                throw new ProviderError(self::LABEL . " did not return a score answer for {$id}");
            }

            return new ClassifierScoreAnswer(
                SystemOneShared::requiredNumber(self::LABEL, $answer->score ?? null, "score for {$id}"),
                SystemOneShared::requiredNumber(self::LABEL, $answer->confidence ?? null, "confidence for {$id}"),
            );
        }

        if ($type !== 'predicate') {
            throw new ProviderError(self::LABEL . " did not return a predicate answer for {$id}");
        }

        return new ClassifierBoolAnswer(SystemOneShared::requiredNumber(self::LABEL, $answer->probability ?? null, "probability for {$id}"));
    }

    /**
     * Upstream's `parseAnswers()`: the answers come back as a list named by question; one of the
     * asked type for every question, in the order the questions were asked.
     *
     * @return array<string, ClassifierChoiceAnswer|ClassifierScoreAnswer|ClassifierBoolAnswer>
     */
    private static function parseAnswers(mixed $value, ClassifierContext $context): array
    {
        if (!is_array($value)) {
            throw new ProviderError(self::LABEL . ' returned an unexpected response');
        }

        $byName = [];

        foreach ($value as $answer) {
            if ($answer instanceof stdClass && is_string($answer->name ?? null)) {
                $byName[$answer->name] = $answer;
            }
        }

        $answers = [];

        foreach ($context->questions as $id => $question) {
            $id = (string) $id;
            $answer = $byName[$id] ?? null;

            if ($answer === null) {
                throw new ProviderError(self::LABEL . " did not return an answer for {$id}");
            }

            $answers[$id] = self::parseAnswer($id, $question, $answer);
        }

        return $answers;
    }

    private static function errorMessage(Throwable $error): string
    {
        if ($error instanceof ProviderHttpError && $error->status === 504) {
            return self::LABEL . ' error (504): the request timed out at the gateway. Very large inputs (above roughly 600K tokens) currently exceed its time limit.';
        }

        return ErrorBody::format(ErrorBody::normalizeProviderError($error), self::LABEL . ' error');
    }
}
