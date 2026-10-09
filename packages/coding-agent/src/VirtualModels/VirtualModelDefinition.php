<?php

declare(strict_types=1);

namespace Pig\CodingAgent\VirtualModels;

use Closure;
use Pig\Agent\ThinkingLevel;

/**
 * What an extension registers with `$pi->registerVirtualModel()` — upstream's `VirtualModelDefinition`.
 *
 * A virtual model is a catalogue entry that routes each request to a physical model. The selection
 * (`/model`, `model_change`, `$session->model()`) may name it; everything below the routing step
 * sees physical models only — providers stream them and assistant messages record them. A
 * virtual model never reaches a provider.
 */
final readonly class VirtualModelDefinition
{
    /**
     * @param string $provider the provider it is listed under, which may be one with physical
     *        models of its own or one that exists only for virtual models (and then needs no key)
     * @param string $id must not be the id of a physical model of $provider
     * @param list<ThinkingLevel> $thinkingLevels offered for selection; their meaning is the router's
     * @param int $contextWindow shown before the first response; afterwards the limits of the
     *        physical model that answered apply. 0 is unknown
     * @param list<'text'|'image'> $input accepted for selection
     * @param Closure(ModelRouteRequest): ModelRoute $route picks the physical model, which must
     *        have credentials, and the thinking level for one request. May throw, which fails the
     *        request
     */
    public function __construct(
        public string $provider,
        public string $id,
        public string $name,
        public Closure $route,
        public array $thinkingLevels = [ThinkingLevel::Off],
        public int $contextWindow = 0,
        public int $maxTokens = 0,
        public array $input = ['text', 'image'],
    ) {
    }
}
