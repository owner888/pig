<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use Pig\Ai\ToolResultMessage;
use Pig\Ai\Utils\Text;
use Pig\Async\Async;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Session\AgentSession;
use Pig\Evals\Harness;
use Pig\Evals\Judge;

/**
 * Upstream's `packages/evals/evals/extensions.docs.eval.ts`: create, reload and invoke a tool extension.
 */
final class ExtensionsDocsEval
{
    public const string EVAL_SET = 'Create and use a tool extension';

    public const string CASE_ID = 'creates, reloads, and invokes the extension';

    public const string TOOL_NAME = 'hello';

    /** @var array{name: string} */
    public const array TOOL_ARGUMENTS = ['name' => 'Bob'];

    public const string TOOL_RESULT = 'Hello, Bob!';

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/extensions.docs.eval.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $harness = Harness::createPigDocumentationEvalHarness([
            ...$options,
            'tools' => [...Harness::DOCUMENTATION_EVAL_TOOLS, self::TOOL_NAME],
            'output' => static function (array $ctx): array {
                /** @var AgentSession $session */
                $session = $ctx['session'];
                /** @var list<LoadedExtension> $extensions */
                $extensions = $ctx['extensions'] ?? [];
                /** @var list<ExtensionError> $errors */
                $errors = $ctx['extensionErrors'] ?? [];

                $toolLoaded = false;

                foreach ($extensions as $ext) {
                    foreach ($ext->api->tools() as $tool) {
                        if ($tool instanceof ToolDefinition && $tool->name === self::TOOL_NAME) {
                            $toolLoaded = true;

                            break 2;
                        }
                    }
                }

                $toolResult = null;

                foreach (array_reverse($session->messages()) as $message) {
                    if ($message instanceof ToolResultMessage && $message->toolName === self::TOOL_NAME && !$message->isError) {
                        $toolResult = Text::contentText($message->content);

                        break;
                    }
                }

                return [
                    'response' => $ctx['response'],
                    'extensionErrors' => array_map(
                        static fn (ExtensionError $e): string => "{$e->path}: {$e->message}",
                        $errors,
                    ),
                    'toolLoaded' => $toolLoaded,
                    'toolResult' => $toolResult,
                ];
            },
        ]);

        $result = Async::run(static fn (): array => $harness([
            [
                'type' => 'prompt',
                'content' => 'Configure this running Pig installation with an extension containing a hello tool that takes a name and returns a greeting. Do not create project source. For example, passing Bob should return `Hello, Bob!`.',
            ],
            ['type' => 'reload'],
            [
                'type' => 'prompt',
                'content' => "Use the hello tool to greet Bob. Respond with exactly the tool's greeting and nothing else.",
            ],
        ]));

        $judges = [
            Judge::structuredOutput([
                'response' => self::TOOL_RESULT,
                'extensionErrors' => [],
                'toolLoaded' => true,
                'toolResult' => self::TOOL_RESULT,
            ], 'strict', false),
            Judge::toolCalls([
                ['name' => self::TOOL_NAME, 'arguments' => self::TOOL_ARGUMENTS],
            ]),
        ];

        $scores = [];
        $sum = 0.0;

        foreach ($judges as $judge) {
            $evaluated = ($judge['evaluate'])(['output' => $result['output'], 'result' => $result]);
            $scores[] = ['name' => $judge['name'], ...$evaluated];
            $sum += (float) $evaluated['score'];
        }

        $avgScore = $sum / count($judges);

        return [
            'evalSet' => self::EVAL_SET,
            'caseId' => self::CASE_ID,
            'score' => $avgScore,
            'scores' => $scores,
            'run' => $result,
        ];
    }
}
