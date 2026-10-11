<?php

declare(strict_types=1);

use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Web\Schedule\ScheduleTool;

/**
 * `builtin:schedule` — scheduled tasks set up by asking: the `schedule` tool (`ScheduleTool`).
 *
 * pig's own built-in, after the Claude app's scheduled tasks; upstream has neither the tasks nor
 * the tool. A built-in rather than core because that is what makes it switchable, as the others
 * are: `-builtin:schedule` in the `extensions` setting and the model is never offered it. The
 * tool itself is in the core beside the scheduler it talks to (`Web\Schedule`), so this file is
 * only the registration.
 */
return static function (ExtensionApi $pi): void {
    $tool = new ScheduleTool();

    $pi->registerTool(new CustomTool(
        name: ScheduleTool::NAME,
        label: 'Schedule',
        description: ScheduleTool::description(),
        parameters: ScheduleTool::parameters(),
        execute: static fn (string $id, array $params, ?Closure $onUpdate, HookContext $ctx) => $tool->execute($params, $ctx),
        promptSnippet: ScheduleTool::promptSnippet(),
        promptGuidelines: ScheduleTool::promptGuidelines(),
    ));
};
