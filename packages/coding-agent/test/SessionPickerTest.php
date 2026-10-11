<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\SessionChoice;
use Pig\CodingAgent\Cli\SessionList;
use Pig\CodingAgent\Cli\SessionPicker;
use Pig\CodingAgent\Session\SessionInfo;
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
    use GlobalThemeFixture;

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        \Pig\Tui\Keybindings::reset();
    }

    private const string ESC = "\e";

    private const string ENTER = "\r";

    private const string DOWN = "\e[B";

    private const string CTRL_C = "\x03";

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
        \Pig\Tui\Keybindings::setKeybindings(new \Pig\CodingAgent\Keybindings(platform: 'linux', env: []));
    }

    /** @param list<string> $keys typed in order, before the picker opens */
    private function pick(array $sessions, array $keys): ?string
    {
        return $this->choose($sessions, $keys)->path;
    }

    /** @param list<string> $keys typed in order, before the picker opens */
    private function choose(array $sessions, array $keys): SessionChoice
    {
        $terminal = new FakeTerminal(80, 24);

        foreach ($keys as $key) {
            $terminal->queue($key);
        }

        return SessionPicker::ask($sessions, $terminal);
    }

    private static function session(
        string $path,
        string $opening,
        int $messages = 4,
        ?string $text = null,
        ?string $name = null,
        ?string $parent = null,
        int $modified = 0,
        string $cwd = '/somewhere',
    ): SessionInfo {
        return new SessionInfo(
            $path,
            basename($path, '.jsonl'),
            $cwd,
            1_700_000_000_000,
            $messages,
            $opening,
            // Most of these are about the list rather than the search, and a conversation
            // whose only text is its opening is a real one — the first thing said, before
            // anything has answered.
            $text ?? $opening,
            $name,
            $parent,
            $modified,
        );
    }

    public function testDeleteAsksFirstAndThenRemovesTheFileAndTheRow(): void
    {
        $dir = sys_get_temp_dir() . '/pig-sessions-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/a.jsonl", '');
        file_put_contents("{$dir}/b.jsonl", '');
        $list = new SessionList([self::session("{$dir}/a.jsonl", 'first'), self::session("{$dir}/b.jsonl", 'second')]);
        $deleted = [];
        $list->setDeleteHandler(static function (string $path) use (&$deleted): ?string {
            $deleted[] = $path;

            return SessionList::deleteSessionFile($path);
        }, "{$dir}/b.jsonl");

        $list->handleInput("\x04"); // ctrl+d: app.session.delete
        $this->assertStringContainsString('Delete session?', self::text($list));
        $list->handleInput(self::ESC);
        $this->assertSame([], $deleted, 'escape keeps it');

        $list->handleInput("\x04");
        $list->handleInput(self::ENTER);
        $this->assertSame(["{$dir}/a.jsonl"], $deleted);
        $this->assertFileDoesNotExist("{$dir}/a.jsonl");
        $this->assertCount(1, $list->matching());

        // The session being talked in is refused before anything is asked.
        $list->handleInput("\x04");
        $this->assertStringContainsString('Cannot delete the currently active session', self::text($list));
        $this->assertFileExists("{$dir}/b.jsonl");

        unlink("{$dir}/b.jsonl");
        rmdir($dir);
    }

    public function testCtrlPShowsEachSessionsPath(): void
    {
        $list = new SessionList([self::session('/tmp/where/a.jsonl', 'first')]);
        $this->assertStringNotContainsString('/tmp/where/a.jsonl', self::text($list));

        $list->handleInput("\x10"); // ctrl+p: app.session.togglePath
        $this->assertStringContainsString('/tmp/where/a.jsonl', self::text($list));
        $this->assertStringContainsString('path (on)', self::text($list));
    }

    private static function text(SessionList $list): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $list->render(200)));
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
        $choice = $this->choose([self::session('/a.jsonl', 'first')], [self::ESC]);

        $this->assertNull($choice->path);
        $this->assertFalse($choice->quit);
    }

    public function testCtrlCQuitsRatherThanStartingANewSession(): void
    {
        // The two are not the same key and upstream does not treat them as one: escape is
        // "not this session", ctrl+c is "not pig". Answering ctrl+c with a new session hands
        // somebody who asked to leave a fresh agent and an empty screen, and the only way out
        // of *that* is the key they just pressed.
        $choice = $this->choose([self::session('/a.jsonl', 'first')], [self::CTRL_C]);

        $this->assertTrue($choice->quit);
        $this->assertNull($choice->path);
    }

    public function testCtrlCWhileSearchingQuitsToo(): void
    {
        // The half-typed query is not a reason to hold on to somebody: ctrl+c is answered by
        // the list no matter what is in the search box, the same as escape.
        $this->assertTrue($this->choose(
            [self::session('/a.jsonl', 'the markdown lexer')],
            ['l', 'e', 'x', self::CTRL_C],
        )->quit);
    }

    public function testNoSessionsIsAnsweredWithoutDrawingAnything(): void
    {
        // Not a screen saying "nothing here" that somebody has to dismiss.
        $choice = SessionPicker::ask([]);

        $this->assertNull($choice->path);
        $this->assertFalse($choice->quit);
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

    public function testEachRowIsTheOpeningTheCountAndTheAge(): void
    {
        $text = self::text(new SessionList([self::session('/a.jsonl', 'why is the sky blue?', 12)]));

        $this->assertMatchesRegularExpression('/why is the sky blue\?\s+12 \d+(y|mo|w|d|h|m)\b/', $text);
        $this->assertStringContainsString('Resume Session (Current Folder)', $text);
        $this->assertStringContainsString('Sort: Threaded', $text);
    }

    public function testASessionWithNothingSaidStillHasALine(): void
    {
        // The alternative is a blank row that cannot be told from a rendering bug.
        $this->assertStringContainsString('(nothing was said)', self::text(new SessionList([self::session('/a.jsonl', '')])));
    }

    public function testThreadedPutsAForkUnderTheSessionItCameFrom(): void
    {
        $parent = self::session('/p.jsonl', 'the parent', modified: 1_000);
        $fork = self::session('/f.jsonl', 'the fork', parent: '/p.jsonl', modified: 3_000);
        $other = self::session('/o.jsonl', 'another', modified: 2_000);
        $list = new SessionList([$fork, $other, $parent]);

        // The parent's subtree holds the newest activity, so it comes first, with its fork under it.
        $this->assertSame(['/p.jsonl', '/f.jsonl', '/o.jsonl'], self::paths($list));
        $this->assertStringContainsString('   └─ the fork', self::text($list));

        $list->handleInput("\x13"); // ctrl+s: app.session.toggleSort → recent
        $this->assertStringContainsString('Sort: Recent', self::text($list));
        $this->assertSame(['/f.jsonl', '/o.jsonl', '/p.jsonl'], self::paths($list), 'recent keeps the order given');
        $this->assertStringNotContainsString('└─', self::text($list));

        $list->handleInput("\x13"); // → fuzzy
        $this->assertStringContainsString('Sort: Fuzzy', self::text($list));
        $list->handleInput("\x13"); // → threaded again
        $this->assertStringContainsString('Sort: Threaded', self::text($list));
    }

    public function testCtrlNKeepsOnlyNamedSessions(): void
    {
        $list = new SessionList([self::session('/a.jsonl', 'first'), self::session('/b.jsonl', 'my release', name: 'my release')]);

        $list->handleInput("\x0e"); // ctrl+n: app.session.toggleNamedFilter
        $this->assertSame(['/b.jsonl'], self::paths($list));
        $this->assertStringContainsString('Name: Named', self::text($list));

        $list->handleInput("\x0e");
        $this->assertCount(2, $list->matching());
    }

    public function testTabSwitchesToEveryProjectsSessionsAndBack(): void
    {
        $loads = 0;
        $list = new SessionList(
            [self::session('/here.jsonl', 'here')],
            10,
            static function () use (&$loads): array {
                $loads++;

                return [self::session('/here.jsonl', 'here'), self::session('/there.jsonl', 'there', cwd: '/elsewhere')];
            },
        );

        $list->handleInput("\t");
        $this->assertSame(['/here.jsonl', '/there.jsonl'], self::paths($list));
        $this->assertStringContainsString('Resume Session (All)', self::text($list));
        $this->assertStringContainsString('/elsewhere', self::text($list), 'every project shows each one\'s folder');

        $list->handleInput("\t");
        $list->handleInput("\t");
        $this->assertSame(1, $loads, 'read once');

        $list->handleInput("\t");
        $this->assertSame(['/here.jsonl'], self::paths($list));
    }

    public function testCtrlRRenamesInPlace(): void
    {
        $renamed = [];
        $list = new SessionList([self::session('/nowhere/a.jsonl', 'first', name: 'old')]);
        $list->handleInput("\x12");
        $this->assertStringNotContainsString('Rename Session', self::text($list), 'nothing to rename with, nothing happens');

        $list->setRenameHandler(static function (string $path, string $name) use (&$renamed): void {
            $renamed[] = [$path, $name];
        });
        $this->assertStringContainsString('rename', self::text($list), 'offered once it can be done');

        $list->handleInput("\x12"); // ctrl+r: app.session.rename
        $this->assertStringContainsString('Rename Session', self::text($list));

        // The field starts with the name it has, and the cursor where upstream's starts: at 0.
        foreach (["\x05", "\x7f", "\x7f", "\x7f", 'n', 'e', 'w'] as $key) {
            $list->handleInput($key);
        }

        $list->handleInput(self::ENTER);
        $this->assertSame([['/nowhere/a.jsonl', 'new']], $renamed);
        $this->assertStringContainsString('new', self::text($list));
        $this->assertSame('new', $list->matching()[0]->name);

        // Escape leaves the field without renaming anything.
        $list->handleInput("\x12");
        $list->handleInput(self::ESC);
        $this->assertCount(1, $renamed);
        $this->assertStringNotContainsString('Rename Session', self::text($list));
    }

    public function testQuotesSearchForAPhraseAndReSearchesForAPattern(): void
    {
        $sessions = [
            self::session('/a.jsonl', 'a', text: 'node is not the cve here'),
            self::session('/b.jsonl', 'b', text: 'the node cve fix'),
        ];

        $this->assertSame(['/b.jsonl'], self::paths(self::typed($sessions, '"node cve"')));
        $this->assertSame(['/a.jsonl'], self::paths(self::typed($sessions, 're:not\s+the')));
        $this->assertSame([], self::paths(self::typed($sessions, 're:(')), 'a pattern that does not compile matches nothing');
    }

    /** @param list<SessionInfo> $sessions */
    private static function typed(array $sessions, string $query): SessionList
    {
        $list = new SessionList($sessions);

        foreach (mb_str_split($query) as $key) {
            $list->handleInput($key);
        }

        return $list;
    }

    /** @return list<string> */
    private static function paths(SessionList $list): array
    {
        return array_map(static fn (SessionInfo $s): string => $s->path, $list->matching());
    }
}
