<?php

declare(strict_types=1);

/**
 * Hello Tool - Minimal custom tool example.
 *
 * Ported from upstream's `examples/extensions/hello.ts`.
 */

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ToolDefinition;

return static function (ExtensionApi $pig): void {
    $pig->registerTool(new ToolDefinition(
        name: 'hello',
        label: 'Hello',
        description: 'A simple greeting tool',
        parameters: [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name to greet',
                ],
            ],
            'required' => ['name'],
        ],
        execute: static function (string $toolCallId, array $params): AgentToolResult {
            $name = (string) ($params['name'] ?? '');

            return new AgentToolResult(
                content: [new TextContent("Hello, {$name}!")],
                details: ['greeted' => $name],
            );
        },
    ));
};
