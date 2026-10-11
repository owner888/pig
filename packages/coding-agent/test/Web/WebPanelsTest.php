<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\WebPanel;
use Pig\CodingAgent\Web\WebPanels;

/**
 * An extension's panel in the page: `ExtensionApi::registerWebPanel()` and what `/api/panels`
 * answers for it — the list, the blocks, a lazy section, an action and the panel drawn after it.
 */
final class WebPanelsTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> the actions the probe was asked for */
    private array $actions = [];

    #[\Override]
    protected function tearDown(): void
    {
        ExtensionApi::forgetWebPanels();
    }

    private function probe(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), 'probe.php', 'probe');
        $api->registerWebPanel(
            'probe.things',
            ['en' => 'Things', 'zh-CN' => '东西'],
            static fn (array $request): array => $request['section'] === 'slow'
                ? [['type' => 'text', 'text' => "slow in {$request['locale']}"]]
                : [['type' => 'text', 'text' => "things in {$request['locale']}"], ['type' => 'lazy', 'section' => 'slow']],
            function (string $action, array $args, array $request): ?string {
                $this->actions[] = [$action, $args, $request];

                return match ($action) {
                    'ok' => null,
                    'boom' => throw new \RuntimeException('it broke'),
                    default => "no {$action}",
                };
            },
            icon: '★',
            commands: ['things'],
        );
    }

    /** @param array<string, mixed> $req */
    private static function ask(string $path, array $req = []): array
    {
        return WebPanels::answer($path, ['method' => 'GET', 'body' => '', 'query' => [], ...$req]);
    }

    public function testThePanelsAreListedInThePagesLanguage(): void
    {
        $this->probe();

        $this->assertSame(['status' => 200, 'body' => ['panels' => [
            ['id' => 'probe.things', 'title' => '东西', 'icon' => '★', 'commands' => ['things']],
        ]]], self::ask('/api/panels', ['query' => ['locale' => 'zh-CN']]));
        $this->assertSame('Things', self::ask('/api/panels', ['query' => ['locale' => 'fr']])['body']['panels'][0]['title'], 'English when there is none in the language');
        $this->assertSame('Things', self::ask('/api/panels')['body']['panels'][0]['title'], 'English without a locale');
    }

    public function testNoExtensionNoPanels(): void
    {
        $this->assertSame(['panels' => []], self::ask('/api/panels')['body']);
        $this->assertSame(404, self::ask('/api/panels/probe.things')['status']);
    }

    public function testAPanelIsItsBlocksAndALazySectionIsAskedForByName(): void
    {
        $this->probe();

        $panel = self::ask('/api/panels/probe.things', ['query' => ['locale' => 'zh-CN']]);
        $this->assertSame(200, $panel['status']);
        $this->assertTrue($panel['body']['ok']);
        $this->assertSame('things in zh-CN', $panel['body']['blocks'][0]['text']);
        $this->assertSame('lazy', $panel['body']['blocks'][1]['type']);

        $section = self::ask('/api/panels/probe.things', ['query' => ['locale' => 'en', 'section' => 'slow']]);
        $this->assertSame([['type' => 'text', 'text' => 'slow in en']], $section['body']['blocks']);
    }

    public function testALocaleThatIsNotOneIsEnglish(): void
    {
        $this->probe();

        $this->assertSame('things in en', self::ask('/api/panels/probe.things', ['query' => ['locale' => '../../etc']])['body']['blocks'][0]['text']);
    }

    public function testAnActionRunsAndThePanelIsDrawnAgain(): void
    {
        $this->probe();

        $done = self::ask('/api/panels/probe.things', ['method' => 'POST', 'body' => json_encode(['action' => 'ok', 'args' => ['id' => 'a'], 'locale' => 'zh-CN'])]);

        $this->assertSame([['ok', ['id' => 'a'], ['locale' => 'zh-CN', 'section' => null]]], $this->actions);
        $this->assertTrue($done['body']['ok']);
        $this->assertArrayNotHasKey('error', $done['body']);
        $this->assertSame('things in zh-CN', $done['body']['blocks'][0]['text']);
    }

    public function testAnActionThatFailsSaysWhyAndThePanelIsStillDrawn(): void
    {
        $this->probe();

        foreach (['nope' => 'no nope', 'boom' => 'it broke'] as $action => $why) {
            $answer = self::ask('/api/panels/probe.things', ['method' => 'POST', 'body' => json_encode(['action' => $action])]);
            $this->assertFalse($answer['body']['ok'], $action);
            $this->assertSame($why, $answer['body']['error']);
            $this->assertSame('things in en', $answer['body']['blocks'][0]['text']);
        }

        $none = self::ask('/api/panels/probe.things', ['method' => 'POST', 'body' => 'not json']);
        $this->assertFalse($none['body']['ok']);
        $this->assertStringContainsString("no action ''", $none['body']['error']);
        $this->assertCount(2, $this->actions, 'an action with no name is not run');

        $this->assertSame(405, self::ask('/api/panels/probe.things', ['method' => 'DELETE'])['status']);
    }

    public function testARenderThatThrowsIsAnErrorRatherThanABrokenAnswer(): void
    {
        (new ExtensionApi(sys_get_temp_dir(), 'probe.php', 'probe'))->registerWebPanel(
            'probe.broken',
            'Broken',
            static fn (): array => throw new \RuntimeException('cannot draw'),
        );

        $this->assertSame(['ok' => false, 'error' => 'cannot draw'], self::ask('/api/panels/probe.broken')['body']);
        $this->assertSame(
            ['ok' => false, 'error' => "Panel 'probe.broken' has no action 'x'."],
            self::ask('/api/panels/probe.broken', ['method' => 'POST', 'body' => json_encode(['action' => 'x'])])['body'],
            'no action handler: the error is that, not the render\'s',
        );
    }

    public function testAPanelIdIsAPathSegmentAndATitleIsNeeded(): void
    {
        foreach (['', 'Upper', '../x', 'a/b', '.hidden'] as $id) {
            try {
                new WebPanel($id, 'T', static fn (): array => []);
                $this->fail("'{$id}' was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new WebPanel('ok', [], static fn (): array => []);
    }

    public function testTheSameIdAgainReplacesThePanelAndForgettingClearsThem(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), 'probe.php', 'probe');
        $api->registerWebPanel('a', 'First', static fn (): array => []);
        $api->registerWebPanel('b', 'B', static fn (): array => []);
        $api->registerWebPanel('a', 'Second', static fn (): array => []);

        $this->assertSame(['Second', 'B'], array_map(static fn (WebPanel $p): string => $p->titleFor('en'), ExtensionApi::webPanels()));
        $this->assertSame('Second', (new WebPanel('x', ['zh-CN' => 'Second'], static fn (): array => []))->titleFor('de'), 'the first there is, without English');

        ExtensionApi::forgetWebPanels();
        $this->assertSame([], ExtensionApi::webPanels());
    }
}
