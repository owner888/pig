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
use Pig\Ai\Utils\JsJson;
use stdClass;

/**
 * Upstream's `api/cloudflare-workers-ai-system-one.ts`: "System One models on the Workers AI REST
 * endpoint: `POST /accounts/{account}/ai/run` with `{ model, input }`. The REST API wraps the model
 * output in Cloudflare's API envelope. Third-party models such as `typesafe/jev` add a run record:
 * `{ success, result: { state: "Completed", result: { answers, usage } } }`. Cloudflare-hosted models
 * such as `@cf/cloudflare/clef` return the output directly: `{ success, result: { model, answers,
 * usage } }`."
 *
 * The `{CLOUDFLARE_ACCOUNT_ID}` in the base URL is filled in before this is reached
 * (`Cloudflare::resolveCloudflareModel()`, upstream's `cloudflareClassifier()`).
 */
final class CloudflareWorkersAiSystemOne implements SystemOneTransport
{
    private const string LABEL = 'Cloudflare Workers AI';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** "Cloudflare Workers AI System One classification with public `bool` values mapped to wire-level `noul`." */
    public function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        return SystemOneShared::classifySystemOne($this, $model, $context, $options, $this->http);
    }

    #[\Override]
    public function api(): ClassifierApi
    {
        return ClassifierApi::CloudflareWorkersAiSystemOne;
    }

    #[\Override]
    public function label(): string
    {
        return self::LABEL;
    }

    /** `new URL("run", `${model.baseUrl.replace(/\/+$/u, "")}/`)`. */
    #[\Override]
    public function url(ClassifierModel $model): string
    {
        return rtrim($model->baseUrl, '/') . '/run';
    }

    /** `{ model: model.id, input: request }`. */
    #[\Override]
    public function payload(ClassifierModel $model, array $request): mixed
    {
        return ['model' => $model->id, 'input' => $request];
    }

    #[\Override]
    public function output(mixed $body): stdClass
    {
        if (!SystemOneShared::isRecord($body)) {
            throw new ProviderError(self::LABEL . ' returned an unexpected response');
        }

        if (($body->success ?? null) === false) {
            throw new ProviderError(self::cloudflareErrorMessage($body->errors ?? null));
        }

        $result = $body->result ?? null;

        if (!SystemOneShared::isRecord($result)) {
            throw new ProviderError(self::LABEL . ' returned an unexpected response');
        }

        if (property_exists($result, 'answers')) {
            return $result;
        }

        if (($result->state ?? null) !== 'Completed') {
            // `String(result.state)`: `undefined` when there is none.
            $state = property_exists($result, 'state') ? JsJson::toString($result->state) : 'undefined';

            throw new ProviderError(self::LABEL . " run did not complete (state: {$state})");
        }

        if (!SystemOneShared::isRecord($result->result ?? null)) {
            throw new ProviderError(self::LABEL . ' returned an unexpected response');
        }

        return $result->result;
    }

    /** Upstream's `cloudflareErrorMessage()`: the `errors[].message` strings joined, else a fixed sentence. */
    private static function cloudflareErrorMessage(mixed $errors): string
    {
        if (is_array($errors)) {
            $messages = [];

            foreach ($errors as $error) {
                if (SystemOneShared::isRecord($error) && is_string($error->message ?? null)) {
                    $messages[] = $error->message;
                }
            }

            if ($messages !== []) {
                return self::LABEL . ' error: ' . implode('; ', $messages);
            }
        }

        return self::LABEL . ' request failed';
    }
}
