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

    private static function session(
        string $path,
        string $opening,
        int $messages = 4,
        ?string $text = null,
    ): SessionInfo {
        return new SessionInfo(
            $path,
            basename($path, '.jsonl'),
            '/somewhere',
            1_700_000_000_000,
            $messages,
            $opening,
            // Most of these are about the list rather than the search, and a conversation
            // whose only text is its opening is a real one — the first thing said, before
            // anything has answered.
            $text ?? $opening,
        );
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

    // ---- searching -----------------------------------------------------------------

    public function testTypingNarrowsTheListAndEnterOpensWhatIsLeft(): void
    {
        $chosen = $this->pick(
            [
                self::session('/lexer.jsonl', 'the markdown lexer'),
                self::session('/tls.jsonl', 'the tls handshake'),
            ],
            // One `type()` per key: a chunk that arrives is a key, so 'tls' in one call
            // would be a single three-character key that is not any of the ones the list
            // watches — which is right here, but says nothing about the search.
            ['t', 'l', 's', self::ENTER],
        );

        $this->assertSame('/tls.jsonl', $chosen);
    }

    public function testTheSearchReachesWhatWasSaidInTheMiddle(): void
    {
        // The whole reason `SessionInfo::$text` exists. Neither opening mentions TLS.
        $chosen = $this->pick(
            [
                self::session('/a.jsonl', 'hello', 6, 'hello can you look at this'),
                self::session('/b.jsonl', 'morning', 6, 'morning the tls handshake is failing'),
            ],
            ['t', 'l', 's', self::ENTER],
        );

        $this->assertSame('/b.jsonl', $chosen);
    }

    public function testEnterWithNothingMatchingOpensNothing(): void
    {
        // And does not end the picker either, which is why this has to escape its way out:
        // a search that found nothing has nothing to choose, and closing the list on Enter
        // would throw away the query somebody is half way through typing.
        $chosen = $this->pick(
            [self::session('/a.jsonl', 'the markdown lexer')],
            ['z', 'z', 'z', self::ENTER, self::ESC],
        );

        $this->assertNull($chosen);
    }

    public function testBackspaceWidensItAgain(): void
    {
        $chosen = $this->pick(
            [
                self::session('/lexer.jsonl', 'the markdown lexer'),
                self::session('/tls.jsonl', 'the tls handshake'),
            ],
            ['z', "\x7f", 'l', 'e', 'x', self::ENTER],
        );

        $this->assertSame('/lexer.jsonl', $chosen);
    }

    public function testTheArrowKeysStillMoveWhileSearching(): void
    {
        // The keys divide in two and nothing switches between them, so the arrows have to
        // keep working with a query in the box — otherwise a search that leaves two
        // candidates can only ever open the first.
        // Both score identically — the query sits at the start of each — so the two come
        // back in the order they went in and the arrow is the only thing deciding.
        $chosen = $this->pick(
            [
                self::session('/one.jsonl', 'tls one'),
                self::session('/two.jsonl', 'tls two'),
            ],
            ['t', 'l', 's', self::DOWN, self::ENTER],
        );

        $this->assertSame('/two.jsonl', $chosen);
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
