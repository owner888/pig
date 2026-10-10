<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Throwable;

/**
 * The `azure` provider — upstream's `providers/azure.ts`: "Azure" serves the Responses API
 * (`AzureOpenAiResponses`, which resolves its own endpoint) and Chat Completions for Foundry
 * deployments (DeepSeek V4 Pro built in, anything else a `models.json` row under `azure` with
 * `api: "openai-completions"`).
 *
 * The Chat Completions arm is upstream's `azureStreams(openAICompletionsApi())`: the ordinary
 * completions provider, handed the model with its base URL resolved the Azure way
 * (`resolveAzureModel()`) and the deployment name put in the payload's `model`
 * (`withDeploymentName()`) — "Send the deployment name as the request's model, keeping `model.id`
 * as the catalog id." Both are resolved "inside `lazyStream` so an unconfigured endpoint errors on
 * the stream instead of throwing out of `stream()`".
 */
final class Azure
{
    /** The provider's id, upstream's `azureProvider()` `id`. */
    public const string PROVIDER = 'azure';

    /**
     * `azureStreams(streams).stream(model, context, options)`: $streams is the completions
     * provider's `stream`, handed the resolved model and options.
     *
     * @param Closure(Model, TranscriptContext, OpenAiOptions): AssistantMessageEventStream $streams
     */
    public static function stream(Model $model, TranscriptContext $context, OpenAiOptions $options, Closure $streams): AssistantMessageEventStream
    {
        // `lazyStream(model, async () => streams.stream(resolveAzureModel(model, options), context,
        // withDeploymentName(model, options)))`: a setup failure is the stream's error event.
        try {
            $resolved = self::resolveAzureModel($model, $options);
            $withDeployment = self::withDeploymentName($model, $options);
        } catch (Throwable $error) {
            return self::setupError($model, $error);
        }

        return $streams($resolved, $context, $withDeployment);
    }

    /** Upstream's `resolveAzureModel()`: `{...model, baseUrl: resolveAzureBaseUrl(model, options)}`. */
    private static function resolveAzureModel(Model $model, StreamOptions $options): Model
    {
        return new Model(
            $model->id,
            $model->name,
            $model->api,
            $model->provider,
            AzureOpenAiConfig::resolveAzureBaseUrl($model, $options),
            $model->contextWindow,
            $model->maxTokens,
            $model->reasoning,
            $model->input,
            $model->pricing,
            $model->headers,
            $model->compat,
            $model->thinkingLevelMap,
            $model->inputLimits,
            $model->promptCache,
        );
    }

    /**
     * Upstream's `withDeploymentName()`: the options as they are when the deployment is the model's
     * id, else the same options with an `onPayload` that sets the payload's `model` to the
     * deployment first and then hands it to the caller's own `onPayload`, whose answer wins
     * (`(await options?.onPayload?.(params, payloadModel)) ?? params`).
     */
    private static function withDeploymentName(Model $model, OpenAiOptions $options): OpenAiOptions
    {
        $deploymentName = AzureOpenAiConfig::resolveDeploymentName($model, $options);

        if ($deploymentName === $model->id) {
            return $options;
        }

        $onPayload = $options->onPayload;

        return new OpenAiOptions(...[
            ...$options->baseArgs(),
            'reasoning' => $options->reasoning,
            'toolChoice' => $options->toolChoice,
            'serviceTier' => $options->serviceTier,
            'reasoningSummary' => $options->reasoningSummary,
            'thinkingBudgets' => $options->thinkingBudgets,
            'onPayload' => static function (mixed $payload, Model $payloadModel) use ($deploymentName, $onPayload): mixed {
                $params = [...(array) $payload, 'model' => $deploymentName];

                return ($onPayload !== null ? $onPayload($params, $payloadModel) : null) ?? $params;
            },
        ]);
    }

    /**
     * Upstream's `lazyStream()` catch: `createSetupErrorMessage(model, error)` — an empty message
     * with the error's text — pushed as the one error event, and the stream ended.
     */
    private static function setupError(Model $model, Throwable $error): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $message = new AssistantMessage(
            [],
            $model->api,
            $model->provider,
            $model->id,
            new Usage(0, 0, 0, 0, 0),
            StopReason::Error,
            $error->getMessage(),
        );
        $stream->push(new ErrorEvent(StopReason::Error, $message));
        $stream->end();

        return $stream;
    }
}
