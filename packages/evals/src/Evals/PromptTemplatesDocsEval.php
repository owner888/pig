<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use InvalidArgumentException;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Text;
use Pig\Async\Async;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Prompt\PromptTemplate;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Session\AgentSession;
use Pig\Evals\Harness;
use Pig\Evals\Judge;

/**
 * Upstream's `packages/evals/evals/prompt-templates.docs.eval.ts`: create a `/current-time` prompt
 * template in the project or for the user, reload, and invoke it.
 */
final class PromptTemplatesDocsEval
{
    public const string TEMPLATE_NAME = 'current-time';

    public const string COMMAND = '/' . self::TEMPLATE_NAME;

    public const string TEMPLATE_PROMPT = 'Tell me the current time and timezone.';

    public const string CASE_ID = 'creates, reloads, and invokes ' . self::COMMAND;

    /** @var list<array{title: string, request: string, scope: 'project'|'user'}> */
    public const array SCENARIOS = [
        [
            'title' => 'Create a project prompt template',
            'request' => 'This project is already trusted. Add a ' . self::COMMAND . ' prompt template for this project containing exactly: ' . self::TEMPLATE_PROMPT,
            'scope' => 'project',
        ],
        [
            'title' => 'Create a user prompt template',
            'request' => 'Make a ' . self::COMMAND . ' prompt template available in all my Pig sessions containing exactly: ' . self::TEMPLATE_PROMPT,
            'scope' => 'user',
        ],
    ];

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return array_map(
            static fn (array $scenario): array => [
                'name' => $scenario['title'] . ' > ' . self::CASE_ID,
                'file' => 'evals/prompt-templates.docs.eval.php',
            ],
            self::SCENARIOS,
        );
    }

    /**
     * @param array<string, mixed> $options `evalSet` picks the scenario, as each of upstream's is a suite of its own
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $scenario = self::scenario($options['evalSet'] ?? null);

        $harness = Harness::createPigDocumentationEvalHarness([
            ...$options,
            'output' => static fn (array $ctx): array => self::inspect($ctx, $scenario['scope']),
        ]);

        $result = Async::run(static fn (): array => $harness([
            ['type' => 'prompt', 'content' => $scenario['request']],
            ['type' => 'reload'],
            ['type' => 'prompt', 'content' => self::COMMAND],
        ]));

        $judge = Judge::structuredOutput([
            'template' => ['scope' => $scenario['scope'], 'atExpectedPath' => true],
            'promptDiagnostics' => [],
            'extensions' => [],
            'extensionErrors' => [],
            'skills' => [],
            'otherScopeTemplateExists' => false,
            'expandedToTimeRequest' => true,
        ], 'strict', false);
        $evaluated = ($judge['evaluate'])(['output' => $result['output'], 'result' => $result]);

        return [
            'evalSet' => $scenario['title'],
            'caseId' => self::CASE_ID,
            'score' => $evaluated['score'],
            'scores' => [['name' => $judge['name'], ...$evaluated]],
            'run' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $ctx the harness's output context
     * @param 'project'|'user' $scope
     * @return array<string, mixed>
     */
    public static function inspect(array $ctx, string $scope): array
    {
        /** @var AgentSession $session */
        $session = $ctx['session'];
        $projectPath = $session->cwd() . '/.pig/prompts/' . self::TEMPLATE_NAME . '.md';
        $userPath = $ctx['agentDir'] . '/prompts/' . self::TEMPLATE_NAME . '.md';
        $expectedPath = $scope === 'project' ? $projectPath : $userPath;
        $otherPath = $scope === 'project' ? $userPath : $projectPath;

        $matching = array_values(array_filter(
            $session->fileCommands(),
            static fn (PromptTemplate $template): bool => $template->name === self::TEMPLATE_NAME,
        ));
        $template = count($matching) === 1 ? $matching[0] : null;

        $expandedText = '';

        foreach (array_reverse($session->messages()) as $message) {
            if ($message instanceof UserMessage) {
                $expandedText = trim(Text::contentText($message->content));

                break;
            }
        }

        /** @var list<LoadedExtension> $extensions */
        $extensions = $ctx['extensions'] ?? [];
        /** @var list<ExtensionError> $extensionErrors */
        $extensionErrors = $ctx['extensionErrors'] ?? [];
        /** @var list<Skill> $skills */
        $skills = $ctx['skills'] ?? [];

        return [
            'template' => $template !== null
                ? ['scope' => trim($template->source, '()'), 'atExpectedPath' => $template->path === $expectedPath]
                : null,
            'promptDiagnostics' => array_values($ctx['promptWarnings'] ?? []),
            // The harness's own inline extension is upstream's `hidden` one.
            'extensions' => array_values(array_map(
                static fn (LoadedExtension $extension): string => $extension->path,
                array_filter($extensions, static fn (LoadedExtension $extension): bool => !str_starts_with($extension->path, '<inline:')),
            )),
            'extensionErrors' => array_map(static fn (ExtensionError $error): string => $error->message, $extensionErrors),
            'skills' => array_map(static fn (Skill $skill): string => $skill->name, $skills),
            'otherScopeTemplateExists' => file_exists($otherPath),
            'expandedToTimeRequest' => $expandedText === self::TEMPLATE_PROMPT,
        ];
    }

    /**
     * @return array{title: string, request: string, scope: 'project'|'user'}
     */
    private static function scenario(mixed $evalSet): array
    {
        foreach (self::SCENARIOS as $scenario) {
            if ($scenario['title'] === $evalSet) {
                return $scenario;
            }
        }

        throw new InvalidArgumentException('Unknown prompt template scenario: ' . var_export($evalSet, true));
    }
}
