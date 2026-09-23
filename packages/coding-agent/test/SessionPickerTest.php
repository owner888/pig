<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\SessionPicker;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Test\FakeTerminal;

/**
 * The list `--resume` shows when nobody said which session.
 *
 * It runs its own loop and blocks until something is chosen, so the keystrokes cannot be
 * typed after the call — there is no after. They are queued on the fake terminal first and
 * arrive as the loop starts reading, which is what a real terminal's buffer does when
 * someone is already holding the key down.
 */
final class SessionPickerTest extends TestCase
{
    private const string ESC = "\e";

    private const string ENTER = "\r";

    private const string DOWN = "\e[B";

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /** @param list<string> $keys typed in order, before the picker opens */
    private function pick(array $sessions, array $keys): ?string
    {
        $terminal = new FakeTerminal(80, 24);

        foreach ($keys as $key) {
            $terminal->queue($key);
        }

        return SessionPicker::ask($sessions, Palette::dark(true), $terminal);
    }

    private static function session(string $path, string $opening, int $messages = 4): SessionInfo
    {
        return new SessionInfo($path, basename($path, '.jsonl'), '/somewhere', 1_700_000_000_000, $messages, $opening);
    }

    public function testEnterChoosesWhatIsHighlighted(): void
    {
        $chosen = $this->pick(
            [self::session('/a.jsonl', 'first'), self::session('/b.jsonl', 'second')],
            [self::ENTER],
        );

        $this->assertSame('/a.jsonl', $chosen);
    }

    public function testTheArrowKeysMove(): void
    {
        $chosen = $this->pick(
            [self::session('/a.jsonl', 'first'), self::session('/b.jsonl', 'second')],
            [self::DOWN, self::ENTER],
        );

        $this->assertSame('/b.jsonl', $chosen);
    }

    public function testEscapeChoosesNothing(): void
    {
        // Which `bin/pig` reads as "start a new one" rather than as "stop": someone who
        // opened the list and changed their mind still wanted pig.
        $this->assertNull($this->pick([self::session('/a.jsonl', 'first')], [self::ESC]));
    }

    public function testNoSessionsIsAnsweredWithoutDrawingAnything(): void
    {
        // Not a screen saying "nothing here" that somebody has to dismiss.
        $this->assertNull(SessionPicker::ask([], Palette::dark(true)));
    }

    // ---- what the list says --------------------------------------------------------

    public function testEachLineIsTheOpeningAndWhenItWas(): void
    {
        $items = SessionPicker::items([self::session('/a.jsonl', 'why is the sky blue?', 12)]);

        $this->assertCount(1, $items);
        $this->assertSame('why is the sky blue?', $items[0]->label);
        $this->assertStringContainsString('12 messages', (string) $items[0]->description);
    }

    public function testASessionWithNothingSaidStillHasALine(): void
    {
        // The alternative is a blank row that cannot be told from a rendering bug.
        $this->assertSame('(nothing was said)', SessionPicker::items([self::session('/a.jsonl', '')])[0]->label);
    }

    public function testTheValueIsThePositionSoTheRightPathComesBack(): void
    {
        $sessions = [self::session('/a.jsonl', 'first'), self::session('/b.jsonl', 'second')];
        $items = SessionPicker::items($sessions);

        // The path is not in the item, so this is the join between the two. Getting it
        // wrong would open somebody else's conversation, which is the kind of mistake that
        // looks like data loss.
        $this->assertSame('/b.jsonl', $sessions[(int) $items[1]->value]->path);
    }
}
