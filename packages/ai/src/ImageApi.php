<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The protocol an image model speaks — upstream's `KnownImageApi`. */
enum ImageApi: string
{
    /** Image generation over OpenRouter's chat completions endpoint (`Providers\OpenRouterImages`). */
    case OpenRouterImages = 'openrouter-images';
}
