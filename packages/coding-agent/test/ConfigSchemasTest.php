<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\Schemas\ConfigSchemas;
use Pig\CodingAgent\Schemas\SchemaErrors;
use Pig\CodingAgent\Session\BranchSummarization;
use Pig\CodingAgent\Session\Compaction;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\ThemeJson;
use Pig\Tui\Keys;
use PigCodemode\CodemodeDescription;

/**
 * Upstream's `config-schemas.test.ts` (#9880, #10751), for schemas kept by hand: what upstream's
 * generation from typebox guarantees, checked here against pig's own definitions, plus upstream's
 * representative documents.
 */
final class ConfigSchemasTest extends TestCase
{
    /** The keywords `SchemaErrors` checks, and the annotations it may ignore. */
    private const array KNOWN_KEYWORDS = [
        '$schema', '$id', '$comment', '$defs', '$ref', 'type', 'required', 'properties', 'patternProperties',
        'additionalProperties', 'items', 'minItems', 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'pattern',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'const', 'enum', 'anyOf',
        'allOf', 'oneOf', 'not', 'minProperties', 'maxProperties',
        'title', 'description', 'default', 'deprecated', 'examples', 'readOnly', 'writeOnly',
    ];

    public function testTheFourSchemasArePublishedUnderPigagentDev(): void
    {
        foreach (ConfigSchemas::NAMES as $name) {
            $schema = ConfigSchemas::load($name);
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
            $this->assertSame("https://pigagent.dev/schemas/{$name}.schema.json", $schema['$id']);
            $this->assertSame(ConfigSchemas::url($name), $schema['$id']);
            $this->assertStringNotContainsString('pi.dev', (string) file_get_contents(ConfigSchemas::path($name)));
            $this->assertSame([], $this->unknownKeywords($schema), "{$name}.schema.json uses a keyword SchemaErrors does not check");
            $this->assertSame([], $this->danglingRefs($schema, $schema), "{$name}.schema.json has a \$ref to nothing");
        }
    }

    public function testKeybindingsSchemaIsTheKeybindingDefinitions(): void
    {
        $schema = ConfigSchemas::load('keybindings');
        $properties = $schema['properties'];
        $this->assertSame(['type' => 'string'], $properties['$schema']);
        unset($properties['$schema']);

        $definitions = Keybindings::keybindingDefinitions('linux', []);
        $this->assertSame(array_keys($definitions), array_keys($properties), 'add the new binding to keybindings.schema.json');

        foreach ($definitions as $id => $definition) {
            $this->assertSame(['$ref' => '#/$defs/KeybindingValue', 'description' => $definition->description], $properties[$id], $id);
        }

        $this->assertSame(['$ref' => '#/$defs/KeybindingValue'], $schema['additionalProperties']);
    }

    public function testEveryDefaultKeyBindingIsOneTheGrammarAccepts(): void
    {
        $schema = ConfigSchemas::load('keybindings');

        foreach (['linux', 'darwin', 'win32'] as $platform) {
            foreach ([[], ['WSL_DISTRO_NAME' => 'Ubuntu']] as $env) {
                foreach (Keybindings::keybindingDefinitions($platform, $env) as $id => $definition) {
                    $this->assertSame([], SchemaErrors::of($schema, [$id => $definition->defaultKeys]), "{$platform} {$id}");
                }
            }
        }
    }

    public function testThemeSchemaIsTheThemeTokens(): void
    {
        $colors = ConfigSchemas::load('theme')['properties']['colors'];

        $this->assertSame(array_keys(ThemeJson::COLOR_TOKENS), array_keys($colors['properties']));
        $this->assertSame(array_keys(array_filter(ThemeJson::COLOR_TOKENS)), $colors['required']);
        $this->assertFalse($colors['additionalProperties']);
    }

    /** Upstream's "preserves editor guidance in the generated theme schema". */
    public function testThemeSchemaKeepsItsEditorGuidance(): void
    {
        $schema = ConfigSchemas::load('theme');

        $this->assertSame('Pig Coding Agent Theme', $schema['title']);
        $this->assertStringContainsString('use compatible fallbacks', $schema['properties']['colors']['description']);
        $this->assertStringContainsString('falls back to muted', $schema['properties']['colors']['properties']['scrollbarTrack']['description']);
        $this->assertSame('Primary accent color (logo, selected items, cursor)', $schema['properties']['colors']['properties']['accent']['description']);
        $this->assertStringContainsString('defaults derived from userMessageBg', $schema['properties']['export']['description']);
        $this->assertStringContainsString('Hex color', $schema['$defs']['ColorValue']['anyOf'][0]['description']);
    }

    /** Upstream's "preserves moved model and settings guidance". */
    public function testModelsAndSettingsSchemasKeepTheirGuidance(): void
    {
        $models = ConfigSchemas::load('models');
        $this->assertStringContainsString('USD per million tokens', $models['$defs']['ModelCost']['properties']['input']['description']);
        $this->assertStringContainsString('highest matching input threshold', $models['$defs']['ModelCost']['properties']['tiers']['description']);
        $this->assertStringContainsString('serialized provider request size', $models['$defs']['ModelInputLimits']['properties']['maxRequestBytes']['description']);

        $settings = ConfigSchemas::load('settings')['properties'];
        $this->assertStringContainsString('hide all startup output', $settings['quietStartup']['description']);
        $this->assertStringContainsString('+name and -name', $settings['defaultTools']['description']);
        $this->assertStringContainsString('1 to 100', $settings['fullscreenWheelScrollLines']['description']);
        $this->assertStringContainsString('provider.maxRetryDelayMs', $settings['retry']['properties']['maxDelayMs']['description']);
    }

    /** Each `default` in the settings schema is what `Settings` answers for a file without the key. */
    public function testSettingsDefaultsAreWhatSettingsAnswers(): void
    {
        $settings = Settings::inMemory([]);
        $codemode = $this->codemodeInlineBudget();
        $answers = [
            'transport' => $settings->transport(),
            'sessionShareDestination' => $settings->sessionShareDestination(),
            'steeringMode' => $settings->steeringMode()->value,
            'followUpMode' => $settings->followUpMode()->value,
            'compaction.enabled' => $settings->compactionEnabled(),
            'compaction.reserveTokens' => Compaction::RESERVE_TOKENS,
            'compaction.keepRecentTokens' => Compaction::KEEP_RECENT_TOKENS,
            'branchSummary.reserveTokens' => BranchSummarization::RESERVE_TOKENS,
            'branchSummary.skipPrompt' => $settings->branchSummarySkipPrompt(),
            'retry.enabled' => $settings->retryEnabled(),
            'retry.maxRetries' => $settings->retrySettings()['maxRetries'],
            'retry.baseDelayMs' => $settings->retrySettings()['baseDelayMs'],
            'retry.maxAgentDelayMs' => $settings->retrySettings()['maxAgentDelayMs'],
            'retry.provider.maxRetryDelayMs' => $settings->providerRetrySettings()['maxRetryDelayMs'],
            'hideThinkingBlock' => $settings->hideThinking(),
            'showCacheMissNotices' => $settings->showCacheMissNotices(),
            'quietStartup' => $settings->quietStartup(),
            'defaultProjectTrust' => $settings->defaultProjectTrust(),
            'collapseChangelog' => $settings->collapseChangelog(),
            'update.check' => $settings->updateCheckEnabled(),
            'enableInstallTelemetry' => $settings->installTelemetry(),
            'enableSkillCommands' => $settings->enableSkillCommands(),
            'terminal.showImages' => $settings->showImages(),
            'terminal.imageWidthCells' => $settings->imageWidthCells(),
            'terminal.clearOnShrink' => $settings->clearOnShrink(),
            'terminal.showTerminalProgress' => $settings->showTerminalProgress(),
            'images.autoResize' => $settings->imageAutoResize(),
            'images.blockImages' => $settings->blockImages(),
            'doubleEscapeAction' => $settings->doubleEscapeAction(),
            'treeFilterMode' => $settings->treeFilterMode(),
            'editorPaddingX' => $settings->editorPaddingX(),
            'outputPad' => $settings->outputPad(),
            'autocompleteMaxVisible' => $settings->autocompleteMaxVisible(),
            'markdown.codeBlockIndent' => $settings->codeBlockIndent(),
            'markdown.mermaid' => $settings->mermaidRenderingMode(),
            'warnings.anthropicExtraUsage' => $settings->warnAnthropicExtraUsage(),
            'codemode.mode' => 'on',
            'codemode.inlineBudget' => $codemode,
            'cacheWarming' => $settings->cacheWarmingMode(),
            'tuiMode' => $settings->tuiMode(),
            'fullscreenExitOutput' => $settings->fullscreenExitOutput(),
            'fullscreenScrollbar' => $settings->fullscreenScrollbar(),
            'fullscreenCopyOnSelect' => $settings->fullscreenCopyOnSelect(),
            'fullscreenWheelScrollLines' => $settings->fullscreenWheelScrollLines(),
            'tokenSpeed.enabled' => $settings->tokenSpeedEnabled(),
        ];

        $defaults = $this->defaults(ConfigSchemas::load('settings'));
        $this->assertSame(array_keys($answers), array_keys($defaults), 'a settings default without a check here, or a check without a default');

        foreach ($defaults as $key => $default) {
            // JSON has one number type: `2000` in the schema is `2000.0` where `Settings` does arithmetic.
            is_int($default) && is_float($answers[$key])
                ? $this->assertEquals($default, $answers[$key], $key)
                : $this->assertSame($default, $answers[$key], $key);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}> */
    public static function representativeDocuments(): iterable
    {
        yield 'models.json' => [
            'models',
            ['providers' => ['local' => [
                'compat' => [
                    'zaiToolStream' => true,
                    'thinkingTokenBudgetField' => 'thinking_budget',
                    'chatTemplateKwargs' => ['budget' => ['$var' => 'thinking.budget']],
                    'supportsMidConvoSystemMessages' => true,
                ],
                'models' => [[
                    'id' => 'model',
                    'inputLimits' => ['maxRequestBytes' => 1024, 'images' => ['maxPerRequest' => 2]],
                    'promptCache' => ['short' => 300],
                ]],
            ]]],
            ['providers' => ['local' => ['models' => [['id' => '']]]]],
        ];
        yield 'settings.json' => [
            'settings',
            [
                'theme' => 'dark',
                'cacheWarming' => 'idle',
                'extensionSetting' => ['enabled' => true],
                'fallbackModels' => ['google/gemini-3.8-flash:medium'],
                'skills' => ['./skills'],
                'retry' => ['maxDelayMs' => 1000],
            ],
            ['cacheWarming' => 'always'],
        ];
        yield 'keybindings.json' => [
            'keybindings',
            ['app.session.new' => 'ctrl+n', 'extension.action' => ['alt+x']],
            ['app.session.new' => 42],
        ];
    }

    /**
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $invalid
     */
    #[DataProvider('representativeDocuments')]
    public function testValidatesRepresentativeDocuments(string $name, array $valid, array $invalid): void
    {
        $schema = ConfigSchemas::load($name);
        $this->assertSame([], SchemaErrors::of($schema, $valid, assoc: true));
        $this->assertNotSame([], SchemaErrors::of($schema, $invalid, assoc: true));
    }

    public function testRejectsNonPositiveModelTokenLimits(): void
    {
        $schema = ConfigSchemas::load('models');

        foreach (['contextWindow', 'maxTokens'] as $field) {
            foreach ([0, -1] as $value) {
                $this->assertFalse(SchemaErrors::check($schema, ['providers' => ['local' => ['models' => [['id' => 'model', $field => $value]]]]], assoc: true), "models[].{$field}={$value}");
                $this->assertFalse(SchemaErrors::check($schema, ['providers' => ['local' => ['modelOverrides' => ['model' => [$field => $value]]]]], assoc: true), "modelOverrides.{$field}={$value}");
            }
        }

        $this->assertTrue(SchemaErrors::check($schema, ['providers' => ['local' => [
            'models' => [['id' => 'model', 'contextWindow' => 1, 'maxTokens' => 1]],
            'modelOverrides' => ['model' => ['contextWindow' => 1, 'maxTokens' => 1]],
        ]]], assoc: true));
    }

    /**
     * Upstream's grammar, widened to the spellings pig reads as well — `control`, `option`, `cmd`,
     * `command` and `meta`, and `pageup` / `pagedown` — so pig's own `command+enter` default is not
     * a schema error. Upstream refuses `control+x`; pig does not.
     */
    public function testValidatesKeybindingSyntax(): void
    {
        $schema = ConfigSchemas::load('keybindings');

        foreach (['a', '9', 'pageUp', 'pageup', '+', 'ctrl+shift+x', 'alt+ctrl+?', 'ctrl+shift+alt+super+f12', 'ctrl++', 'shift+=', 'control+x', 'command+enter', 'cmd+k', 'option+left', 'meta+shift+z'] as $binding) {
            $this->assertTrue(SchemaErrors::check($schema, ['extension.action' => $binding]), $binding);
        }

        foreach (['', 'Ctrl+x', 'ctrl+not-a-key', 'ctrl+ctrl+x', 'ctrl+control+x', 'cmd+meta+x', 'alt+option+x', 'ctrl+shift+alt+super+ctrl+x', "ctrl+x\n"] as $binding) {
            $this->assertFalse(SchemaErrors::check($schema, ['extension.action' => $binding]), json_encode($binding) ?: '');
        }
    }

    /** The grammar and `Keys::isKeyName()` agree on every key pig names, with and without modifiers. */
    public function testKeybindingGrammarIsWhatKeysReads(): void
    {
        $schema = ConfigSchemas::load('keybindings');
        $keys = new \ReflectionClass(Keys::class);
        $names = [
            ...array_keys($keys->getConstant('NAMED_KEYS')),
            ...$keys->getConstant('SYMBOL_KEYS'),
            ...range('a', 'z'),
            ...array_map('strval', range(0, 9)),
        ];

        foreach ($names as $name) {
            $this->assertSame(Keys::isKeyName($name), SchemaErrors::check($schema, ['x' => $name]), $name);

            // pig reads no modifier on a function key; the grammar, upstream's, allows one.
            if (!preg_match('/^f\d+$/', $name)) {
                foreach (['ctrl', 'shift+alt', 'command', 'ctrl+shift+alt+super'] as $modifiers) {
                    $this->assertSame(Keys::isKeyName("{$modifiers}+{$name}"), SchemaErrors::check($schema, ['x' => "{$modifiers}+{$name}"]), "{$modifiers}+{$name}");
                }
            }
        }
    }

    public function testRejectsSettingsValuesThatRuntimeAccessorsReject(): void
    {
        $schema = ConfigSchemas::load('settings');

        foreach ([
            ['compaction' => ['reserveTokens' => -1]],
            ['compaction' => ['keepRecentTokens' => 1.5]],
            ['compaction' => ['modelOverrides' => ['provider/model' => ['reserveTokens' => 9007199254740992]]]],
            ['httpIdleTimeoutMs' => -1],
            ['websocketConnectTimeoutMs' => -1],
            ['httpIdleTimeoutMs' => 'bogus'],
            ['websocketConnectTimeoutMs' => '30000'],
            ['codemode' => ['inlineBudget' => -1]],
            ['update' => ['check' => 'no']],
        ] as $invalid) {
            $this->assertFalse(SchemaErrors::check($schema, $invalid, assoc: true), json_encode($invalid) ?: '');
        }

        $this->assertTrue(SchemaErrors::check($schema, [
            'compaction' => [
                'reserveTokens' => 0,
                'keepRecentTokens' => 9007199254740991,
                'modelOverrides' => ['provider/model' => ['reserveTokens' => 0]],
            ],
            'httpIdleTimeoutMs' => 0,
            'websocketConnectTimeoutMs' => 'disabled',
            'codemode' => ['inlineBudget' => 0],
        ], assoc: true));
    }

    /**
     * `SchemaErrors` against typebox itself: each document in `fixtures/schema-errors.json` was
     * checked by `Compile(schema).Errors()` from typebox 1.3.27, upstream's version, against pig's
     * committed schema, and the errors it gave are pinned beside it — keyword, instance path and
     * message, in order, at most eight.
     */
    public function testSchemaErrorsReportsWhatTypeboxReports(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/schema-errors.json'), false, flags: JSON_THROW_ON_ERROR);
        $checked = 0;

        foreach (get_object_vars($fixtures) as $name => $cases) {
            $schema = ConfigSchemas::load($name);

            foreach ($cases as $index => [$document, $expected]) {
                $actual = array_map(
                    static fn (array $error): array => [$error['keyword'], $error['instancePath'], $error['message']],
                    SchemaErrors::of($schema, $document),
                );
                $this->assertSame($expected, $actual, "{$name} #{$index}: " . json_encode($document));
                $checked++;
            }
        }

        $this->assertGreaterThan(200, $checked);
    }

    /** Upstream's "accepts $schema in settings.json and preserves unknown settings". */
    public function testSettingsAcceptASchemaReferenceAndKeepUnknownSettings(): void
    {
        $home = $this->temporaryDirectory();
        file_put_contents("{$home}/settings.json", (string) json_encode([
            '$schema' => ConfigSchemas::url('settings'),
            'theme' => 'light',
            'extensionSetting' => ['enabled' => true],
        ]));

        $settings = Settings::load($home, $home, projectTrusted: false);
        $this->assertSame([], $settings->problems());
        $this->assertSame('light', $settings->theme());
        $this->assertSame(ConfigSchemas::url('settings'), $settings->get('$schema'));

        $settings->setTheme('dark');
        $saved = json_decode((string) file_get_contents("{$home}/settings.json"), true);
        $this->assertSame(ConfigSchemas::url('settings'), $saved['$schema']);
        $this->assertSame(['enabled' => true], $saved['extensionSetting']);
        $this->assertSame('dark', $saved['theme']);
    }

    /** Upstream's "accepts $schema in keybindings.json without treating it as an action". */
    public function testKeybindingsAcceptASchemaReferenceWithoutTreatingItAsAnAction(): void
    {
        $home = $this->temporaryDirectory();
        file_put_contents("{$home}/keybindings.json", (string) json_encode([
            '$schema' => ConfigSchemas::url('keybindings'),
            'app.session.new' => 'ctrl+n',
            'app.model.select' => 'ctrl+not-a-key',
        ]));

        $keybindings = Keybindings::load($home);
        $this->assertSame(['ctrl+n'], $keybindings->keysFor('app.session.new'));
        $this->assertSame('ctrl+n', $keybindings->getEffectiveConfig()['app.session.new']);
        // Upstream drops the binding its schema refuses without a word; pig drops it and says so.
        $this->assertSame(['ctrl+l'], $keybindings->keysFor('app.model.select'));
        $this->assertCount(1, $keybindings->problems());
        $this->assertStringContainsString('ctrl+not-a-key', $keybindings->problems()[0]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @var list<string> */
    private array $directories = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/pig-config-schema-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function codemodeInlineBudget(): int
    {
        if (!class_exists(CodemodeDescription::class)) {
            require_once dirname(__DIR__, 3) . '/extensions/pig-codemode/CodemodeDescription.php';
        }

        return CodemodeDescription::DEFAULT_INLINE_BUDGET;
    }

    /**
     * Every `default` in a schema's property tree, by dotted path, in order.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function defaults(array $schema, string $prefix = ''): array
    {
        $found = [];

        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (array_key_exists('default', $property)) {
                $found[$prefix . $key] = $property['default'];
            }
            $found = [...$found, ...$this->defaults($property, "{$prefix}{$key}.")];
        }

        return $found;
    }

    /**
     * Keywords anywhere in a schema that are neither checked nor annotations.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function unknownKeywords(array $schema): array
    {
        $unknown = [];

        foreach ($schema as $key => $value) {
            if (!in_array($key, self::KNOWN_KEYWORDS, true)) {
                $unknown[] = (string) $key;
            }

            if (in_array($key, ['properties', 'patternProperties', '$defs'], true) && is_array($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        $unknown = [...$unknown, ...$this->unknownKeywords($child)];
                    }
                }
            } elseif (in_array($key, ['anyOf', 'allOf', 'oneOf'], true) && is_array($value)) {
                foreach ($value as $child) {
                    $unknown = [...$unknown, ...$this->unknownKeywords($child)];
                }
            } elseif (in_array($key, ['items', 'additionalProperties', 'not'], true) && is_array($value)) {
                $unknown = [...$unknown, ...$this->unknownKeywords($value)];
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * @param array<mixed>         $node
     * @param array<string, mixed> $root
     * @return list<string>
     */
    private function danglingRefs(array $node, array $root): array
    {
        $dangling = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && SchemaErrors::resolve($root, $value) === null) {
                $dangling[] = $value;
            } elseif (is_array($value)) {
                $dangling = [...$dangling, ...$this->danglingRefs($value, $root)];
            }
        }

        return $dangling;
    }
}
