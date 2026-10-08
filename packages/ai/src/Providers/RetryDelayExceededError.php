<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use RuntimeException;

/**
 * Upstream's `RetryDelayExceededError` in `openai-codex-responses.ts`: a server asked for a longer
 * wait than `maxRetryDelayMs` allows, which `OpenAiCodexResponses` does not retry past.
 */
final class RetryDelayExceededError extends RuntimeException
{
}
