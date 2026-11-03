<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\CodingAgent\Interactive\CustomEditor;
use Pig\CodingAgent\Interactive\ScopedModelsSelector;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Ansi;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\SlashCommand;
use Pig\Tui\Components\Editor;
use Pig\Tui\Keybindings as TuiKeybindings;

/** Upstream #7629: the selection page keys reach every scrolling list. */
final class CustomEditorPageKeysTest extends TestCase
{
    use GlobalThemeFixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TuiKeybindings::reset();
        $this->tearDownGlobalTheme();
    }

    public function testAnOpenCompletionListIsPagedBeforeAnImageIsPastedWithTheSameKey(): void
    {
        $keybindings = new Keybindings(['tui.select.pageDown' => 'ctrl+v', 'app.clipboard.pasteImage' => 'ctrl+v']);
        TuiKeybindings::setKeybindings($keybindings);
        $editor = new CustomEditor(new Editor(Themes::getEditorTheme()), $keybindings);
        $pastes = 0;
        $editor->on('app.clipboard.pasteImage', static function () use (&$pastes): void {
            $pastes++;
        });
        $editor->setAutocompleteProvider(new CombinedAutocompleteProvider(
            array_map(static fn (int $index): SlashCommand => new SlashCommand("command-{$index}"), range(0, 11)),
        ));

        $editor->handleInput('/');
        $this->assertTrue($editor->capturesViewportInput());
        $editor->handleInput("\x16");
        $editor->handleInput("\t");

        $this->assertSame('/command-5 ', $editor->text());
        $this->assertSame(0, $pastes);
        $this->assertFalse($editor->capturesViewportInput());

        // With the list closed, the key is the paste again.
        $editor->handleInput("\x16");
        $this->assertSame(1, $pastes);
    }

    public function testTheScopedModelsListPagesByWhatItShows(): void
    {
        $models = array_map(
            static fn (int $index): Model => new Model("model-{$index}", '', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 1_000, 100),
            range(0, 19),
        );
        $noop = static function (): void {
        };
        $selector = new ScopedModelsSelector($models, null, $noop, $noop, $noop);

        $selector->handleInput("\x1b[6~");
        $this->assertStringContainsString('(9/20)', Ansi::strip(implode("\n", $selector->render(80))));
        $selector->handleInput("\x1b[6~");
        $selector->handleInput("\x1b[6~");
        $this->assertStringContainsString('(20/20)', Ansi::strip(implode("\n", $selector->render(80))));
        $this->assertTrue($selector->capturesViewportInput());
    }
}
