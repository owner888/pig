<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Model;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Settings;

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

    /** @var list<string> the providers this extension registered, to take back on unload */
    private array $providers = [];

    /**
     * @var array<string, array{type: 'boolean'|'string', description: string, default: bool|string|null}>
     *      declared across every extension — `--flag` on the command line is only knowable once
     *      the extensions have loaded, so `bin/pig` asks here after loading and before refusing
     *      an option it does not know
     */
    private static array $flags = [];

    /** @var array<string, bool|string> what the command line said for a declared flag */
    private static array $flagValues = [];

    /** The settings this run was started with; null until `CodingAgent::session()` wires it. */
    private static ?Settings $settings = null;

    /**
     * @var array<string, Closure(string, array{method: string, body: string, query: array<string, string>}): array{status: int, body: mixed}|null>
     *      HTTP routes the extensions registered, by path prefix — see `registerHttpRoute()`
     */
    private static array $httpRoutes = [];

    /** @var array<string, array{key: string, handler: Closure, description: string}> */
    private array $shortcuts = [];

    /** @var list<Closure(string, array{role: string, isStreaming: bool}): string> */
    private array $markdownTransformers = [];

    /** @var list<Closure(string, Closure(): ?array): ?array> */
    private array $toolRenderers = [];

    public function __construct(
        string $cwd,
        string $path,
        public readonly string $name,
        private readonly ?Auth $auth = null,
        ?Closure $send = null,
        ?Closure $note = null,
        ?Closure $context = null,
        ?EventBus $events = null,
    ) {
        parent::__construct($cwd, $path, $send, $note, $context);
        $this->events = $events ?? new EventBus();
    }

    private readonly EventBus $events;

    /** The channel every extension of this load shares. Upstream's `pi.events`. */
    public function events(): EventBus
    {
        return $this->events;
    }

    /** @return list<string> the tools the model is offered now. Upstream's `getActiveTools()`. */
    public function getActiveTools(): array
    {
        return $this->session()?->activeTools() ?? [];
    }

    /**
     * Every registered tool, active or not, with its description and schema. Upstream's `getAllTools()`.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, active: bool}>
     */
    public function getAllTools(): array
    {
        return $this->session()?->allTools() ?? [];
    }

    /**
     * Offer the model only these tools, from its next request. Upstream's `setActiveTools()`.
     * Nothing is unregistered, so a later call can put any of them back; a name nothing
     * registered is ignored.
     *
     * @param list<string> $names
     */
    public function setActiveTools(array $names): void
    {
        ($this->session() ?? throw new \LogicException('setActiveTools() needs a session — call it from a handler.'))
            ->setActiveTools($names);
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

    // ---- providers ---------------------------------------------------------------------

    /**
     * Bring a provider: models, a protocol, a sign-in. Upstream's `registerProvider()`.
     *
     * Everything a built-in provider is spread across four core classes arrives as one object
     * and goes into `ProviderRegistry`, which those four classes ask when their own tables come
     * up empty — so from the moment this returns, `--model`, `/model`, `/login` and `Stream`
     * all know the provider as if it had shipped with pig. Taken back by `unregisterProvider()`
     * and on `/reload`, which loads the extensions again.
     */
    public function registerProvider(Provider $provider): void
    {
        ProviderRegistry::register($provider);
        $this->providers[] = $provider->id;
    }

    public function unregisterProvider(string $id): void
    {
        ProviderRegistry::unregister($id);
        $this->providers = array_values(array_filter($this->providers, static fn (string $one): bool => $one !== $id));
    }

    /** @return list<string> @internal */
    public function providers(): array
    {
        return $this->providers;
    }

    // ---- flags -------------------------------------------------------------------------

    /**
     * Declare a command-line flag. Upstream's `registerFlag()`.
     *
     * `--name` for a boolean, `--name <value>` for a string. The value is what `bin/pig` saw;
     * `getFlag()` answers the default until the command line has been read, which is after
     * every extension has loaded — so a factory must not read its own flag at load, only a
     * handler may. Declared under the extension's own name: two extensions declaring one flag
     * is the second one's mistake and is said so.
     *
     * @param 'boolean'|'string' $type
     */
    public function registerFlag(string $name, string $type = 'boolean', string $description = '', bool|string|null $default = null): void
    {
        $name = ltrim(trim($name), '-');

        if ($name === '' || !in_array($type, ['boolean', 'string'], true)) {
            throw new \InvalidArgumentException("A flag needs a name and a type of 'boolean' or 'string'.");
        }

        if (isset(self::$flags[$name])) {
            throw new \InvalidArgumentException("--{$name} is already declared by another extension.");
        }

        self::$flags[$name] = ['type' => $type, 'description' => $description, 'default' => $default];
    }

    /** What the command line said for a declared flag, or its default; null for one nobody declared. */
    public function getFlag(string $name): bool|string|null
    {
        $name = ltrim($name, '-');

        if (!isset(self::$flags[$name])) {
            return null;
        }

        return self::$flagValues[$name] ?? self::$flags[$name]['default'];
    }

    /**
     * Every flag the loaded extensions declared, for `bin/pig` to accept and for `--help`.
     *
     * @return array<string, array{type: 'boolean'|'string', description: string, default: bool|string|null}>
     */
    public static function declaredFlags(): array
    {
        return self::$flags;
    }

    /**
     * What the command line said. `bin/pig` calls this once, after parsing, with the options it
     * did not recognise itself; a boolean flag's presence is `true`, a string flag's value is
     * the string.
     *
     * @param array<string, string> $options
     */
    public static function applyFlags(array $options): void
    {
        foreach (self::$flags as $name => $flag) {
            if (!array_key_exists($name, $options)) {
                continue;
            }

            self::$flagValues[$name] = $flag['type'] === 'boolean' ? true : $options[$name];
        }
    }

    /** For tests and `/reload`. */
    public static function forgetFlags(): void
    {
        self::$flags = [];
        self::$flagValues = [];
    }

    // ---- the web UI --------------------------------------------------------------------

    /**
     * Answer HTTP requests under a path prefix in `pig web`.
     *
     * pig's own, with no upstream counterpart: pi-web is a separate package that imports nothing
     * from an extension. Here the web shell loads the extensions as the terminal does, and an
     * extension with something to show a browser — the Antigravity accounts panel — registers
     * the endpoints its page calls rather than having them written into `HttpServer`. The handler
     * gets the path and the request and answers a status and a JSON-encodable body; null means
     * "not mine", so a prefix can be shared. A throw is a 500 with the message, which is the
     * same fault isolation every other handler in the server has.
     *
     * @param Closure(string, array{method: string, body: string, query: array<string, string>}): (array{status: int, body: mixed}|null) $handler
     */
    public function registerHttpRoute(string $prefix, Closure $handler): void
    {
        $prefix = '/' . trim($prefix, '/');

        if ($prefix === '/') {
            throw new \InvalidArgumentException('An HTTP route needs a prefix under the root.');
        }

        self::$httpRoutes[$prefix] = $handler;
    }

    /**
     * The handler for a path, by longest matching prefix, or null.
     *
     * @return Closure(string, array{method: string, body: string, query: array<string, string>}): (array{status: int, body: mixed}|null)|null
     */
    public static function httpRouteFor(string $path): ?Closure
    {
        $best = null;
        $length = 0;

        foreach (self::$httpRoutes as $prefix => $handler) {
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && strlen($prefix) > $length) {
                $best = $handler;
                $length = strlen($prefix);
            }
        }

        return $best;
    }

    /** For tests and `/reload`. */
    public static function forgetHttpRoutes(): void
    {
        self::$httpRoutes = [];
    }

    // ---- the session, from an extension's side ------------------------------------------

    /** The settings this run started with. Upstream's `getSettings()`. */
    public static function useSettings(?Settings $settings): void
    {
        self::$settings = $settings;
    }

    public function getSettings(): ?Settings
    {
        return self::$settings;
    }

    /**
     * Switch the session's model. Upstream's `setModel()`: false when there is no key for it
     * or no session to switch — a refusal, not a failure, because a hook asking for a model
     * this machine cannot reach has nothing to do about it.
     */
    public function setModel(Model $model): bool
    {
        $session = $this->session();

        if ($session === null) {
            return false;
        }

        try {
            $session->setModel($model);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function getModel(): ?Model
    {
        return $this->session()?->model();
    }

    public function getThinkingLevel(): ?ThinkingLevel
    {
        return $this->session()?->thinkingLevel();
    }

    public function setThinkingLevel(ThinkingLevel $level): void
    {
        $this->session()?->setThinkingLevel($level);
    }

    /**
     * Put a *user* message into the conversation, as if typed. Upstream's `sendUserMessage()`.
     *
     * Different from `sendMessage()`, which is a hook's own message under its own type: this is
     * the person's voice, and reaches the model as a user turn. `steer` cuts in after the tool
     * that is running; `followUp` waits for the turn to end; with the agent idle either one
     * starts a turn.
     *
     * @param 'steer'|'followUp' $deliverAs
     */
    public function sendUserMessage(string $text, string $deliverAs = 'followUp'): void
    {
        $session = $this->session() ?? throw new \LogicException('sendUserMessage() needs a session — call it from a handler.');

        if (!$session->isStreaming()) {
            $session->prompt($text, source: 'extension');

            return;
        }

        $deliverAs === 'steer' ? $session->steer($text, 'extension') : $session->followUp($text, 'extension');
    }

    /** Name a point in the conversation, or clear the name with null. Upstream's `setLabel()`. */
    public function setLabel(string $entryId, ?string $label): void
    {
        $this->session()?->store()?->appendLabel($entryId, $label);
    }

    /** The commands every hook and extension registered, by name, for a help screen of its own. @return list<string> */
    public function getCommands(): array
    {
        $session = $this->session();

        if ($session === null) {
            return array_keys($this->commands());
        }

        [$all] = $session->hooks()?->commands() ?? [[]];

        return array_keys($all);
    }

    private function session(): ?\Pig\CodingAgent\Session\AgentSession
    {
        return $this->contextNow()?->session;
    }

    /**
     * @return list<CustomTool>
     * @internal
     */
    /**
     * Register a keyboard shortcut. Upstream's `registerShortcut()`.
     *
     * @param callable(\Pig\CodingAgent\Hooks\HookContext): void $handler
     */
    public function registerShortcut(string $shortcut, callable $handler, string $description = ''): void
    {
        $this->shortcuts[strtolower($shortcut)] = [
            'key' => $shortcut,
            'handler' => Closure::fromCallable($handler),
            'description' => $description,
        ];
    }

    /** @return array<string, array{key: string, handler: Closure, description: string}> */
    public function shortcuts(): array
    {
        return $this->shortcuts;
    }

    /**
     * Transform user and assistant Markdown before it is rendered in the transcript.
     * Upstream's `registerMarkdownTransformer()`.
     *
     * @param callable(string, array{role: string, isStreaming: bool}): string $transformer
     */
    public function registerMarkdownTransformer(callable $transformer): void
    {
        $this->markdownTransformers[] = Closure::fromCallable($transformer);
    }

    /** @return list<Closure(string, array{role: string, isStreaming: bool}): string> */
    public function markdownTransformers(): array
    {
        return $this->markdownTransformers;
    }

    /**
     * Choose how calls to a tool are drawn. Upstream's `registerToolRenderer()`.
     *
     * The resolver is `fn (string $toolName, Closure $next): ?array{renderCall?: Closure, renderResult?: Closure}`.
     * Resolvers run in extension load order as an onion chain.
     */
    public function registerToolRenderer(callable $resolver): void
    {
        $this->toolRenderers[] = Closure::fromCallable($resolver);
    }

    /** @return list<Closure> */
    public function toolRenderers(): array
    {
        return $this->toolRenderers;
    }

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
