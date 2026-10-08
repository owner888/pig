<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use Generator;

/**
 * ConverseStream's answer: `$metadata` (status and request id) and `stream`, the union members as they
 * are decoded. The first is read by `prime()` before the client hands this over, as the SDK's
 * deserializer reads it; `items()` yields it first and then the rest.
 *
 * @internal
 */
final class BedrockEventStream
{
    /** @var array<string, mixed>|null */
    private ?array $first = null;

    private bool $primed = false;

    /** @param Generator<int, array<string, mixed>> $messages */
    public function __construct(
        public readonly int $status,
        public readonly ?string $requestId,
        private readonly Generator $messages,
    ) {
    }

    /** Decode up to the first message the client models, or to the end. */
    public function prime(): void
    {
        $this->primed = true;
        $this->first = $this->messages->valid() ? $this->messages->current() : null;
    }

    /** @return Generator<int, array<string, mixed>> */
    public function items(): Generator
    {
        if (!$this->primed) {
            $this->prime();
        }

        if ($this->first === null) {
            return;
        }

        yield $this->first;
        $this->messages->next();

        while ($this->messages->valid()) {
            yield $this->messages->current();
            $this->messages->next();
        }
    }
}
