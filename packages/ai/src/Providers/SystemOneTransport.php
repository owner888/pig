<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierModel;
use stdClass;

/**
 * "Differences between services that serve System One models" — upstream's `SystemOneTransport` in
 * `api/system-one-shared.ts`. `TypesafeSystemOne` and `CloudflareWorkersAiSystemOne` are its two.
 */
interface SystemOneTransport
{
    /** "Classifier API implemented by this transport." */
    public function api(): ClassifierApi;

    /** "Service name used in error messages." */
    public function label(): string;

    /** "Absolute request URL." */
    public function url(ClassifierModel $model): string;

    /**
     * "Wraps the System One request in the service's request envelope."
     *
     * @param array{state: array<string, mixed>|stdClass, questions: array<string, mixed>} $request
     */
    public function payload(ClassifierModel $model, array $request): mixed;

    /**
     * "Extracts the System One output (`{ answers, usage }`) from the service's response envelope."
     *
     * @param mixed $body the response, decoded with objects as `stdClass`
     */
    public function output(mixed $body): stdClass;
}
