<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The protocol an image model speaks — upstream's `ImageApi`, `KnownImageApi | (string & {})`: the
 * known ones as cases, and every API an extension brings as the one `Extension` case.
 */
enum ImageApi: string
{
    /** Image generation over OpenRouter's chat completions endpoint (`Providers\OpenRouterImages`). */
    case OpenRouterImages = 'openrouter-images';

    /**
     * An image API an extension brought — upstream's provider `images` implementation for an api id
     * it does not know: `Models::generateImages()` finds it through
     * `Extension\ProviderRegistry::imageApiFor()` by the model's provider, as `Api::Extension` finds a
     * `StreamApi`. One case for every such API for `Api::Extension`'s reason: an enum is closed and an
     * extension is not, and the provider name is on the model already.
     */
    case Extension = 'extension';
}
