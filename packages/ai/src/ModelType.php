<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What a catalogue entry is for — upstream's `ModelType`, which "decides which `Models` operation
 * accepts it": a chat `Model` streams (`Stream`), an `ImageModel` generates images
 * (`Models::generateImages()`), a `ClassifierModel` classifies (`Models::classify()`).
 *
 * Upstream keeps all three in one list per provider and narrows with `isModelType()`; pig keeps
 * them in three classes and three tables, so the type is the class, and this names it for
 * `Models::findOfType()` and `Models::allOfType()`.
 */
enum ModelType: string
{
    case Chat = 'chat';
    case Image = 'image';
    case Classifier = 'classifier';
}
