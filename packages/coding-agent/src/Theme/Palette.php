<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Closure;
use InvalidArgumentException;
use Pig\Agent\ThinkingLevel;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\MarkdownTheme;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\SettingsListTheme;
use Pig\Tui\Style;

/**
 * Every colour the interactive mode draws in, under a name.
 *
 * Components ask for `accent` or `toolOutput`, never for cyan — so the two themes below
 * are the only place a colour is chosen, and a component cannot quietly go its own way.
 * `pig/tui` takes its colours the same way, as `Closure(string): string`, which is what
 * the bridges at the bottom hand it.
 *
 * Ported from upstream's `theme.ts` and its `dark.json` / `light.json`. What is not
 * ported: reading a theme from JSON, the custom-themes directory, and the file watcher
 * that reloads one as it is edited. Those want a schema validator and a fs watcher, and
 * arrive together with the `/theme` command if it is ever wanted. The two built-in
 * themes are tables here instead.
 */
final class Palette
{
    /** @var array<string, string> name to the escape that turns it on */
    private array $colours = [];

    private function __construct(array $palette, private readonly bool $truecolor)
    {
        $vars = $palette['vars'];

        foreach ($palette['colors'] as $name => $value) {
            $resolved = self::resolve($value, $vars, $name);

            // Backgrounds are named as such, and reset differently, so they are kept
            // apart by name rather than by a second table.
            $this->colours[$name] = str_ends_with($name, 'Bg')
                ? Colour::background($resolved, $truecolor)
                : Colour::foreground($resolved, $truecolor);
        }
    }

    public static function dark(?bool $truecolor = null): self
    {
        return new self(self::DARK, $truecolor ?? Colour::truecolor());
    }

    public static function light(?bool $truecolor = null): self
    {
        return new self(self::LIGHT, $truecolor ?? Colour::truecolor());
    }

    /** @return list<string> */
    public static function names(): array
    {
        return ['dark', 'light'];
    }

    public static function named(string $name, ?bool $truecolor = null): self
    {
        return match ($name) {
            'dark' => self::dark($truecolor),
            'light' => self::light($truecolor),
            default => throw new InvalidArgumentException(
                "No theme called '{$name}'. There is " . implode(' and ', self::names()) . '.',
            ),
        };
    }

    // ---- using it ------------------------------------------------------------------

    /**
     * $text in the named colour.
     *
     * Only the foreground is reset at the end, not every attribute, so this can be used
     * inside something bold or on a background without cancelling it.
     */
    public function fg(string $name, string $text): string
    {
        return $this->on($name) . $text . "\e[39m";
    }

    /** $text on the named background, resetting only the background. */
    public function bg(string $name, string $text): string
    {
        return $this->on($name) . $text . "\e[49m";
    }

    /** The named colour as something a component can be handed. */
    public function of(string $name): Closure
    {
        // Looked up now rather than per call, so a typo is an error at wiring time
        // instead of a colourless string somewhere in a render.
        $escape = $this->on($name);
        $reset = str_ends_with($name, 'Bg') ? "\e[49m" : "\e[39m";

        return static fn (string $text): string => $escape . $text . $reset;
    }

    public function isTruecolor(): bool
    {
        return $this->truecolor;
    }

    private function on(string $name): string
    {
        return $this->colours[$name] ?? throw new InvalidArgumentException("No colour called '{$name}'");
    }

    // ---- what pig/tui wants ---------------------------------------------------------

    public function markdownTheme(): MarkdownTheme
    {
        $highlight = $this->highlightTheme();

        return new MarkdownTheme(
            heading: $this->of('mdHeading'),
            link: $this->of('mdLink'),
            linkUrl: $this->of('mdLinkUrl'),
            code: $this->of('mdCode'),
            codeBlock: $this->of('mdCodeBlock'),
            codeBlockBorder: $this->of('mdCodeBlockBorder'),
            quote: $this->of('mdQuote'),
            quoteBorder: $this->of('mdQuoteBorder'),
            rule: $this->of('mdHr'),
            listBullet: $this->of('mdListBullet'),
            bold: Style::bold(...),
            italic: Style::italic(...),
            strikethrough: Style::strikethrough(...),
            underline: Style::underline(...),
            highlightCode: static fn (string $code, string $language): array
                => Highlight::lines($code, $language, $highlight),
        );
    }

    public function highlightTheme(): HighlightTheme
    {
        return new HighlightTheme(
            comment: $this->of('syntaxComment'),
            string: $this->of('syntaxString'),
            number: $this->of('syntaxNumber'),
            keyword: $this->of('syntaxKeyword'),
            type: $this->of('syntaxType'),
            function: $this->of('syntaxFunction'),
            variable: $this->of('syntaxVariable'),
            // Unstyled, as above: the terminal's own foreground is already the right
            // colour for a bracket, and escaping every one of them costs a line's width
            // in bytes for nothing.
            //
            // Which is why the two theme tables below have 49 colours where upstream's JSON
            // has 51: `syntaxOperator` and `syntaxPunctuation` are the two it defines and this
            // has nothing to name them with, because `Highlight` has seven categories and a
            // `plain`. Checked the other way round too — every one of the 49 holds the same
            // hex as upstream's, var references resolved, in both themes.
            plain: static fn (string $text): string => $text,
        );
    }

    public function selectListTheme(): SelectListTheme
    {
        return new SelectListTheme(
            selectedText: $this->of('accent'),
            description: $this->of('muted'),
            scrollInfo: $this->of('muted'),
            noMatch: $this->of('muted'),
        );
    }

    /**
     * A settings list's two columns.
     *
     * The value is the accent on the selected row and plain everywhere else — not muted,
     * which is what `selectListTheme()` gives a description. A description is commentary;
     * a value is the thing the row is about, and greying out every one of them would make
     * the screen say nothing at a glance.
     */
    public function settingsListTheme(): SettingsListTheme
    {
        return new SettingsListTheme(
            label: fn (string $text, bool $selected): string => $selected ? $this->fg('accent', $text) : $text,
            value: fn (string $text, bool $selected): string => $selected
                ? $this->fg('accent', $text)
                : $this->fg('muted', $text),
            description: $this->of('muted'),
            hint: $this->of('muted'),
        );
    }

    public function editorTheme(): EditorTheme
    {
        return new EditorTheme($this->of('borderMuted'), $this->selectListTheme());
    }

    /** The border colour that says, without words, how hard the agent is thinking. */
    public function thinkingBorder(ThinkingLevel $level): Closure
    {
        return $this->of('thinking' . ucfirst($level->value));
    }

    /**
     * @param array<string, string> $vars
     * @param list<string>          $seen
     */
    private static function resolve(string $value, array $vars, string $name, array $seen = []): string
    {
        if ($value === '' || str_starts_with($value, '#')) {
            return $value;
        }

        if (in_array($value, $seen, true)) {
            throw new InvalidArgumentException("Colour '{$name}' refers to itself through '{$value}'");
        }

        $next = $vars[$value] ?? throw new InvalidArgumentException("Colour '{$name}' wants '{$value}', which is not defined");

        return self::resolve($next, $vars, $name, [...$seen, $value]);
    }

    // ---- the themes ------------------------------------------------------------------

    private const array DARK = [
        'vars' => [
            'cyan' => '#00d7ff',
            'blue' => '#5f87ff',
            'green' => '#b5bd68',
            'red' => '#cc6666',
            'yellow' => '#ffff00',
            'gray' => '#808080',
            'dimGray' => '#666666',
            'darkGray' => '#505050',
            'accent' => '#8abeb7',
            'selectedBg' => '#3a3a4a',
            'userMsgBg' => '#343541',
            'toolPendingBg' => '#282832',
            'toolSuccessBg' => '#283228',
            'toolErrorBg' => '#3c2828',
            'customMsgBg' => '#2d2838',
        ],
        'colors' => [
            'accent' => 'accent',
            'border' => 'blue',
            'borderAccent' => 'cyan',
            'borderMuted' => 'darkGray',
            'success' => 'green',
            'error' => 'red',
            'warning' => 'yellow',
            'muted' => 'gray',
            'dim' => 'dimGray',
            'text' => '',
            'thinkingText' => 'gray',

            'selectedBg' => 'selectedBg',
            'userMessageBg' => 'userMsgBg',
            'userMessageText' => '',
            'customMessageBg' => 'customMsgBg',
            'customMessageText' => '',
            'customMessageLabel' => '#9575cd',
            'toolPendingBg' => 'toolPendingBg',
            'toolSuccessBg' => 'toolSuccessBg',
            'toolErrorBg' => 'toolErrorBg',
            'toolTitle' => '',
            'toolOutput' => 'gray',

            'mdHeading' => '#f0c674',
            'mdLink' => '#81a2be',
            'mdLinkUrl' => 'dimGray',
            'mdCode' => 'accent',
            'mdCodeBlock' => 'green',
            'mdCodeBlockBorder' => 'gray',
            'mdQuote' => 'gray',
            'mdQuoteBorder' => 'gray',
            'mdHr' => 'gray',
            'mdListBullet' => 'accent',

            'toolDiffAdded' => 'green',
            'toolDiffRemoved' => 'red',
            'toolDiffContext' => 'gray',

            'syntaxComment' => '#6A9955',
            'syntaxKeyword' => '#569CD6',
            'syntaxFunction' => '#DCDCAA',
            'syntaxVariable' => '#9CDCFE',
            'syntaxString' => '#CE9178',
            'syntaxNumber' => '#B5CEA8',
            'syntaxType' => '#4EC9B0',

            'thinkingOff' => 'darkGray',
            'thinkingMinimal' => '#6e6e6e',
            'thinkingLow' => '#5f87af',
            'thinkingMedium' => '#81a2be',
            'thinkingHigh' => '#b294bb',
            'thinkingXhigh' => '#d183e8',

            'bashMode' => 'green',
        ],
    ];

    private const array LIGHT = [
        'vars' => [
            'teal' => '#5f8787',
            'blue' => '#5f87af',
            'green' => '#87af87',
            'red' => '#af5f5f',
            'yellow' => '#d7af5f',
            'mediumGray' => '#6c6c6c',
            'dimGray' => '#8a8a8a',
            'lightGray' => '#b0b0b0',
            'selectedBg' => '#d0d0e0',
            'userMsgBg' => '#e8e8e8',
            'toolPendingBg' => '#e8e8f0',
            'toolSuccessBg' => '#e8f0e8',
            'toolErrorBg' => '#f0e8e8',
            'customMsgBg' => '#ede7f6',
        ],
        'colors' => [
            'accent' => 'teal',
            'border' => 'blue',
            'borderAccent' => 'teal',
            'borderMuted' => 'lightGray',
            'success' => 'green',
            'error' => 'red',
            'warning' => 'yellow',
            'muted' => 'mediumGray',
            'dim' => 'dimGray',
            'text' => '',
            'thinkingText' => 'mediumGray',

            'selectedBg' => 'selectedBg',
            'userMessageBg' => 'userMsgBg',
            'userMessageText' => '',
            'customMessageBg' => 'customMsgBg',
            'customMessageText' => '',
            'customMessageLabel' => '#7e57c2',
            'toolPendingBg' => 'toolPendingBg',
            'toolSuccessBg' => 'toolSuccessBg',
            'toolErrorBg' => 'toolErrorBg',
            'toolTitle' => '',
            'toolOutput' => 'mediumGray',

            'mdHeading' => 'yellow',
            'mdLink' => 'blue',
            'mdLinkUrl' => 'dimGray',
            'mdCode' => 'teal',
            'mdCodeBlock' => 'green',
            'mdCodeBlockBorder' => 'mediumGray',
            'mdQuote' => 'mediumGray',
            'mdQuoteBorder' => 'mediumGray',
            'mdHr' => 'mediumGray',
            'mdListBullet' => 'green',

            'toolDiffAdded' => 'green',
            'toolDiffRemoved' => 'red',
            'toolDiffContext' => 'mediumGray',

            'syntaxComment' => '#008000',
            'syntaxKeyword' => '#0000FF',
            'syntaxFunction' => '#795E26',
            'syntaxVariable' => '#001080',
            'syntaxString' => '#A31515',
            'syntaxNumber' => '#098658',
            'syntaxType' => '#267F99',

            'thinkingOff' => 'lightGray',
            'thinkingMinimal' => '#9e9e9e',
            'thinkingLow' => '#5f87af',
            'thinkingMedium' => '#5f8787',
            'thinkingHigh' => '#875f87',
            'thinkingXhigh' => '#8b008b',

            'bashMode' => 'green',
        ],
    ];
}
