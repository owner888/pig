<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\AssistantImages;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;

/**
 * Image generation an extension brings with it — upstream's `ProviderImages`, which a provider
 * supplies as `createProvider({ images: { [api]: implementation } })` or the legacy
 * `registerProvider(name, { images })`, and `Provider.generateImages()` dispatches to by `model.api`.
 *
 * pig's `ImageApi` is closed, so an extension's image models say `ImageApi::Extension` and
 * `Models::generateImages()` finds this through `ProviderRegistry::imageApiFor()` by the model's
 * provider — `StreamApi`'s arrangement for chat. `Models` has resolved the key first (the caller's,
 * else the environment's) and put it on `$options->apiKey`, as upstream's `applyAuth()` does before
 * it calls the provider.
 */
interface ImageGenerationApi
{
    /**
     * Upstream: "Never rejects." Every failure should be an `AssistantImages` with `stopReason`
     * error or aborted (`AssistantImages::error()`), and `usage` priced at the model's rates when
     * the service reports token counts. A throw is not lost: `Models::generateImages()` turns it
     * into that error result, as upstream's `catch` around the provider call does.
     */
    public function generateImages(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages;
}
