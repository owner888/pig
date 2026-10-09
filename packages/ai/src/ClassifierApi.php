<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The protocol a classifier model speaks — upstream's `KnownClassifierApi`, which is to
 * `ClassifierModel` what `Api` is to a chat `Model`.
 */
enum ClassifierApi: string
{
    /** TypeSafe's System One (`Providers\TypesafeSystemOne`), also served by OpenRouter, Vercel's AI Gateway and OpenCode Zen. */
    case TypesafeSystemOne = 'typesafe-system-one';
    /** System One models on the Workers AI REST endpoint (`Providers\CloudflareWorkersAiSystemOne`). */
    case CloudflareWorkersAiSystemOne = 'cloudflare-workers-ai-system-one';
    /** A chat model on llama.cpp's `llama-server`, read by its next-token probabilities (`Providers\LlamaCppClassify`). */
    case LlamaCppClassify = 'llama-cpp-classify';
    /** OpenAI's Decisions API, `POST /v1/decisions` (`Providers\OpenAiDecisions`) — the one classifier that takes images. */
    case OpenAiDecisions = 'openai-decisions';
}
