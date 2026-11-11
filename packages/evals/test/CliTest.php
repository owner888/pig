<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Evals\Cli;
use Pig\Test\AssertsThrows;

/**
 * Tests `Cli::parseEvalCli()` option parsing and validation.
 */
final class CliTest extends TestCase
{
    use AssertsThrows;

    public function testParsesExplicitProviderModelFilesRunsAndFilter(): void
    {
        $parsed = Cli::parseEvalCli([
            'evals/extensions.docs.eval.ts',
            '--provider',
            'openai-codex',
            '--model=gpt-5.6-sol',
            '--runs-per-variant',
            '3',
            '-t',
            'adds the model',
        ], []);

        $this->assertSame([
            'provider' => 'openai-codex',
            'model' => 'gpt-5.6-sol',
            'runsPerVariant' => 3,
            'requestedFiles' => ['evals/extensions.docs.eval.php'],
            'discoveryArgs' => ['-t', 'adds the model'],
        ], $parsed);
    }

    public function testUsesEnvironmentDefaultsWhenCliDoesNotSelectAModel(): void
    {
        $parsed = Cli::parseEvalCli([], [
            'PIG_PROVIDER' => 'anthropic',
            'PIG_MODEL' => 'claude-sonnet-4-5',
            'PIG_EVAL_RUNS_PER_VARIANT' => '2',
        ]);

        $this->assertSame('anthropic', $parsed['provider']);
        $this->assertSame('claude-sonnet-4-5', $parsed['model']);
        $this->assertSame(2, $parsed['runsPerVariant']);
    }

    public function testRejectsIncompleteCliModelAndInvalidArguments(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Cli::parseEvalCli(['--provider', 'anthropic'], []),
            'both --provider and --model',
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Cli::parseEvalCli([], ['PIG_PROVIDER' => 'anthropic']),
            'Set both PIG_PROVIDER and PIG_MODEL',
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Cli::parseEvalCli(['--runs-per-variant', '0'], []),
            'positive integer',
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Cli::parseEvalCli(['--unknown'], []),
            'Unsupported eval argument',
        );
    }
}
