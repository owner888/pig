<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\Themes;
use Pig\Evals\Evals\TuiDocsEval;
use Pig\Tui\Component;
use Pig\Tui\TuiBase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verifies the TUI footer oracle (`inspectContextFooter`) and `ContextFooterJudge` against both an
 * unmodified footer and an extension that replaces the context percentage with the 10-cell bar.
 */
final class TuiDocsEvalTest extends TestCase
{
    private string $tempDir = '';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        Themes::initTheme('dark');
        $this->tempDir = sys_get_temp_dir() . '/pig-tui-eval-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::removeTree($this->tempDir);
    }

    public function testLevenshteinSimilarityScoresIdenticalAndDifferentStrings(): void
    {
        $this->assertSame(1.0, TuiDocsEval::levenshteinSimilarity('abc', 'abc'));
        $this->assertSame(1.0, TuiDocsEval::levenshteinSimilarity('', ''));
        $this->assertSame(0.0, TuiDocsEval::levenshteinSimilarity('abc', 'xyz'));
        $this->assertGreaterThan(0.7, TuiDocsEval::levenshteinSimilarity('████░░░░░░ 42%', '████░░░░░░ 43%'));
    }

    public function testTheContextFooterOracleScoresACorrectProgressBarExtensionAsOneAndUnmodifiedAsZero(): void
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));
        $agent->setModel(new Model(
            'gpt-test',
            'Test',
            Api::OpenAiCompletions,
            'openai',
            'http://127.0.0.1:1',
            TuiDocsEval::CONTEXT_WINDOW,
            4096,
            true,
        ));
        $session = new AgentSession($agent, $this->tempDir);

        // 1. Without an extension, the built-in footer stays in place and scores 0.
        $unmodified = Async::run(fn (): array => TuiDocsEval::inspectContextFooter($session, $this->tempDir));
        $judge = TuiDocsEval::contextFooterJudge();
        $unmodifiedScore = ($judge['evaluate'])(['output' => $unmodified]);
        $this->assertSame(0, $unmodifiedScore['score']);

        // 2. With an extension that replaces the context readout with the 10-cell bar, it scores 1.
        $api = new ExtensionApi($this->tempDir, $this->tempDir . '/footer.php', 'footer');
        $api->on('session_start', static function (object $event, ExtensionContext $ctx): void {
            unset($event);
            $ctx->ui->setFooter(static function (\Pig\Tui\TUI $tui, Theme $theme, FooterComponent $defaultFooter) use ($ctx): Component {
                unset($tui, $theme);

                return new class ($defaultFooter, $ctx) implements Component {
                    public function __construct(
                        private readonly FooterComponent $defaultFooter,
                        private readonly ExtensionContext $ctx,
                    ) {
                    }

                    #[\Override]
                    public function invalidate(): void
                    {
                        $this->defaultFooter->invalidate();
                    }

                    #[\Override]
                    public function render(int $width): array
                    {
                        $rawLines = $this->defaultFooter->render($width);
                        $usage = $this->ctx->getContextUsage();
                        $pct = max(0.0, min(100.0, (float) ($usage->percent ?? 0.0)));
                        $filled = (int) round($pct / 10);
                        $bar = str_repeat('█', $filled) . str_repeat('░', 10 - $filled) . ' ' . (int) round($pct) . '%';

                        return array_map(
                            static fn (string $line): string => (string) preg_replace(
                                TuiDocsEval::BUILT_IN_CONTEXT_PATTERN,
                                $bar,
                                \Pig\Tui\Ansi::strip($line),
                            ),
                            $rawLines,
                        );
                    }
                };
            });
        });

        $ext = new LoadedExtension($this->tempDir . '/footer.php', $this->tempDir . '/footer.php', 'footer', $api);
        $customized = Async::run(fn (): array => TuiDocsEval::inspectContextFooter($session, $this->tempDir, [$ext]));
        $customizedScore = ($judge['evaluate'])(['output' => $customized]);

        $this->assertSame(1, $customizedScore['score']);
        $this->assertSame(100, $customizedScore['metadata']['retainedPercent']);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
