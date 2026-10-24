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
use Pig\Ai\SystemOneCompat;
use stdClass;

/**
 * Upstream's `api/typesafe-system-one.ts`: "TypeSafe's native System One protocol. OpenRouter serves
 * the same protocol, so both providers use this API with different base URLs" — Vercel's AI Gateway
 * and OpenCode Zen do too. `POST <baseUrl>/systemone` with `{model, state, questions}`; the answer is
 * the body itself.
 *
 * "TypeSafe takes images as `assets`. They are experimental and need the `TypeSafe-Danger-Zone-Images`
 * opt-in header; without it the API answers 400. OpenRouter drops `assets` silently and takes images
 * as `image_url` parts in the state instead. Models behind a proxy that hides both the provider ID
 * and the OpenRouter host must set `compat.imageInput`."
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

    /**
     * "OpenRouter by provider ID, which survives baseUrl overrides, or by host, which covers regional
     * endpoints."
     */
    #[\Override]
    public function detectCompat(ClassifierModel $model): SystemOneCompat
    {
        return $model->provider === 'openrouter' || str_contains($model->baseUrl, 'openrouter.ai')
            ? new SystemOneCompat(SystemOneCompat::STATE_IMAGE_URLS, false)
            : new SystemOneCompat(SystemOneCompat::ASSETS, true);
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
