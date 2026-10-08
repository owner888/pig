<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The normalized request context passed to providers — upstream's `TranscriptContext`.
 *
 * The prompt and the tool declarations are carried by the transcript's system messages, never by
 * a `systemPrompt` or `tools` field, so a provider cannot read the raw `Context` by accident.
 * `Utils\Transcript::normalizeContext()` folds a `Context` into one; `Stream::simple()` and
 * `Stream::start()` do that before any provider is called.
 *
 * Upstream brands the type so only `normalizeContext()` can produce it. PHP has no brands, so the
 * constructor is public — the providers' own `collapseSystemMessages()` builds one too, as
 * upstream's does with a cast.
 *
 * @phpstan-import-type Message from Context
 */
final readonly class TranscriptContext
{
    /** @param list<Message> $messages */
    public function __construct(public array $messages)
    {
    }
}
