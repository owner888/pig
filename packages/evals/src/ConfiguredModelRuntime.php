<?php

declare(strict_types=1);

namespace Pig\Evals;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomModels;
use Pig\CodingAgent\ModelRefresh;
use Pig\CodingAgent\ModelRegistry;

/**
 * Isolated `ModelRuntime` equivalent for `ConfiguredRuntime` (`packages/evals/evals/configured-runtime.ts`),
 * reading `<agentDir>/models.json` and `<agentDir>/auth.json` without touching the host's files.
 */
final class ConfiguredModelRuntime
{
    private ?string $error = null;

    private readonly Auth $auth;

    private readonly ModelRegistry $registry;

    public function __construct(
        private readonly string $agentDir,
        private readonly bool $allowModelNetwork = false,
    ) {
        $this->auth = Auth::discover($this->agentDir . '/auth.json');
        $this->registry = new ModelRegistry($this->auth);
        $this->refresh($this->allowModelNetwork);
    }

    public function refresh(bool $allowNetwork = false): void
    {
        $this->error = null;
        $modelsPath = $this->agentDir . '/models.json';
        $custom = CustomModels::load($modelsPath);
        $fatalProblems = array_values(array_filter(
            $custom->problems,
            static fn (string $problem): bool => !str_contains($problem, '"apiKey" references '),
        ));

        if ($fatalProblems !== []) {
            $this->error = 'Failed to parse models.json: ' . implode('; ', $fatalProblems);
        }

        $custom->install($this->auth);

        if ($allowNetwork) {
            ModelRefresh::refresh($this->auth, allowNetwork: true);
        }
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getModel(string $providerId, string $modelId): ?Model
    {
        return $this->registry->find($providerId, $modelId);
    }

    public function completeSimple(
        Model $model,
        Context|TranscriptContext $context,
        ?SimpleStreamOptions $options = null,
    ): AssistantMessage {
        return $this->registry->streamSimple($model, $context, $options)->result()->await();
    }
}
