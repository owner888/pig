<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use InvalidArgumentException;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\StopReason;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CodingAgentError;

/**
 * The virtual models the extensions registered, with their routers — the virtual-model half of
 * upstream's `ModelRuntime`.
 *
 * One per process, reached through `current()`, for `McpServerRegistry`'s reason: the catalogue
 * row goes into `Models` (`registerVirtual()`), which is static, and the router that goes with it
 * has to be found from wherever a request is being prepared. `reset()` is the reload and the
 * test boundary, and takes the rows out of `Models` with it.
 */
final class VirtualModelRegistry
{
    /** Custom entry type that stores router state on the session branch — upstream's `VIRTUAL_MODEL_STATE_ENTRY`. */
    public const string STATE_ENTRY = 'pi.virtual-model-state';

    private static ?self $current = null;

    /** @var array<string, VirtualModelDefinition> keyed `provider/id` */
    private array $definitions = [];

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /** Forget every registration: the extensions are being loaded again, or a test is starting over. */
    public static function reset(): void
    {
        foreach (self::$current?->definitions ?? [] as $definition) {
            Models::forgetVirtual($definition->provider, $definition->id);
        }

        self::$current = null;
    }

    /**
     * Register or replace a virtual model. Upstream's `registerVirtualModel()`: the same provider
     * and id again replaces it; an id that is a physical model's of that provider is refused.
     *
     * @throws InvalidArgumentException
     */
    public function register(VirtualModelDefinition $definition): void
    {
        if (trim($definition->provider) === '' || trim($definition->id) === '') {
            throw new InvalidArgumentException('Virtual model provider and id must not be empty.');
        }

        $existing = Models::find($definition->provider, $definition->id);

        if ($existing !== null && !Models::isVirtual($existing)) {
            throw new InvalidArgumentException("Virtual model {$definition->provider}/{$definition->id} conflicts with a physical model.");
        }

        $this->definitions[$definition->provider . '/' . $definition->id] = $definition;
        Models::registerVirtual(self::model($definition));
    }

    public function unregister(string $provider, string $id): void
    {
        if (!isset($this->definitions[$provider . '/' . $id])) {
            return;
        }

        unset($this->definitions[$provider . '/' . $id]);
        Models::forgetVirtual($provider, $id);
    }

    public function get(string $provider, string $id): ?VirtualModelDefinition
    {
        return $this->definitions[$provider . '/' . $id] ?? null;
    }

    /** @return list<VirtualModelDefinition> in registration order */
    public function list(): array
    {
        return array_values($this->definitions);
    }

    /**
     * Ask a virtual model's router for the physical model and thinking level of one request —
     * upstream's `ModelRuntime.resolveModel()`. The router must answer a physical catalogue model
     * whose provider has credentials; the thinking level is clamped to that model.
     *
     * `previous` reports the latest successful response in $messages. A retry passes the failed
     * response as $failed; $messages no longer contains it. $state is what the caller stored last
     * on the branch, and the caller stores what comes back.
     *
     * @param list<mixed> $messages
     * @param ModelRouteRequest::USER|ModelRouteRequest::CONTINUATION|ModelRouteRequest::RETRY|ModelRouteRequest::DIRECT $reason
     * @param Closure(Model): bool $hasKey whether the physical model's provider has credentials
     * @throws CodingAgentError when the model is not registered or the route is unusable;
     *         whatever the router throws, as it is
     */
    public function resolve(
        Model $model,
        array $messages,
        string $reason,
        ThinkingLevel $thinkingLevel,
        Closure $hasKey,
        ?AbortSignal $signal = null,
        ?AssistantMessage $failed = null,
        mixed $state = null,
    ): ModelRoute {
        $name = "Virtual model {$model->provider}/{$model->id}";
        $definition = $this->get($model->provider, $model->id);

        if ($definition === null) {
            throw new CodingAgentError("{$name} is not registered.");
        }

        $latest = self::latestResponse($messages);
        $previousModel = $latest === null ? null : self::physical($latest->provider, $latest->model);
        // A failed routing attempt names the virtual model; there is no physical request to report.
        $failedModel = $failed === null ? null : self::physical($failed->provider, $failed->model);

        $route = ($definition->route)(new ModelRouteRequest(
            $model,
            $thinkingLevel,
            $reason,
            $previousModel === null ? null : new RoutedModel($previousModel, self::levelOf($latest)),
            $failedModel === null ? null : new RoutedModel($failedModel, self::levelOf($failed), $failed),
            $state,
            $messages,
            $signal,
        ));

        if (!$route instanceof ModelRoute) {
            throw new CodingAgentError("{$name} answered with something that was not a route.");
        }

        $target = self::physical($route->model->provider, $route->model->id);
        $routed = "{$name} routed to {$route->model->provider}/{$route->model->id}";

        if ($target === null) {
            throw new CodingAgentError("{$routed}, which is not a physical model.");
        }

        if (!$hasKey($target)) {
            throw new CodingAgentError("{$routed}, which has no credentials.");
        }

        return new ModelRoute($target, ThinkingLevel::clampedFor($target, $route->thinkingLevel), $route->state);
    }

    /** The level a response was asked with, when it records one pig's agent knows. */
    private static function levelOf(?AssistantMessage $message): ?ThinkingLevel
    {
        return $message?->thinkingLevel === null ? null : ThinkingLevel::tryFrom($message->thinkingLevel);
    }

    /** A catalogue model that is not virtual — upstream's `getPhysicalModel()`. */
    public static function physical(string $provider, string $id): ?Model
    {
        $model = Models::find($provider, $id);

        return $model !== null && !Models::isVirtual($model) ? $model : null;
    }

    /**
     * The latest successful response — upstream's `findLatestResponse()`. Its model is physical:
     * failed and aborted requests, failed routing included, are skipped.
     *
     * @param list<mixed> $messages
     */
    public static function latestResponse(array $messages): ?AssistantMessage
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($message instanceof AssistantMessage
                && $message->stopReason !== StopReason::Error
                && $message->stopReason !== StopReason::Aborted) {
                return $message;
            }
        }

        return null;
    }

    /**
     * The router state a session branch stores for a virtual model, from the `STATE_ENTRY` notes
     * on it, oldest first — upstream's `getVirtualModelState()`.
     *
     * @param list<\Pig\CodingAgent\Session\CustomEntry> $entries
     */
    public static function stateIn(array $entries, string $provider, string $id): mixed
    {
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $data = $entries[$i]->data;

            if (is_array($data) && ($data['provider'] ?? null) === $provider && ($data['modelId'] ?? null) === $id) {
                return $data['state'] ?? null;
            }
        }

        return null;
    }

    /** The catalogue row — upstream's `createVirtualModel()`. */
    private static function model(VirtualModelDefinition $definition): Model
    {
        $offered = array_map(static fn (ThinkingLevel $level): string => $level->value, $definition->thinkingLevels);
        $map = [];

        foreach (Model::THINKING_LEVELS as $level) {
            $map[$level] = in_array($level, $offered, true) ? $level : null;
        }

        return new Model(
            id: $definition->id,
            name: $definition->name,
            api: Api::Virtual,
            provider: $definition->provider,
            baseUrl: '',
            contextWindow: $definition->contextWindow,
            maxTokens: $definition->maxTokens,
            reasoning: array_filter($offered, static fn (string $level): bool => $level !== 'off') !== [],
            input: $definition->input,
            thinkingLevelMap: $map,
        );
    }
}
