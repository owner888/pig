<?php

declare(strict_types=1);

namespace Pig\Evals;

use InvalidArgumentException;
use Pig\Ai\Utils\Uuid;
use Pig\Evals\Evals\CustomProviderDocsEval;
use Pig\Evals\Evals\DocumentationAuditEval;
use Pig\Evals\Evals\ExtensionsDocsEval;
use Pig\Evals\Evals\ModelsDocsEval;
use Pig\Evals\Evals\OpenAiProviderDocsEval;
use Pig\Evals\Evals\SmokeEval;
use Pig\Evals\Evals\TuiDocsEval;
use RuntimeException;
use Throwable;

/**
 * Upstream's `packages/evals/src/cli.ts`: orchestrates host evals (`smoke`, `documentation-audit`)
 * and paired documentation-lift comparisons (`without_docs` vs `with_docs`).
 */
final class Cli
{
    /** @var list<string> */
    private const array RUNNER_OPTIONS = ['--provider', '--model', '--runs-per-variant'];

    /** @var list<string> */
    private const array FILTER_OPTIONS = ['-t', '--testNamePattern'];

    /**
     * @param list<string> $args
     * @param array<string, string|null>|null $environment
     * @return array{
     *   provider: ?string,
     *   model: ?string,
     *   runsPerVariant: int,
     *   requestedFiles: list<string>,
     *   discoveryArgs: list<string>,
     * }
     */
    public static function parseEvalCli(array $args, ?array $environment = null): array
    {
        $provider = null;
        $model = null;
        $runsText = null;
        $cliSelectedModel = false;
        $requestedFiles = [];
        $discoveryArgs = [];
        $count = count($args);

        for ($index = 0; $index < $count; $index++) {
            $argument = $args[$index];

            if (str_ends_with($argument, '.docs.eval.ts') || str_ends_with($argument, '.docs.eval.php') || str_ends_with($argument, '.docs')) {
                $requestedFiles[] = self::normalizeDocsEvalFile($argument);

                continue;
            }

            if (in_array($argument, self::FILTER_OPTIONS, true)) {
                $value = $args[$index + 1] ?? null;

                if ($value === null || $value === '') {
                    throw new InvalidArgumentException("Missing value for {$argument}.");
                }

                $discoveryArgs[] = $argument;
                $discoveryArgs[] = $value;
                $index++;

                continue;
            }

            if (str_starts_with($argument, '--testNamePattern=')) {
                if (strlen($argument) === strlen('--testNamePattern=')) {
                    throw new InvalidArgumentException('Missing value for --testNamePattern.');
                }

                $discoveryArgs[] = $argument;

                continue;
            }

            $equals = strpos($argument, '=');
            $name = $equals === false ? $argument : substr($argument, 0, $equals);

            if (in_array($name, self::RUNNER_OPTIONS, true)) {
                $value = $equals === false ? ($args[$index + 1] ?? '') : substr($argument, $equals + 1);

                if ($value === '' || ($equals === false && str_starts_with($value, '-'))) {
                    throw new InvalidArgumentException("Missing value for {$name}.");
                }

                if ($name === '--provider') {
                    $provider = $value;
                } elseif ($name === '--model') {
                    $model = $value;
                } else {
                    $runsText = $value;
                }

                if ($name !== '--runs-per-variant') {
                    $cliSelectedModel = true;
                }

                if ($equals === false) {
                    $index++;
                }

                continue;
            }

            throw new InvalidArgumentException("Unsupported eval argument: {$argument}");
        }

        $provider = trim((string) $provider) ?: null;
        $model = trim((string) $model) ?: null;

        if ($cliSelectedModel) {
            if ($provider === null || $model === null) {
                throw new InvalidArgumentException('CLI model selection requires both --provider and --model.');
            }
        } else {
            $envProvider = $environment !== null
                ? ($environment['PIG_PROVIDER'] ?? $environment['PI_PROVIDER'] ?? null)
                : (self::env('PIG_PROVIDER') ?? self::env('PI_PROVIDER'));
            $envModel = $environment !== null
                ? ($environment['PIG_MODEL'] ?? $environment['PI_MODEL'] ?? null)
                : (self::env('PIG_MODEL') ?? self::env('PI_MODEL'));
            $provider = trim((string) $envProvider) ?: null;
            $model = trim((string) $envModel) ?: null;

            if (($provider !== null) !== ($model !== null)) {
                throw new InvalidArgumentException('Set both PIG_PROVIDER and PIG_MODEL, or neither.');
            }
        }

        $envRuns = $environment !== null
            ? ($environment['PIG_EVAL_RUNS_PER_VARIANT'] ?? $environment['PI_EVAL_RUNS_PER_VARIANT'] ?? null)
            : (self::env('PIG_EVAL_RUNS_PER_VARIANT') ?? self::env('PI_EVAL_RUNS_PER_VARIANT'));
        $configuredRuns = trim((string) ($runsText ?? $envRuns ?? ''));

        if ($configuredRuns === '') {
            $runsPerVariant = 1;
        } else {
            if (preg_match('/^[1-9]\d*$/', $configuredRuns) !== 1) {
                throw new InvalidArgumentException('Runs per variant must be a positive integer.');
            }

            $runsPerVariant = (int) $configuredRuns;
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'runsPerVariant' => $runsPerVariant,
            'requestedFiles' => $requestedFiles,
            'discoveryArgs' => $discoveryArgs,
        ];
    }

    /**
     * Run the documentation-lift comparative eval suite (`without_docs` vs `with_docs`).
     *
     * @param list<string> $args
     */
    public static function runDocsComparison(array $args, ?string $artifactRoot = null): int
    {
        $cli = self::parseEvalCli($args);

        if ($cli['provider'] === null || $cli['model'] === null) {
            throw new RuntimeException('Set PIG_PROVIDER and PIG_MODEL, or pass --provider and --model.');
        }

        $modelSelection = ['provider' => $cli['provider'], 'id' => $cli['model']];
        $modelIdentity = "{$cli['provider']}/{$cli['model']}";
        $allSuites = self::docsSuites();
        $files = $cli['requestedFiles'] !== [] ? $cli['requestedFiles'] : array_keys($allSuites);
        sort($files, SORT_STRING);

        $rawCases = [];

        foreach ($files as $file) {
            if (!isset($allSuites[$file])) {
                throw new RuntimeException("Unknown documentation eval suite: {$file}");
            }

            foreach (($allSuites[$file]['cases'])() as $case) {
                if (self::matchesDiscoveryFilter($case['name'], $cli['discoveryArgs'])) {
                    $rawCases[] = $case;
                }
            }
        }

        $cases = Plan::parseDiscoveredCases($rawCases);

        if ($cases === []) {
            throw new RuntimeException('No documentation eval cases matched the selection.');
        }

        $tasks = Plan::createTaskPlan($cases, $modelIdentity, $cli['runsPerVariant']);
        $artifactDirectory = ($artifactRoot ?? (dirname(__DIR__) . '/.eval')) . '/' . self::artifactRunId();
        mkdir($artifactDirectory, 0o700, true);

        $protocol = [
            'schemaVersion' => 1,
            'model' => $modelIdentity,
            'runsPerVariant' => $cli['runsPerVariant'],
            'files' => $files,
            'cases' => array_map(
                static fn (DiscoveredEvalCase $c): array => ['evalSet' => $c->evalSet, 'caseId' => $c->caseId, 'file' => $c->file],
                $cases,
            ),
            'tasks' => array_map(static fn (EvalTask $t): array => $t->toArray(), $tasks),
        ];
        $protocolText = json_encode($protocol, JSON_THROW_ON_ERROR);
        $protocolDigest = hash('sha256', $protocolText);

        file_put_contents(
            $artifactDirectory . '/protocol.json',
            json_encode([...$protocol, 'protocolDigest' => $protocolDigest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        file_put_contents(
            $artifactDirectory . '/expected-runs.json',
            json_encode(array_map(static fn (EvalTask $t): array => $t->toArray(), $tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        \Pig\Ai\Http\HttpClient::useIdleTimeout(45.0);
        $observations = [];
        $totalTasks = count($tasks);

        foreach ($tasks as $index => $task) {
            $num = $index + 1;
            echo "\n[{$num}/{$totalTasks}] {$task->evalSet} > {$task->caseId} | {$task->variant} | {$task->model} | run {$task->runNumber}\n";

            $reportPath = self::executeDocsTask($task, $modelSelection, $allSuites[$task->file]['run'], $artifactDirectory);
            $observation = $reportPath !== null
                ? Report::readTaskObservation($task, $reportPath, $artifactDirectory)
                : Report::erroredObservation($task);
            $observations[] = $observation;

            $jsonl = implode("\n", array_map(static fn (array $item): string => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $observations)) . "\n";
            file_put_contents($artifactDirectory . '/observations.jsonl', $jsonl);
        }

        $report = Report::summarizeEvalObservations($protocolDigest, $tasks, $observations);
        $reportText = Report::formatEvalComparisonReport($report);
        file_put_contents(
            $artifactDirectory . '/report.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        file_put_contents($artifactDirectory . '/report.txt', $reportText . "\n");

        echo "\n{$reportText}\n";
        echo "\nArtifacts: {$artifactDirectory}\n";

        return $report['blockedPairs'] !== [] ? 1 : 0;
    }

    /**
     * Run host evals (`smoke` and `documentation-audit`).
     *
     * @param list<string> $args
     */
    public static function runHostEvals(array $args): int
    {
        $provider = null;
        $model = null;
        $selected = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--provider') {
                $provider = $args[++$i] ?? throw new InvalidArgumentException('Missing value for --provider.');

                continue;
            }

            if ($arg === '--model') {
                $model = $args[++$i] ?? throw new InvalidArgumentException('Missing value for --model.');

                continue;
            }

            $selected[] = $arg;
        }

        $selection = Harness::resolveModelSelection(
            $provider !== null || $model !== null ? ['provider' => (string) $provider, 'id' => (string) $model] : null,
        );
        $options = ['model' => $selection];

        $runSmoke = $selected === [] || in_array('smoke', $selected, true) || in_array('evals/smoke.eval.ts', $selected, true) || in_array('evals/smoke.eval.php', $selected, true);
        $runAudit = $selected === [] || in_array('documentation-audit', $selected, true) || in_array('evals/documentation-audit.eval.ts', $selected, true) || in_array('evals/documentation-audit.eval.php', $selected, true);

        $failed = 0;

        if ($runSmoke) {
            echo "Running smoke eval ({$selection['provider']}/{$selection['id']})...\n";

            try {
                $res = SmokeEval::run($options);
                printf("  ok   %s > %s (%dms, %d tokens)\n", $res['evalSet'], $res['caseId'], $res['run']['timings']['totalMs'], $res['run']['usage']['totalTokens']);
            } catch (Throwable $e) {
                $failed++;
                printf("  FAIL %s > %s: %s\n", SmokeEval::EVAL_SET, SmokeEval::CASE_ID, $e->getMessage());
            }
        }

        if ($runAudit) {
            $cases = DocumentationAuditEval::cases();
            echo 'Running documentation-audit eval (' . count($cases) . " pages)...\n";

            foreach ($cases as $case) {
                try {
                    $res = DocumentationAuditEval::runPage($case['path'], $options);
                    printf("  ok   %s (%dms, %d tokens)\n", $res['caseId'], $res['run']['timings']['totalMs'], $res['run']['usage']['totalTokens']);
                } catch (Throwable $e) {
                    $failed++;
                    printf("  FAIL %s: %s\n", $case['name'], $e->getMessage());
                }
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * @param array{provider: string, id: string} $modelSelection
     * @param \Closure(array<string, mixed>): array<string, mixed> $runner
     */
    private static function executeDocsTask(
        EvalTask $task,
        array $modelSelection,
        \Closure $runner,
        string $artifactDirectory,
    ): ?string {
        $identity = json_encode([$task->evalSet, $task->caseId, $task->variant, $task->model, $task->runNumber], JSON_THROW_ON_ERROR);
        $taskDir = $artifactDirectory . '/tasks/' . hash('sha256', $identity);
        mkdir($taskDir, 0o700, true);
        $reportPath = $taskDir . '/vitest.json';
        $startedAt = hrtime(true);
        \Pig\Async\Loop::reset();

        try {
            $outcome = $runner([
                'allowHost' => true,
                'variant' => $task->variant,
                'model' => $modelSelection,
            ]);
            printf(
                "  -> score=%s (%dms, %d tools, %d tokens)\n",
                (string) $outcome['score'],
                $outcome['run']['timings']['totalMs'] ?? 0,
                $outcome['run']['usage']['toolCalls'] ?? 0,
                $outcome['run']['usage']['totalTokens'] ?? 0,
            );
            $status = 'passed';
            $meta = [
                'eval' => [
                    'avgScore' => $outcome['score'],
                    'scores' => $outcome['scores'],
                    'thresholdFailed' => false,
                ],
                'harness' => [
                    'name' => $task->variant,
                    'run' => $outcome['run'],
                ],
            ];
        } catch (Throwable $error) {
            $status = 'failed';
            $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1e6);
            echo "  -> errored ({$elapsedMs}ms): {$error->getMessage()}\n";
            $meta = [
                'eval' => [
                    'avgScore' => null,
                    'scores' => [],
                    'thresholdFailed' => true,
                ],
                'harness' => [
                    'name' => $task->variant,
                    'run' => [
                        'output' => null,
                        'usage' => [
                            'provider' => $modelSelection['provider'],
                            'model' => $modelSelection['id'],
                        ],
                        'timings' => ['totalMs' => $elapsedMs],
                        'artifacts' => [],
                        'errors' => [['message' => $error->getMessage()]],
                    ],
                ],
            ];
        }

        file_put_contents($reportPath, json_encode([
            'numFailedTests' => $status === 'failed' ? 1 : 0,
            'numPassedTests' => $status === 'passed' ? 1 : 0,
            'numPendingTests' => 0,
            'numTodoTests' => 0,
            'numTotalTests' => 1,
            'startTime' => (int) (microtime(true) * 1000),
            'success' => $status === 'passed',
            'testResults' => [
                [
                    'message' => '',
                    'name' => '/repo/packages/evals/' . $task->file,
                    'status' => $status,
                    'assertionResults' => [
                        [
                            'ancestorTitles' => [$task->evalSet],
                            'fullName' => "{$task->evalSet} {$task->caseId}",
                            'status' => $status,
                            'title' => $task->caseId,
                            'failureMessages' => [],
                            'meta' => $meta,
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $reportPath;
    }

    /**
     * @return array<string, array{cases: \Closure(): list<array{name: string, file: string}>, run: \Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function docsSuites(): array
    {
        return [
            'evals/extensions.docs.eval.php' => [
                'cases' => ExtensionsDocsEval::cases(...),
                'run' => ExtensionsDocsEval::run(...),
            ],
            'evals/models.docs.eval.php' => [
                'cases' => ModelsDocsEval::cases(...),
                'run' => ModelsDocsEval::run(...),
            ],
            'evals/openai-provider.docs.eval.php' => [
                'cases' => OpenAiProviderDocsEval::cases(...),
                'run' => OpenAiProviderDocsEval::run(...),
            ],
            'evals/custom-provider.docs.eval.php' => [
                'cases' => CustomProviderDocsEval::cases(...),
                'run' => CustomProviderDocsEval::run(...),
            ],
            'evals/tui.docs.eval.php' => [
                'cases' => TuiDocsEval::cases(...),
                'run' => TuiDocsEval::run(...),
            ],
        ];
    }

    private static function normalizeDocsEvalFile(string $path): string
    {
        $base = basename($path);
        $base = (string) preg_replace('/\.docs(?:\.eval\.(?:ts|php))?$/', '.docs.eval.php', $base);

        return 'evals/' . $base;
    }

    /**
     * @param list<string> $discoveryArgs
     */
    private static function matchesDiscoveryFilter(string $name, array $discoveryArgs): bool
    {
        $count = count($discoveryArgs);

        for ($i = 0; $i < $count; $i++) {
            $arg = $discoveryArgs[$i];

            if ($arg === '-t' || $arg === '--testNamePattern') {
                $pattern = $discoveryArgs[++$i] ?? '';

                if ($pattern !== '' && !self::matchesPattern($name, $pattern)) {
                    return false;
                }
            } elseif (str_starts_with($arg, '--testNamePattern=')) {
                $pattern = substr($arg, strlen('--testNamePattern='));

                if ($pattern !== '' && !self::matchesPattern($name, $pattern)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function matchesPattern(string $name, string $pattern): bool
    {
        if (str_contains($name, $pattern)) {
            return true;
        }

        $delimited = '/' . str_replace('/', '\/', $pattern) . '/i';
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($delimited, $name) === 1;
        } finally {
            restore_error_handler();
        }
    }

    private static function artifactRunId(): string
    {
        return str_replace(':', '-', gmdate('Y-m-d\TH:i:s\Z')) . '_' . Uuid::v7();
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
