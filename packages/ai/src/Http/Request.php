<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

/** One outgoing request. */
final readonly class Request
{
    /**
     * @param array<string, string> $headers  case-insensitive; host, connection,
     *        accept-encoding and content-length are filled in if absent
     * @param string|null           $body     already encoded — JSON, usually
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }
}
