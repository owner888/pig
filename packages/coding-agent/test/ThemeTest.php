<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\CodingAgent\Theme\Themes;
use Pig\Test\AssertsThrows;
use Pig\Tui\Ansi;
use Pig\Tui\Colors;
use Pig\Tui\Images\TerminalImage;

/**
 * The global theme and the TUI helpers built on it, as the interactive mode uses them. Ported from what
 * pig's old `PaletteTest` checked that upstream's theme tests do not.
 */
final class ThemeTest extends TestCase
{
    use AssertsThrows;
    use ThemeTestEnvironment;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
        TerminalImage::setCapabilityOverrides(['trueColor' => true]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    public function testEveryBuiltinThemeDefinesEveryToken(): void
    {
        // A component asking for a token must not work on one theme and throw on another.
        foreach (['dark', 'light', 'labra'] as $name) {
            $theme = Themes::getThemeByName($name);
            $this->assertNotNull($theme, $name);
            foreach (self::FOREGROUNDS as $token) {
                $this->assertSame('x', Ansi::strip($theme->fg($token, 'x')), "{$name} {$token}");
            }
            foreach (self::BACKGROUNDS as $token) {
                $this->assertSame('x', Ansi::strip($theme->bg($token, 'x')), "{$name} {$token}");
            }
        }
    }

    public function testAColourResetsOnlyItsOwnLayer(): void
    {
        // So that a colour used inside something bold, or on a background, does not cancel it.
        Themes::initTheme('dark');
        $this->assertStringEndsWith("\e[39m", Themes::theme()->fg('accent', 'x'));
        $this->assertStringEndsWith("\e[49m", Themes::theme()->bg('selectedBg', 'x'));
    }

    public function testAVariableIsFollowedToItsColour(): void
    {
        // dark's `syntaxKeyword` is its `blue` variable.
        Themes::initTheme('dark');
        $blue = Colors::parseColor('okhsl(232 54% 67%)');
        $this->assertStringStartsWith(Colors::foregroundAnsi($blue, 'truecolor'), Themes::theme()->fg('syntaxKeyword', 'x'));
    }

    public function testAnUnknownTokenIsAnErrorRatherThanNothing(): void
    {
        Themes::initTheme('dark');
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Themes::theme()->fg('chartreuse', 'x'),
            'Unknown theme color: chartreuse',
        );
    }

    public function testTheMarkdownThemeHighlightsCodeWithTheThemesColours(): void
    {
        Themes::initTheme('dark');
        $lines = (Themes::getMarkdownTheme()->highlightCode)('return 1;', 'php');

        $this->assertStringContainsString(Themes::theme()->getFgAnsi('syntaxKeyword') . 'return', $lines[0]);
    }

    public function testTheHelpersReadTheThemeThatIsCurrentWhenTheyDraw(): void
    {
        // Built once, drawn after a theme switch: the switch must show.
        Themes::initTheme('dark');
        $selectList = Themes::getSelectListTheme();
        Themes::setTheme('light');

        $this->assertSame(Themes::theme()->fg('accent', 'x'), ($selectList->selectedText)('x'));
    }

    public function testEveryThinkingLevelHasABorderColour(): void
    {
        Themes::initTheme('dark');
        foreach (ThinkingLevel::cases() as $level) {
            $paint = Themes::theme()->getThinkingBorderColor($level);
            $this->assertSame(Themes::theme()->fg('thinking' . ucfirst($level->value), '|'), $paint('|'), $level->value);
        }
    }

    public function testTheEditorCarriesTheSelectListColoursWithIt(): void
    {
        Themes::initTheme('light');
        $editor = Themes::getEditorTheme();

        $this->assertSame(Themes::theme()->fg('borderMuted', 'x'), ($editor->border)('x'));
        $this->assertSame(Themes::theme()->fg('muted', 'x'), ($editor->selectList->description)('x'));
    }

    public function testThemePathsLoadEvenWhenNothingElseIsLookedFor(): void
    {
        // Upstream's `--theme <path>` and `--no-themes`: a file and a directory for this run, the
        // custom directories left out, the built-ins kept, a missing path said by name.
        is_dir($this->customThemesDir()) || mkdir($this->customThemesDir(), 0o777, true);
        $theme = self::builtinThemeJson('dark');
        file_put_contents($this->customThemesDir() . '/mine.json', json_encode([...$theme, 'name' => 'mine'], JSON_THROW_ON_ERROR));
        mkdir($this->themeTestRoot . '/cli', 0o777, true);
        file_put_contents($this->themeTestRoot . '/cli/one.json', json_encode([...$theme, 'name' => 'one'], JSON_THROW_ON_ERROR));
        file_put_contents($this->themeTestRoot . '/two.json', json_encode([...$theme, 'name' => 'two'], JSON_THROW_ON_ERROR));

        Themes::setCliThemePaths([$this->themeTestRoot . '/cli', $this->themeTestRoot . '/two.json', $this->themeTestRoot . '/gone']);
        $this->assertContains('mine', Themes::getAvailableThemes());
        $this->assertContains('one', Themes::getAvailableThemes());
        $this->assertContains('two', Themes::getAvailableThemes());

        Themes::useThemeDiscovery(false);
        $available = Themes::getAvailableThemes();
        $this->assertNotContains('mine', $available);
        $this->assertContains('one', $available);
        $this->assertContains('dark', $available);
        $this->assertContains($this->themeTestRoot . '/gone: Theme path does not exist', Themes::getCustomThemeErrors());
        $this->assertSame(['success' => true], Themes::setTheme('two'));
    }

    public function testAProjectThemeIsFoundOnceTheProjectIsSet(): void
    {
        $project = $this->themeTestRoot . '/project';
        mkdir($project . '/.pig/themes', 0o777, true);
        $nord = self::builtinThemeJson('dark');
        $nord['name'] = 'nord';
        $nord['colors']['accent'] = '#88c0d0';
        file_put_contents($project . '/.pig/themes/nord.json', json_encode($nord, JSON_THROW_ON_ERROR));

        $this->assertNotContains('nord', Themes::getAvailableThemes());

        Themes::setCustomThemesCwd($project);
        $this->assertContains('nord', Themes::getAvailableThemes());
        $this->assertSame(['success' => true], Themes::setTheme('nord'));
        $this->assertSame("\e[38;2;136;192;208mx\e[39m", Themes::theme()->fg('accent', 'x'));
    }

    /**
     * Every token a component may ask for, written out rather than read off a theme, so that deleting
     * one from every theme still fails here.
     */
    private const array FOREGROUNDS = [
        'accent', 'border', 'borderAccent', 'borderMuted', 'success', 'error', 'warning',
        'muted', 'dim', 'text', 'thinkingText',
        'searchMatchText', 'userMessageText',
        'customMessageText', 'customMessageLabel', 'scrollbarTrack', 'scrollbarThumb',
        'toolTitle', 'toolOutput',
        'mdHeading', 'mdLink', 'mdLinkUrl', 'mdCode', 'mdCodeBlock', 'mdCodeBlockBorder',
        'mdQuote', 'mdQuoteBorder', 'mdHr', 'mdListBullet',
        'toolDiffAdded', 'toolDiffRemoved', 'toolDiffContext',
        'syntaxComment', 'syntaxKeyword', 'syntaxFunction', 'syntaxVariable',
        'syntaxString', 'syntaxNumber', 'syntaxType', 'syntaxOperator', 'syntaxPunctuation',
        'thinkingOff', 'thinkingMinimal', 'thinkingLow', 'thinkingMedium', 'thinkingHigh',
        'thinkingXhigh', 'thinkingMax', 'bashMode',
    ];

    private const array BACKGROUNDS = [
        'selectedBg', 'searchMatchBg', 'userMessageBg', 'customMessageBg',
        'toolPendingBg', 'toolSuccessBg', 'toolErrorBg',
    ];
}
