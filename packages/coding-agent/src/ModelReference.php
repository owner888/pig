<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;

/**
 * What a model reference resolved to — upstream's `ModelReferenceResult`, a union on `status`:
 *
 * - `resolved`: one `$model`.
 * - `ambiguous`: the `$candidates` several providers offer, and how many of them have credentials
 *   (`$usableCount`). Upstream reports this rather than letting catalog order pick the provider (#10160).
 * - `not-found`: nothing matched. `$target` is set when a provider was given or named by a
 *   `provider/` prefix, for building a custom model id.
 */
final readonly class ModelReference
{
    public const string RESOLVED = 'resolved';
    public const string AMBIGUOUS = 'ambiguous';
    public const string NOT_FOUND = 'not-found';

    /** @param list<Model> $candidates */
    private function __construct(
        public string $status,
        public ?Model $model = null,
        public array $candidates = [],
        public int $usableCount = 0,
        public ?ThinkingLevel $thinking = null,
        public ?string $warning = null,
        public ?ModelReferenceTarget $target = null,
    ) {
    }

    public static function resolved(Model $model): self
    {
        return new self(self::RESOLVED, $model);
    }

    /** @param list<Model> $candidates */
    public static function ambiguous(array $candidates, int $usableCount): self
    {
        return new self(self::AMBIGUOUS, candidates: $candidates, usableCount: $usableCount);
    }

    public static function notFound(?ModelReferenceTarget $target = null): self
    {
        return new self(self::NOT_FOUND, target: $target);
    }

    /** The same answer with a thinking level and a warning — upstream's `{ ...inner, thinkingLevel, warning }`. */
    public function with(?ThinkingLevel $thinking, ?string $warning): self
    {
        return new self($this->status, $this->model, $this->candidates, $this->usableCount, $thinking, $warning, $this->target);
    }
}
