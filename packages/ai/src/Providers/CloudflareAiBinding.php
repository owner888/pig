<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Closure;
use TypeError;

/**
 * Upstream's `api/cloudflare-ai-binding.ts`, the part that exists outside a Cloudflare Worker.
 *
 * Upstream: "A Worker avoids that token by talking to the gateway through the AI binding's `fetch`
 * passthrough (`env.AI.fetch()`), which serves the gateway's provider passthrough at
 * `https://workers-binding.ai/ai-gateway/gateways/{gateway}/{provider}/{endpoint...}` … A model
 * whose `baseUrl` names that route therefore needs no translation: point it there and pass
 * `createAiBindingFetch` as the request `fetch`."
 *
 * The binding is a Workers runtime object and the `fetch` it is handed to is a request option pig
 * does not have (`StreamOptions` has no `fetch`), so what is ported is the sentinel and the
 * function's own check, for a caller that holds something with a `fetch` method; nothing in pig
 * passes the returned closure to a provider.
 */
final class CloudflareAiBinding
{
    /**
     * "Placeholder value for auth headers on binding-routed requests. API implementations require an
     * API key or a recognized auth header (`authorization`, `x-api-key`, `cf-aig-authorization`)
     * before dispatch; binding calls are pre-authenticated, so pass `cf-aig-authorization: Bearer
     * ${CLOUDFLARE_GATEWAY_BINDING_AUTH_SENTINEL}` to satisfy the check."
     */
    public const string CLOUDFLARE_GATEWAY_BINDING_AUTH_SENTINEL = 'cloudflare-gateway-binding';

    /**
     * Upstream's `createAiBindingFetch(binding)`: "`fetch` is optional on the type, so its presence is
     * checked here — early, rather than as a confusing failure on the first inference request", and
     * bound eagerly.
     *
     * @return Closure(mixed $input, mixed $init = null): mixed
     */
    public static function createAiBindingFetch(object $binding): Closure
    {
        if (!method_exists($binding, 'fetch')) {
            throw new TypeError('createAiBindingFetch: the AI binding does not expose fetch()');
        }

        $bindingFetch = Closure::fromCallable([$binding, 'fetch']);

        return static fn (mixed $input, mixed $init = null): mixed => $bindingFetch($input, $init);
    }
}
