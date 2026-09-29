<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Hooks\HookApi;

/**
 * What an extension factory is handed to register capabilities.
 *
 * Upstream pi's `ExtensionAPI` concept: an extension can register commands, tools,
 * lifecycle event handlers, custom message renderers, and interactive UI dialogs,
 * all from a single extension definition.
 *
 * Inherits from `HookApi` so any existing hook or extension typehinting `HookApi`
 * remains 100% compatible.
 */
final class ExtensionApi extends HookApi
{
    /** @var list<CustomTool> */
    private array $tools = [];

    public function __construct(
        string $cwd,
        string $path,
        public readonly string $name,
        private readonly ?Auth $auth = null,
        ?Closure $send = null,
        ?Closure $note = null,
        ?Closure $context = null,
    ) {
        parent::__construct($cwd, $path, $send, $note, $context);
    }

    /**
     * Register a tool that the model can call.
     */
    public function registerTool(CustomTool $tool): void
    {
        $this->tools[] = $tool;
    }

    /**
     * Access authentication storage and credentials if available.
     */
    public function auth(): ?Auth
    {
        return $this->auth;
    }

    /**
     * @return list<CustomTool>
     * @internal
     */
    public function tools(): array
    {
        return $this->tools;
    }
}
