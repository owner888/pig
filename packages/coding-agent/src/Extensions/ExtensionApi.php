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

    /** @var array<string, array{label: string, translations: array<string, string>}> */
    private static array $registeredLocales = [];

    /** Told when `tools()` changes after load — see `onToolsChanged()`. */
    private ?Closure $toolsChanged = null;

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
        // A second registration under one name replaces the first, as upstream's does: an MCP
        // server that reconnects lists its tools again, and the new list is the list.
        $this->tools = array_values(array_filter($this->tools, static fn (CustomTool $t): bool => $t->name !== $tool->name));
        $this->tools[] = $tool;
        $this->notifyToolsChanged();
    }

    /**
     * Take tools away again — the ones `$which` says yes to. An MCP server that dropped a tool,
     * or was disabled, has nothing to offer under that name any more.
     *
     * @param Closure(CustomTool): bool $which
     */
    public function removeTools(Closure $which): void
    {
        $before = count($this->tools);
        $this->tools = array_values(array_filter($this->tools, static fn (CustomTool $t): bool => !$which($t)));

        if (count($this->tools) !== $before) {
            $this->notifyToolsChanged();
        }
    }

    /**
     * Who is told when the tool list changes after load. The loader reads `tools()` once at
     * startup; a tool that arrives later — an MCP server connecting — has to reach the agent by
     * another door, and this is it. `CustomToolSet::adopt()` is what sets it.
     *
     * @internal
     */
    public function onToolsChanged(Closure $listener): void
    {
        $this->toolsChanged = $listener;
    }

    private function notifyToolsChanged(): void
    {
        if ($this->toolsChanged !== null) {
            ($this->toolsChanged)($this);
        }
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

    /**
     * Register a custom language pack / locale from this extension.
     *
     * @param string $locale E.g. 'ja', 'es', 'zh-TW'
     * @param string $label E.g. '日本語', 'Español', '繁體中文'
     * @param array<string, string> $translations Key-value translation dictionary
     */
    public function registerLocale(string $locale, string $label, array $translations): void
    {
        $cleanLocale = trim($locale);
        if ($cleanLocale === '') {
            return;
        }

        self::$registeredLocales[$cleanLocale] = [
            'label' => $label !== '' ? $label : $cleanLocale,
            'translations' => $translations,
        ];
    }

    /**
     * Get all custom locales registered across loaded extensions.
     *
     * @return array<string, array{label: string, translations: array<string, string>}>
     */
    public static function registeredLocales(): array
    {
        return self::$registeredLocales;
    }

    /**
     * Reset registered locales (for testing).
     *
     * @internal
     */
    public static function resetRegisteredLocales(): void
    {
        self::$registeredLocales = [];
    }
}
