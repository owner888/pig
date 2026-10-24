<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Who requests are attributed to — upstream's `ClientAttribution`. `ClientIdentity::attributionHeaders()`
 * maps the fields to each provider's headers.
 */
final readonly class ClientAttribution
{
    /**
     * @param string $name "App name: OpenRouter app title, NVIDIA invoke origin (first letter upper-cased), OpenCode client."
     * @param string $url "App URL: OpenRouter `HTTP-Referer`, the app's identity in OpenRouter's rankings, grouped by origin."
     * @param list<string> $categories "App categories, e.g. `["cli-agent"]`: OpenRouter `X-OpenRouter-Categories`, comma-separated."
     */
    public function __construct(
        public string $name,
        public string $url,
        public array $categories = [],
    ) {
    }

    /**
     * pig's attribution — upstream's `PI_ATTRIBUTION`, under pig's own name and site (开发者要求: a
     * request says what sent it). Used by the agent loop and the coding agent.
     */
    public static function pig(): self
    {
        return new self('pig', 'https://pigagent.dev', ['cli-agent']);
    }
}
