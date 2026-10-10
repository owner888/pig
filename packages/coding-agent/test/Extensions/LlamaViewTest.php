<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\Extensions\Llama\LlamaView;
use Pig\Tui\Ansi;
use Pig\Tui\Keybindings;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\TuiMainScreen;

foreach (['LlamaClient', 'HuggingFaceClient', 'LlamaUi', 'HuggingFaceSearch', 'LlamaView'] as $class) {
    require_once dirname(__DIR__, 4) . "/extensions/pig-llama/{$class}.php";
}

/** `/llama`'s view (upstream's `LlamaView` in `extensions/llama/ui.ts`) driven by keys. */
final class LlamaViewTest extends TestCase
{
    use GlobalThemeFixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        Loop::reset();
    }

    public function testTheModelListPutsLoadedModelsFirstAndDownloadLast(): void
    {
        $view = self::view();
        $catalog = [
            ['id' => 'b-cold', 'status' => ['value' => 'unloaded']],
            ['id' => 'a-warm', 'status' => ['value' => 'loaded'], 'meta' => ['n_ctx' => 32768]],
        ];
        $screen = '';

        [$first, $second] = Async::run(static function () use ($view, $catalog, &$screen): array {
            Async::spawn(static function () use ($view, &$screen): void {
                Async::delay(0.01);
                $screen = self::text($view);
                $view->handleInput("\r");
                Async::delay(0.01);
                $view->handleInput("\x1b[B");
                $view->handleInput("\x1b[B");
                $view->handleInput("\r");
            });

            return [$view->showModels('http://127.0.0.1:8080', $catalog), $view->showModels('http://127.0.0.1:8080', $catalog)];
        });

        $this->assertSame('model', $first['type']);
        $this->assertSame('a-warm', $first['model']['id']);
        $this->assertSame(['type' => 'download'], $second);
        $this->assertStringContainsString('llama.cpp models', $screen);
        $this->assertStringContainsString('http://127.0.0.1:8080', $screen);
        $this->assertStringContainsString('loaded · 33k context', $screen);
        $this->assertStringContainsString('Download model…', $screen);
        $this->assertStringContainsString('enter load/unload/download', $screen);
        $this->assertLessThan(strpos($screen, 'b-cold'), strpos($screen, 'a-warm'));
    }

    public function testEscapeDuringProgressAsksAndAYesCancels(): void
    {
        $view = self::view();
        $cancelled = false;
        $screens = [];

        $result = Async::run(static function () use ($view, &$cancelled, &$screens): array {
            Async::spawn(static function () use ($view, &$screens): void {
                Async::delay(0.01);
                $screens[] = self::text($view);
                $view->handleInput("\x1b");
                Async::delay(0.01);
                $screens[] = self::text($view);
                $view->handleInput("\r");
            });

            return LlamaView::runWithProgress(
                $view,
                title: 'Loading model',
                model: 'qwen',
                initialMessage: 'Starting…',
                cancelTitle: 'Stop loading?',
                cancelMessage: 'qwen',
                run: static function (AbortSignal $signal, \Closure $update): never {
                    $update(['message' => 'Loading tensors', 'ratio' => 0.5]);
                    $stopped = new Deferred();
                    $signal->onAbort(static fn (string $reason) => $stopped->error(new AbortError($reason)));
                    $stopped->future->await();

                    throw new \LogicException('unreachable');
                },
                cancel: static function () use (&$cancelled): void {
                    $cancelled = true;
                },
            );
        });

        $this->assertSame(['cancelled' => true], $result);
        $this->assertTrue($cancelled);
        $this->assertStringContainsString('Loading tensors', $screens[0]);
        $this->assertStringContainsString('50%', $screens[0]);
        $this->assertStringContainsString('escape/ctrl+c stop', $screens[0]);
        $this->assertStringContainsString('Stop loading?', $screens[1]);
        $this->assertStringContainsString('Yes', $screens[1]);
    }

    public function testANoGoesBackToTheProgressAndTheWorkFinishes(): void
    {
        $view = self::view();
        $finish = new Deferred();

        $result = Async::run(static function () use ($view, $finish): array {
            Async::spawn(static function () use ($view, $finish): void {
                Async::delay(0.01);
                $view->handleInput("\x1b");
                Async::delay(0.01);
                $view->handleInput("\x1b[B");
                $view->handleInput("\r");
                Async::delay(0.01);
                $finish->complete('done');
            });

            return LlamaView::runWithProgress(
                $view,
                title: 'Downloading model',
                model: 'owner/repo',
                initialMessage: 'Starting…',
                cancelTitle: 'Stop download?',
                cancelMessage: 'owner/repo',
                run: static fn (): string => $finish->future->await(),
                cancel: static fn () => throw new \LogicException('not cancelled'),
            );
        });

        $this->assertSame(['cancelled' => false, 'value' => 'done'], $result);
    }

    private static function view(): LlamaView
    {
        return new LlamaView(new TuiMainScreen(new FakeTerminal(100, 30)), Keybindings::getKeybindings());
    }

    private static function text(LlamaView $view): string
    {
        return Ansi::strip(implode("\n", $view->render(100)));
    }
}
