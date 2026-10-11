<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;

/**
 * Classification an extension brings with it — upstream's `ProviderClassifier`, which a provider
 * supplies as `createProvider({ classifiers: { [api]: implementation } })` or the legacy
 * `registerProvider(name, { classifiers })`, and `Provider.classify()` dispatches to by `model.api`.
 *
 * pig's `ClassifierApi` is closed, so an extension's classifier models say `ClassifierApi::Extension`
 * and `Models::classify()` finds this through `ProviderRegistry::classifierApiFor()` by the model's
 * provider — `StreamApi`'s arrangement for chat. `Models` has checked the input
 * (`assertClassifierInputSupported()`) and resolved the key onto `$options->apiKey` first.
 */
interface ClassificationApi
{
    /**
     * Upstream: "Never rejects." Every failure should be a `ClassifierResult` with `stopReason` error
     * or aborted (`ClassifierResult::error()`); a throw is turned into one by `Models::classify()`.
     */
    public function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult;
}
