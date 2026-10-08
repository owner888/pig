<?php

declare(strict_types=1);

namespace Pig\Ai;

use Throwable;

/**
 * Upstream's `ClassifierResult`. Never thrown — a refused or failed request is a result with
 * `stopReason` error (or aborted) and an `errorMessage`, as upstream's `classify()` "never rejects".
 *
 * `stopReason` is `StopReason`'s `stop`, `error` or `aborted` — upstream's `ClassifierStopReason`,
 * which is those three of the chat stop reasons.
 */
final readonly class ClassifierResult
{
    /**
     * @param array<string, ClassifierChoiceAnswer|ClassifierScoreAnswer|ClassifierBoolAnswer> $answers by question id
     * @param Usage|null $usage "Token usage and its cost at the model's catalog price, when the
     *        service reports token counts."
     */
    public function __construct(
        public ClassifierApi $api,
        public string $provider,
        public string $model,
        public array $answers,
        public StopReason $stopReason,
        public int $timestamp,
        public ?Usage $usage = null,
        public ?string $errorMessage = null,
    ) {
    }

    /**
     * Upstream's `classifierErrorResult()`: no answers, `aborted` when the caller's signal was,
     * the error's message.
     */
    public static function error(ClassifierModel $model, Throwable $error, bool $aborted = false): self
    {
        return new self(
            $model->api,
            $model->provider,
            $model->id,
            [],
            $aborted ? StopReason::Aborted : StopReason::Error,
            Timestamp::nowMs(),
            errorMessage: $error->getMessage(),
        );
    }
}
