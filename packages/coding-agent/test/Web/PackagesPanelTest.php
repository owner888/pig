<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use Generator;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\ResponseBody;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Test\Extensions\NoticingUi;
use Pig\CodingAgent\Web\PackagesPanel;

/**
 * `pig web`'s Packages panel: what it lists, and that its buttons are `pig install|update|remove`
 * run as commands — so a removal takes back what the install wrote, as `pig remove` does.
 */
final class PackagesPanelTest extends TestCase
{
    private string $home;

    private string $cwd;

    /** @var list<array{0: list<string>, 1: string}> */
    private array $ran = [];

    #[\Override]
    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/pig-packages-panel-' . bin2hex(random_bytes(4));
        $this->home = "{$root}/home";
        $this->cwd = "{$root}/project";
        mkdir($this->home, 0o700, true);
        mkdir("{$this->cwd}/tools/extensions", 0o700, true);
        file_put_contents("{$this->cwd}/tools/extensions/hello.php", '<?php');
        file_put_contents("{$this->home}/settings.json", '{"disabledExtensions":["hello"]}');
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->home)));
    }

    public function testItListsThePackagesWithWhatTheyBring(): void
    {
        (new PackageManager($this->cwd, Settings::load($this->cwd, $this->home), $this->home))->installAndPersist('./tools');
        $panel = $this->panel();

        $blocks = Async::run(fn (): array => $panel->render('zh-CN'));

        // Two tabs: what is installed, and the market, asked of GitHub when its tab opens.
        $this->assertSame('tabs', $blocks[0]['type']);
        [$installed, $market] = $blocks[0]['tabs'];
        $this->assertSame(['已安装（1）', '插件市场'], [$installed['label'], $market['label']]);
        $this->assertSame(['text', 'lazy'], array_column($market['blocks'], 'type'));
        $this->assertSame(PackagesPanel::MARKET, $market['blocks'][1]['section']);
        $blocks = $installed['blocks'];
        $this->assertSame(['actions', 'list', 'text'], array_column($blocks, 'type'));
        $this->assertSame(['install', 'update'], array_column($blocks[0]['actions'], 'action'));
        $item = $blocks[1]['items'][0];
        $this->assertSame('tools', $item['title']);
        $this->assertSame('全局', $item['meta']);
        // A local path has nothing to update; its removal asks first.
        $this->assertSame(['remove'], array_column($item['actions'], 'action'));
        $this->assertStringContainsString('settings.json', $item['actions'][0]['confirm']);
    }

    public function testNothingInstalledSaysWhereToFindOne(): void
    {
        $blocks = Async::run(fn (): array => $this->panel()->render('en'));

        $installed = $blocks[0]['tabs'][0];
        $this->assertSame('Installed (0)', $installed['label']);
        $this->assertSame([], $installed['blocks'][1]['items']);
        $this->assertSame(['install'], array_column($installed['blocks'][0]['actions'], 'action'), 'nothing to update all');
        $this->assertStringContainsString('pigagent.dev/packages', $installed['blocks'][1]['empty']);
    }

    public function testTheButtonsArePigsOwnCommands(): void
    {
        $panel = $this->panel();
        $ui = new NoticingUi();
        $ui->inputAnswer = ' git:github.com/owner888/pig-yw123 ';

        $this->assertNull(Async::run(fn (): ?string => $panel->act('install', [], 'en', $ui)));
        $this->assertNull(Async::run(fn (): ?string => $panel->act('update', ['source' => 'git:github.com/owner888/pig-yw123', 'local' => false], 'en', $ui)));
        $this->assertNull(Async::run(fn (): ?string => $panel->act('update', [], 'en', $ui)));
        $this->assertNull(Async::run(fn (): ?string => $panel->act('remove', ['source' => './tools', 'local' => true], 'en', $ui)));

        $this->assertSame([
            ['install', 'git:github.com/owner888/pig-yw123'],
            ['update', 'git:github.com/owner888/pig-yw123'],
            ['update', '--extensions'],
            ['remove', './tools', '-l'],
        ], array_column($this->ran, 0));
        $this->assertStringContainsString('Removed tools.', implode("\n", $ui->notices));
    }

    public function testAFailedCommandSaysItsLastLine(): void
    {
        $panel = new PackagesPanel($this->cwd, $this->home, static fn (): array => [1, '', "\e[31mError: git clone failed: not found\e[0m\n"]);

        $this->assertSame('Error: git clone failed: not found', $panel->act('remove', ['source' => 'git:x/y'], 'en', new NoticingUi()));
    }

    public function testAChangeReloadsThePagesExtensionsAndAFailureDoesNot(): void
    {
        // A removed package's panel and usage source went on being served by the page until
        // `pig web restart`: the server's copy of the extensions is loaded again once a command
        // has changed the packages, and only then.
        $reloads = 0;
        $changed = static function () use (&$reloads): void {
            $reloads++;
        };
        $ok = new PackagesPanel($this->cwd, $this->home, static fn (): array => [0, '', ''], changed: $changed);
        $failing = new PackagesPanel($this->cwd, $this->home, static fn (): array => [1, '', "Error: no\n"], changed: $changed);

        $this->assertNull(Async::run(fn (): ?string => $ok->act('remove', ['source' => 'git:github.com/owner888/pig-yw123'], 'en', new NoticingUi())));
        $this->assertSame(1, $reloads);
        $this->assertSame('Error: no', $failing->act('remove', ['source' => 'git:x/y'], 'en', new NoticingUi()));
        $this->assertSame(1, $reloads);
    }

    public function testTheMarketIsGitHubsTopicWithInstallOnWhatIsNotThere(): void
    {
        (new PackageManager($this->cwd, Settings::load($this->cwd, $this->home), $this->home))->addSourceToSettings('git:github.com/owner888/pig-yw123');
        $http = $this->github(200, (string) json_encode(['items' => [
            ['name' => 'pig-antigravity', 'full_name' => 'owner888/pig-antigravity', 'description' => 'Antigravity for pig', 'stargazers_count' => 12],
            ['name' => 'pig-yw123', 'full_name' => 'owner888/pig-yw123', 'description' => null, 'stargazers_count' => 1],
        ]]));
        $panel = new PackagesPanel($this->cwd, $this->home, http: $http);

        $items = Async::run(fn (): array => $panel->market('zh-CN'))[0]['items'];

        $this->assertSame(PackagesPanel::MARKET_URL, $this->asked[0]->url);
        $this->assertStringContainsString('topic:pig-package', $this->asked[0]->url);
        $this->assertSame('pig-antigravity', $items[0]['title']);
        $this->assertSame('★ 12', $items[0]['meta']);
        $this->assertSame([['label' => '安装…', 'action' => 'install', 'args' => ['source' => 'git:github.com/owner888/pig-antigravity']]], $items[0]['actions']);
        $this->assertSame('★ 1 · 已安装', $items[1]['meta']);
        $this->assertSame([], $items[1]['actions']);

        // Its button installs that source without asking for one.
        $ran = $this->panel();
        $this->assertNull(Async::run(fn (): ?string => $ran->act('install', ['source' => 'git:github.com/owner888/pig-antigravity'], 'en', new NoticingUi())));
        $this->assertSame(['install', 'git:github.com/owner888/pig-antigravity'], $this->ran[0][0]);
    }

    public function testAGitHubThatSaysNoIsSaid(): void
    {
        $panel = new PackagesPanel($this->cwd, $this->home, http: $this->github(403, '{}'));

        $blocks = Async::run(fn (): array => $panel->market('en'));

        $this->assertSame('error', $blocks[0]['tone']);
        $this->assertStringContainsString('rate limit', $blocks[0]['text']);
    }

    /** @var list<Request> what the market asked GitHub */
    private array $asked = [];

    /** GitHub answering `$status` with `$body`, and keeping what it was asked. */
    private function github(int $status, string $body): HttpTransport
    {
        $asked = &$this->asked;

        return new class ($status, $body, $asked) implements HttpTransport {
            /** @param list<Request> $asked */
            public function __construct(private readonly int $status, private readonly string $body, private array &$asked)
            {
            }

            #[\Override]
            public function send(Request $request, ?AbortSignal $signal = null): Response
            {
                $this->asked[] = $request;

                return new Response($this->status, 'Status', [], new class ($this->body) extends ResponseBody {
                    public function __construct(private readonly string $body)
                    {
                    }

                    #[\Override]
                    public function getIterator(): Generator
                    {
                        yield $this->body;
                    }

                    #[\Override]
                    public function close(): void
                    {
                    }
                });
            }
        };
    }

    private function panel(): PackagesPanel
    {
        return new PackagesPanel($this->cwd, $this->home, function (array $arguments, string $cwd): array {
            $this->ran[] = [$arguments, $cwd];

            return [0, '', ''];
        });
    }
}
