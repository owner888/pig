<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;

/** Upstream's `ModelReferenceTarget`: where a model reference that found nothing was pointed. */
final readonly class ModelReferenceTarget
{
    /**
     * @param non-empty-list<Model> $providerModels the provider's models, the anchor first
     * @param string                $modelText      text after the provider prefix as typed, including any `:<suffix>`
     * @param string                $modelId        `$modelText` without a valid thinking suffix (unless suffixes are off)
     */
    public function __construct(
        public string $provider,
        public array $providerModels,
        public string $modelText,
        public string $modelId,
        public ?ThinkingLevel $thinking = null,
    ) {
    }
}
