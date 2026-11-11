<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use Pig\Evals\Harness;
use Pig\Evals\Judge;
use Pig\Evals\RecordingTerminal;
use Pig\Tui\Ansi;
use RuntimeException;
use Throwable;

/**
 * Upstream's `packages/evals/evals/tui.docs.eval.ts`: customize the interactive context footer to
 * show a 10-cell progress bar while preserving the rest of the built-in status line.
 */
final class TuiDocsEval
{
    public const string EVAL_SET = 'Customize the interactive context footer';

    public const string CASE_ID = 'replaces numeric context usage with a progress bar';

    public const int CONTEXT_WINDOW = 272_000;

    public const string BUILT_IN_CONTEXT_PATTERN = '/\d+(?:\.\d+)?%\/272k(?: \(auto\))?/';

    /**
     * @var list<array{percent: float|int, expectedBar: string}>
     */
    public const array CONTEXT_FIXTURES = [
        ['percent' => 42.2, 'expectedBar' => '████░░░░░░ 42%'],
        ['percent' => 65, 'expectedBar' => '███████░░░ 65%'],
        ['percent' => 120, 'expectedBar' => '██████████ 100%'],
    ];

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/tui.docs.eval.php',
            ],
        ];
    }

    /**
     * Normalized Levenshtein similarity: 1 for identical strings, 0 for completely different ones.
     */
    public static function levenshteinSimilarity(string $a, string $b): float
    {
        $aChars = mb_str_split($a, 1, 'UTF-8');
        $bChars = mb_str_split($b, 1, 'UTF-8');
        $aLen = count($aChars);
        $bLen = count($bChars);
        $maxLength = max($aLen, $bLen);

        if ($maxLength === 0) {
            return 1.0;
        }

        $previous = range(0, $bLen);

        for ($i = 1; $i <= $aLen; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $bLen; $j++) {
                $cost = $aChars[$i - 1] === $bChars[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }

            $previous = $current;
        }

        return 1.0 - $previous[$bLen] / $maxLength;
    }

    /**
     * @param list<LoadedExtension> $extensions
     * @param list<ExtensionError> $extensionErrors
     * @return array{
     *   builtInStatusLine: string|null,
     *   observations: list<array{percent: float|int, renderedOutput: string, contextUsage: array{tokens: int|null, contextWindow: int, percent: float|null}|null}>,
     *   extensionErrors: list<string>,
     * }
     */
    public static function inspectContextFooter(
        AgentSession $session,
        string $agentDir,
        array $extensions = [],
        array $extensionErrors = [],
    ): array {
        $activeModel = $session->model();

        if ($activeModel === null) {
            throw new RuntimeException('TUI footer oracle requires an active model.');
        }

        $model = new Model(
            $activeModel->id,
            $activeModel->name,
            $activeModel->api,
            $activeModel->provider,
            $activeModel->baseUrl,
            self::CONTEXT_WINDOW,
            $activeModel->maxTokens,
            $activeModel->reasoning,
            $activeModel->input,
            $activeModel->pricing,
            $activeModel->headers,
            $activeModel->compat,
            $activeModel->thinkingLevelMap,
            $activeModel->inputLimits,
            $activeModel->promptCache,
        );

        $cwd = $session->cwd();
        $settings = Settings::load($cwd);
        $auth = Auth::discover($agentDir . '/auth.json');
        $diskExtensions = array_values(array_filter(
            $extensions,
            static fn (LoadedExtension $e): bool => !str_starts_with($e->path, '<inline:'),
        ));
        $runner = new ExtensionRunner($diskExtensions, $cwd);
        $runtimeErrors = array_map(static fn (ExtensionError $e): string => $e->message, $extensionErrors);
        $runner->onError(static function (string $error) use (&$runtimeErrors): void {
            $runtimeErrors[] = $error;
        });
        $store = SessionManager::previewOf($cwd, [
            new AssistantMessage(
                [new TextContent('Context usage fixture')],
                $model->api,
                $model->provider,
                $model->id,
                new Usage(
                    input: 106_000,
                    output: 5_800,
                    cacheRead: 606_000,
                    cacheWrite: 0,
                    totalTokens: 717_800,
                    cost: new Cost(input: 1.008, output: 0.0, cacheRead: 0.0, cacheWrite: 0.0, total: 1.008),
                ),
                StopReason::Stop,
            ),
        ]);
        $agent = new Agent(new AgentOptions());
        $agent->setModel($model);
        $agent->setThinkingLevel(ThinkingLevel::High);
        $oracleSession = new AgentSession($agent, $cwd, $store, $settings, $runner, auth: $auth, usesDefaultTools: false);

        $terminal = new RecordingTerminal();
        $previousOffline = getenv('PIG_OFFLINE');
        putenv('PIG_OFFLINE=1');

        try {
            $firstFixture = self::CONTEXT_FIXTURES[0];
            $oracleSession->agent->replaceMessages([self::createContextMessage($model, $firstFixture['percent'])]);

            $builtInFooter = new FooterComponent($oracleSession, $cwd, $settings, $auth);
            $builtInStatusLine = null;

            foreach (array_map(Ansi::strip(...), $builtInFooter->render(100)) as $line) {
                if (preg_match(self::BUILT_IN_CONTEXT_PATTERN, $line) === 1) {
                    $builtInStatusLine = $line;

                    break;
                }
            }

            $mode = new InteractiveMode(
                session: $oracleSession,
                cwd: $cwd,
                version: '0.0.0',
                terminal: $terminal,
                settings: $settings,
                extensionRunner: $runner,
                customTools: new CustomToolSet(),
                auth: $auth,
                extensions: $diskExtensions,
                tuiMode: 'regular',
            );

            $observations = [];

            foreach (self::CONTEXT_FIXTURES as $index => $fixture) {
                $percent = $fixture['percent'];
                $oracleSession->agent->replaceMessages([self::createContextMessage($model, $percent)]);

                if ($index === 0) {
                    $mode->start();
                } else {
                    $terminal->requestResize();
                }

                $renders = $terminal->takeRenders();

                if ($index === 0 && $builtInStatusLine === null) {
                    foreach ($renders as $render) {
                        foreach (self::lines($render) as $line) {
                            if (preg_match(self::BUILT_IN_CONTEXT_PATTERN, $line) === 1) {
                                $builtInStatusLine = $line;

                                break 2;
                            }
                        }
                    }
                }

                $usage = $oracleSession->contextUsage();
                $observations[] = [
                    'percent' => $percent,
                    'renderedOutput' => $renders === [] ? '' : $renders[count($renders) - 1],
                    'contextUsage' => $usage === null ? null : [
                        'tokens' => $usage->tokens,
                        'contextWindow' => $usage->contextWindow,
                        'percent' => $usage->percent,
                    ],
                ];
            }

            return [
                'builtInStatusLine' => $builtInStatusLine,
                'observations' => $observations,
                'extensionErrors' => $runtimeErrors,
            ];
        } finally {
            try {
                if (isset($mode)) {
                    $mode->stop();
                } else {
                    $terminal->stop();
                }
            } catch (Throwable) {
            }

            if ($previousOffline === false) {
                putenv('PIG_OFFLINE');
            } else {
                putenv("PIG_OFFLINE={$previousOffline}");
            }
        }
    }

    /**
     * @return array{name: string, evaluate: \Closure(array<string, mixed>): array{score: float|int, metadata?: array<string, mixed>}}
     */
    public static function contextFooterJudge(): array
    {
        return Judge::create('ContextFooterJudge', static function (array $ctx): array {
            $output = is_array($ctx['output'] ?? null) ? $ctx['output'] : [];
            $valid = ($output['extensionErrors'] ?? []) === [];
            $similarities = [];
            $contextMarker = '{context}';
            $builtInRaw = is_string($output['builtInStatusLine'] ?? null)
                ? (string) preg_replace('/\s+/', ' ', trim($output['builtInStatusLine']))
                : null;
            $reference = $builtInRaw !== null
                ? (string) preg_replace(self::BUILT_IN_CONTEXT_PATTERN, $contextMarker, $builtInRaw)
                : null;

            if ($reference === null || !str_contains($reference, $contextMarker)) {
                $valid = false;
            }

            $observations = is_array($output['observations'] ?? null) ? $output['observations'] : [];

            foreach (self::CONTEXT_FIXTURES as $index => $fixture) {
                $percent = $fixture['percent'];
                $expectedBar = $fixture['expectedBar'];
                $observation = $observations[$index] ?? null;

                if (!is_array($observation) || ($observation['percent'] ?? null) !== $percent) {
                    $valid = false;

                    continue;
                }

                $contextUsage = is_array($observation['contextUsage'] ?? null) ? $observation['contextUsage'] : null;
                $observedPercent = is_int($contextUsage['percent'] ?? null) || is_float($contextUsage['percent'] ?? null)
                    ? round((float) $contextUsage['percent'] * 10) / 10
                    : -1.0;

                if (($contextUsage['contextWindow'] ?? null) !== self::CONTEXT_WINDOW || $observedPercent !== (float) $percent) {
                    $valid = false;
                }

                $actualStatusLine = null;

                foreach (self::lines((string) ($observation['renderedOutput'] ?? '')) as $line) {
                    if (str_contains($line, $expectedBar) || preg_match(self::BUILT_IN_CONTEXT_PATTERN, $line) === 1) {
                        $actualStatusLine = $line;

                        break;
                    }
                }

                if ($actualStatusLine === null) {
                    $valid = false;

                    continue;
                }

                $normalizedStatusLine = (string) preg_replace('/\s+/', ' ', trim($actualStatusLine));

                if (!str_contains($normalizedStatusLine, $expectedBar) || preg_match(self::BUILT_IN_CONTEXT_PATTERN, $normalizedStatusLine) === 1) {
                    $valid = false;

                    continue;
                }

                foreach (self::CONTEXT_FIXTURES as $other) {
                    $otherBar = $other['expectedBar'];

                    if ($otherBar !== $expectedBar && str_contains($normalizedStatusLine, $otherBar)) {
                        $valid = false;
                    }
                }

                if ($reference !== null) {
                    $similarities[] = self::levenshteinSimilarity(
                        str_replace($expectedBar, $contextMarker, $normalizedStatusLine),
                        $reference,
                    );
                }
            }

            $retained = count($similarities) === count(self::CONTEXT_FIXTURES) ? min($similarities) : 0.0;
            $retainedPercent = (int) round($retained * 100);

            return [
                'score' => $valid ? 1 : 0,
                'metadata' => [
                    'rationale' => $valid
                        ? "Footer retained {$retainedPercent}% of the built-in status text."
                        : 'Context progress behavior was incorrect.',
                    'retainedPercent' => $retainedPercent,
                ],
            ];
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $harness = Harness::createPigDocumentationEvalHarness([
            ...$options,
            'output' => static fn (array $ctx): array => self::inspectContextFooter(
                $ctx['session'],
                (string) $ctx['agentDir'],
                is_array($ctx['extensions'] ?? null) ? $ctx['extensions'] : [],
                is_array($ctx['extensionErrors'] ?? null) ? $ctx['extensionErrors'] : [],
            ),
        ]);

        $result = Async::run(static fn (): array => $harness([
            [
                'type' => 'prompt',
                'content' => 'Change the Pig footer replacing the built-in context info, e.g. `42.2%/272k (auto)`, with a ten-cell progress bar to make it show ████░░░░░░ 42% instead. Clamp percentages to the 0-100 range. Just replace the context. Otherwise leave everything else the same.',
            ],
            ['type' => 'reload'],
        ]));

        $judge = self::contextFooterJudge();
        $evaluated = ($judge['evaluate'])(['output' => $result['output'], 'result' => $result]);

        return [
            'evalSet' => self::EVAL_SET,
            'caseId' => self::CASE_ID,
            'score' => $evaluated['score'],
            'scores' => [['name' => $judge['name'], ...$evaluated]],
            'run' => $result,
        ];
    }

    private static function createContextMessage(Model $model, float|int $percent): AssistantMessage
    {
        $tokens = (int) round(($model->contextWindow * $percent) / 100);

        return new AssistantMessage(
            [new TextContent('Context usage fixture')],
            $model->api,
            $model->provider,
            $model->id,
            new Usage(
                input: $tokens,
                output: 0,
                cacheRead: 0,
                cacheWrite: 0,
                totalTokens: $tokens,
                cost: new Cost(input: 0.0, output: 0.0, cacheRead: 0.0, cacheWrite: 0.0, total: 0.0),
            ),
            StopReason::Stop,
        );
    }

    /**
     * @return list<string>
     */
    private static function lines(string $output): array
    {
        $parts = preg_split('/[\r\n]+/', $output) ?: [];

        return array_values(array_filter($parts, static fn (string $line): bool => trim($line) !== ''));
    }
}
