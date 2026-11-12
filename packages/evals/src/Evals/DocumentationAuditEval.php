<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use FilesystemIterator;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\Evals\Harness;
use Pig\Evals\Judge;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Upstream's `packages/evals/evals/documentation-audit.eval.ts`: audits each documentation page
 * against the repository implementation using a structured terminating tool.
 */
final class DocumentationAuditEval
{
    public const string EVAL_SET = 'Audit documentation against implementation';

    public const string TOOL_NAME = 'submit_documentation_audit';

    /**
     * @return list<array{name: string, file: string, path: string}>
     */
    public static function cases(?string $docsRoot = null): array
    {
        $docsRoot ??= Config::docsPath();
        $pages = self::discoverPages($docsRoot);

        return array_map(
            static fn (string $path): array => [
                'name' => self::EVAL_SET . ' > ' . "{$path} matches the implementation",
                'file' => 'evals/documentation-audit.eval.php',
                'path' => $path,
            ],
            $pages,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function runPage(string $path, array $options = [], ?string $docsRoot = null): array
    {
        $repositoryRoot = Config::packageDir();
        $docsRoot ??= Config::docsPath();
        $documentationPath = rtrim($docsRoot, '/') . '/' . ltrim($path, '/');
        $submitAudit = self::submitAuditTool();

        $harness = Harness::createPigCodingAgentHarness([
            ...$options,
            'name' => 'documentation-page-audit',
            'tools' => ['read', 'grep', 'find', 'ls', self::TOOL_NAME],
            'customTools' => [$submitAudit],
            'allowedPaths' => [$repositoryRoot, $docsRoot],
        ]);

        $toolName = self::TOOL_NAME;
        $prompt = <<<PROMPT
Audit this Pig documentation page against the repository implementation.

Documentation page: {$documentationPath}
Repository root: {$repositoryRoot}

Read the complete page and the relevant implementation. Report a mismatch only for a clear, user-visible contradiction between an explicit documentation claim and actual runtime behavior. Follow the runtime path; names, comments, types, isolated helpers, and tests are not sufficient evidence by themselves.

Missing internal detail, ambiguous wording, hypothetical misuse, and undocumented edge cases are not mismatches. If the evidence is not decisive, report inconclusive. Otherwise report match.

For a mismatch, quote the claim, cite the implementation path and symbol, and state the concrete behavior a user would observe.

Call {$toolName} exactly once as your final action. Do not return prose.
PROMPT;

        $result = Async::run(static fn (): array => $harness($prompt));
        $auditCalls = array_values(array_filter(
            Judge::extractToolCalls($result),
            static fn (array $call): bool => $call['name'] === self::TOOL_NAME,
        ));

        if (count($auditCalls) !== 1) {
            throw new RuntimeException('Expected exactly 1 call to ' . self::TOOL_NAME . ', got ' . count($auditCalls));
        }

        $auditCall = $auditCalls[0];

        if ($auditCall['status'] !== 'ok') {
            throw new RuntimeException(self::TOOL_NAME . ' failed.');
        }

        $verdict = $auditCall['arguments']['verdict'] ?? null;
        $explanation = (string) ($auditCall['arguments']['explanation'] ?? '');

        if ($verdict !== 'match') {
            throw new RuntimeException("Documentation audit reported {$verdict} for {$path}: {$explanation}");
        }

        return [
            'evalSet' => self::EVAL_SET,
            'caseId' => "{$path} matches the implementation",
            'score' => 1,
            'scores' => [['name' => 'DocumentationAuditJudge', 'score' => 1, 'metadata' => ['verdict' => $verdict, 'explanation' => $explanation]]],
            'run' => $result,
        ];
    }

    public static function submitAuditTool(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::TOOL_NAME,
            label: 'Submit documentation audit',
            description: 'Submit the final verdict after completing the documentation investigation.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'verdict' => [
                        'type' => 'string',
                        'enum' => ['match', 'mismatch', 'inconclusive'],
                    ],
                    'explanation' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 2000,
                    ],
                ],
                'required' => ['verdict', 'explanation'],
                'additionalProperties' => false,
            ],
            execute: static fn (string $toolCallId, array $params): AgentToolResult => new AgentToolResult(
                content: [new TextContent('Documentation audit submitted.')],
                details: $params,
                terminate: true,
            ),
            promptSnippet: 'Submit the final documentation audit as validated structured data',
            constrainedSampling: ['type' => 'json_schema', 'strict' => 'prefer'],
        );
    }

    /**
     * @return list<string>
     */
    public static function discoverPages(string $docsRoot): array
    {
        if (!is_dir($docsRoot)) {
            return [];
        }

        $pages = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'md') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($docsRoot, '/')) + 1));
            $pages[] = $relative;
        }

        sort($pages, SORT_STRING);

        return $pages;
    }
}
