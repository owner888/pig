<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What an Amazon Bedrock model says about itself — upstream's `BedrockCompat`
 * (`providers/compat-schema.ts`, `BedrockCompatSchema`): "Compatibility settings for Amazon Bedrock
 * models."
 *
 * One key, as upstream has it. Upstream's generator writes `{supportsStrictMode: true}` on every
 * Bedrock row models.dev lists with `structured_output: true`; `Providers\Bedrock` reads it as
 * `model.compat?.supportsStrictMode ?? false` and sends a tool's `strict: true` only then.
 */
final readonly class BedrockCompat
{
    /**
     * @param bool|null $supportsStrictMode "Whether the model supports Bedrock strict tool schemas."
     *        Null is upstream's undefined, read as false
     */
    public function __construct(
        public ?bool $supportsStrictMode = null,
    ) {
    }
}
