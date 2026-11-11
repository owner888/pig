<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Evals\DiscoveredEvalCase;
use Pig\Evals\Plan;
use Pig\Test\AssertsThrows;

/**
 * Ported from upstream's `packages/evals/test/plan.test.ts`.
 */
final class PlanTest extends TestCase
{
    use AssertsThrows;

    /** @var list<array{name: string, file: string}> */
    private const array DISCOVERED = [
        ['name' => 'Add model > adds the model', 'file' => 'evals/models.docs.eval.php'],
    ];

    public function testDerivesStableCaseIdentityFromOrdinaryNames(): void
    {
        $this->assertEquals([
            new DiscoveredEvalCase(
                'evals/models.docs.eval.php',
                'Add model > adds the model',
                'Add model',
                'adds the model',
            ),
        ], Plan::parseDiscoveredCases(self::DISCOVERED));
    }

    public function testRejectsAmbiguousAndDuplicateIdentities(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Plan::parseDiscoveredCases([['name' => 'adds the model', 'file' => 'model.php']]),
            '<eval set> > <case>',
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Plan::parseDiscoveredCases([...self::DISCOVERED, ...self::DISCOVERED]),
            'Duplicate eval case identity',
        );
    }

    public function testCreatesOneIsolatedTaskPerCaseVariantModelAndRepetition(): void
    {
        $cases = Plan::parseDiscoveredCases(self::DISCOVERED);
        $tasks = Plan::createTaskPlan($cases, 'fixture/model', 2);

        $this->assertCount(4, $tasks);
        $this->assertSame([
            ['without_docs', 1],
            ['with_docs', 1],
            ['with_docs', 2],
            ['without_docs', 2],
        ], array_map(static fn ($t): array => [$t->variant, $t->runNumber], $tasks));
    }

    public function testRejectsInvalidModelIdentitiesAndRepetitions(): void
    {
        $cases = Plan::parseDiscoveredCases(self::DISCOVERED);

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Plan::createTaskPlan($cases, 'model', 1),
            'provider and model',
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Plan::createTaskPlan($cases, 'fixture/model', 0),
            'positive integer',
        );
    }
}
