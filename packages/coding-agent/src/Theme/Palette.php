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

    /**
     * @var array<string, string> name to the colour itself, `#rrggbb`
     *
     * Kept beside the escapes because one consumer does not want an escape: the HTML export
     * writes a stylesheet, and a CSS file full of `\e[38;2;…m` is no use to it. Without this
     * it had the dark theme's six syntax colours written out by hand, so a light-theme export
     * came out with pale blue keywords on white.
     */
    private array $hex = [];

    private function __construct(array $palette, private readonly bool $truecolor)
    {
        $vars = $palette['vars'];

        foreach ($palette['colors'] as $name => $value) {
            $resolved = self::resolve($value, $vars, $name);
            $this->hex[$name] = $resolved;

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

    /** The named colour as `#rrggbb`, for a consumer that is not a terminal. */
    public function hex(string $name): string
    {
        return $this->hex[$name] ?? throw new InvalidArgumentException("No colour called '{$name}'");
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
            'text' => '#dee0e1',
            'muted' => '#9da5a9',
            'violet' => '#a798d7',
            'blue' => '#69add0',
            'green' => '#68b78d',
            'red' => '#ea7f81',
            'yellow' => '#cd9a22',
            'blueBg' => '#213b49',
        ],
        'colors' => [
            'accent' => 'violet',
            'border' => '#5fa8cc',
            'borderAccent' => '#a08ed5',
            'borderMuted' => '#768186',
            'success' => 'green',
            'error' => 'red',
            'warning' => 'yellow',
            'muted' => 'muted',
            'dim' => '#7e888e',
            'text' => '',
            'thinkingText' => '#96a0a4',

            'selectedBg' => 'blueBg',
            'userMessageBg' => 'blueBg',
            'userMessageText' => '',
            'customMessageBg' => '#3a3055',
            'customMessageText' => '',
            'customMessageLabel' => 'violet',
            'toolPendingBg' => '#34383a',
            'toolSuccessBg' => '#254131',
            'toolErrorBg' => '#5b282a',
            'toolTitle' => '',
            'toolOutput' => 'muted',

            'mdHeading' => 'yellow',
            'mdLink' => 'blue',
            'mdLinkUrl' => 'muted',
            'mdCode' => 'violet',
            'mdCodeBlock' => 'green',
            'mdCodeBlockBorder' => 'muted',
            'mdQuote' => 'muted',
            'mdQuoteBorder' => 'muted',
            'mdHr' => 'muted',
            'mdListBullet' => 'violet',

            'toolDiffAdded' => 'green',
            'toolDiffRemoved' => 'red',
            'toolDiffContext' => 'muted',

            'syntaxComment' => 'muted',
            'syntaxKeyword' => 'blue',
            'syntaxFunction' => 'yellow',
            'syntaxVariable' => '#5db3ba',
            'syntaxString' => '#de8d5a',
            'syntaxNumber' => 'green',
            'syntaxType' => 'violet',

            'thinkingOff' => '#6c767b',
            'thinkingMinimal' => '#68808d',
            'thinkingLow' => '#5489a4',
            'thinkingMedium' => '#6185cc',
            'thinkingHigh' => '#9776e5',
            'thinkingXhigh' => '#de54c1',

            'bashMode' => '#5eb286',
        ],
    ];

    private const array LIGHT = [
        'vars' => [
            'text' => '#3b3f41',
            'muted' => '#677176',
            'violet' => '#7459b4',
            'blue' => '#2f7899',
            'green' => '#337e58',
            'red' => '#c8253d',
            'yellow' => '#8f6802',
            'blueBg' => '#dfe7ec',
        ],
        'colors' => [
            'accent' => 'violet',
            'border' => '#3d8eb3',
            'borderAccent' => '#8a72cb',
            'borderMuted' => '#9aa2a7',
            'success' => 'green',
            'error' => 'red',
            'warning' => 'yellow',
            'muted' => 'muted',
            'dim' => '#879095',
            'text' => '',
            'thinkingText' => '#7c868c',

            'selectedBg' => 'blueBg',
            'userMessageBg' => 'blueBg',
            'userMessageText' => '',
            'customMessageBg' => '#e6e4ee',
            'customMessageText' => '',
            'customMessageLabel' => 'violet',
            'toolPendingBg' => '#e4e5e6',
            'toolSuccessBg' => '#dee9e1',
            'toolErrorBg' => '#eee2e1',
            'toolTitle' => '',
            'toolOutput' => 'muted',

            'mdHeading' => 'yellow',
            'mdLink' => 'blue',
            'mdLinkUrl' => 'muted',
            'mdCode' => 'violet',
            'mdCodeBlock' => 'green',
            'mdCodeBlockBorder' => 'muted',
            'mdQuote' => 'muted',
            'mdQuoteBorder' => 'muted',
            'mdHr' => 'muted',
            'mdListBullet' => 'violet',

            'toolDiffAdded' => 'green',
            'toolDiffRemoved' => 'red',
            'toolDiffContext' => 'muted',

            'syntaxComment' => 'muted',
            'syntaxKeyword' => 'blue',
            'syntaxFunction' => 'yellow',
            'syntaxVariable' => '#287a81',
            'syntaxString' => '#a45417',
            'syntaxNumber' => 'green',
            'syntaxType' => 'violet',

            'thinkingOff' => '#c2c8ca',
            'thinkingMinimal' => '#b5c4cb',
            'thinkingLow' => '#9fc2d5',
            'thinkingMedium' => '#a2b7e0',
            'thinkingHigh' => '#b5a5e8',
            'thinkingXhigh' => '#e585cd',

            'bashMode' => '#40976c',
        ],
    ];
}
