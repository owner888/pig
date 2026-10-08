<?php

declare(strict_types=1);

namespace Pig\Ai;

use Throwable;

/**
 * What an image model answered — upstream's `AssistantImages`. Never thrown: a failure is a result
 * with `stopReason` error (or aborted) and an `errorMessage`.
 *
 * `stopReason` is `StopReason`'s `stop`, `error` or `aborted` — upstream's `ImagesStopReason`.
 */
final readonly class AssistantImages
{
    /** @param list<TextContent|ImageContent> $output */
    public function __construct(
        public ImageApi $api,
        public string $provider,
        public string $model,
        public array $output,
        public StopReason $stopReason,
        public int $timestamp,
        public ?string $responseId = null,
        public ?Usage $usage = null,
        public ?string $errorMessage = null,
    ) {
    }

    /** Upstream's `imageErrorResult()`. */
    public static function error(ImageModel $model, Throwable $error, bool $aborted = false): self
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
