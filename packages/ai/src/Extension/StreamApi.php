<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;

/**
 * A wire protocol an extension brings with it.
 *
 * Upstream's `registerProvider(provider)` takes an `api` whose `streamSimple` and `stream` the
 * registry calls in place of a built-in's. The five built-in protocols are `Api` cases and arms
 * in `Stream::start()`; a model whose `Api` is `Api::Extension` is handed here instead, and the
 * registry finds the implementation by the model's provider name. That is the whole of what an
 * extension has to implement to speak to an endpoint pig has never heard of — the Anthropic,
 * OpenAI and Google providers in `Providers\` are examples of the shape, with `HttpClient`,
 * `SseParser` and `AssistantMessageBuilder` already there to build one out of.
 */
interface StreamApi
{
    /**
     * Turn simple options into this protocol's own, the way `Stream::translate()` does for a
     * built-in: a reasoning level into whatever the endpoint calls it, a missing `maxTokens`
     * into the model's, and the key into the request.
     */
    public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions;

    /**
     * Returns at once; the response fills in as it arrives.
     *
     * The context is a transcript, as upstream's custom APIs receive: the prompt and the tools are
     * its system messages (`Utils\Transcript`), not fields beside them.
     */
    public function stream(Model $model, TranscriptContext $context, StreamOptions $options): AssistantMessageEventStream;
}
