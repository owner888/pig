<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\ProviderError;
use stdClass;

/**
 * Upstream's `api/typesafe-system-one.ts`: "TypeSafe's native System One protocol. OpenRouter serves
 * the same protocol, so both providers use this API with different base URLs" — Vercel's AI Gateway
 * and OpenCode Zen do too. `POST <baseUrl>/systemone` with `{model, state, questions}`; the answer is
 * the body itself.
 */
final class TypesafeSystemOne implements SystemOneTransport
{
    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** "TypeSafe System One classification with public `bool` values mapped to wire-level `noul`." */
    public function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        return SystemOneShared::classifySystemOne($this, $model, $context, $options, $this->http);
    }

    #[\Override]
    public function api(): ClassifierApi
    {
        return ClassifierApi::TypesafeSystemOne;
    }

    #[\Override]
    public function label(): string
    {
        return 'System One API';
    }

    /** `new URL("systemone", `${model.baseUrl.replace(/\/+$/u, "")}/`)`. */
    #[\Override]
    public function url(ClassifierModel $model): string
    {
        return rtrim($model->baseUrl, '/') . '/systemone';
    }

    /** `{ model: model.id, ...request }`. */
    #[\Override]
    public function payload(ClassifierModel $model, array $request): mixed
    {
        return ['model' => $model->id, ...$request];
    }

    #[\Override]
    public function output(mixed $body): stdClass
    {
        if (!SystemOneShared::isRecord($body)) {
            throw new ProviderError('System One API returned an unexpected response');
        }

        return $body;
    }
}
