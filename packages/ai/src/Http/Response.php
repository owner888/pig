<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

/**
 * A response whose head has arrived and whose body has not.
 *
 * Status and headers are known as soon as this exists; the body is read from the
 * socket as it comes, which is what makes token-by-token streaming possible.
 */
final readonly class Response
{
    /** @param array<string, string> $headers lowercased names, duplicates joined with ", " */
    public function __construct(
        public int $status,
        public string $reason,
        public array $headers,
        public ResponseBody $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
