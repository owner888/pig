<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Evals\Cli;
use Pig\Evals\Evals\PromptTemplatesDocsEval;
use Pig\Evals\Plan;
use Pig\Test\AssertsThrows;

/**
 * `PromptTemplatesDocsEval`'s two scenarios are two eval sets in one suite file, as upstream's
 * `prompt-templates.docs.eval.ts` registers two `describeEval`s: each must be a case of its own,
 * and a run must run the one its task names.
 */
final class PromptTemplatesDocsEvalTest extends TestCase
{
    use AssertsThrows;

    public function testDiscoversOneCasePerScenario(): void
    {
        $cases = Plan::parseDiscoveredCases(PromptTemplatesDocsEval::cases());

        $this->assertSame(
            ['Create a project prompt template', 'Create a user prompt template'],
            array_map(static fn ($case): string => $case->evalSet, $cases),
        );
        $this->assertSame(
            ['creates, reloads, and invokes /current-time', 'creates, reloads, and invokes /current-time'],
            array_map(static fn ($case): string => $case->caseId, $cases),
        );
        $this->assertArrayHasKey('evals/prompt-templates.docs.eval.php', Cli::docsSuites());
    }

    public function testRefusesARunThatNamesNoScenario(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => PromptTemplatesDocsEval::run(['evalSet' => 'Create a team prompt template']),
            'Unknown prompt template scenario',
        );
    }
}
