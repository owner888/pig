<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Config;
use Pig\Evals\Harness;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * Ported from upstream's `packages/evals/test/harness.test.ts`.
 */
final class HarnessTest extends TestCase
{
    use AssertsThrows;

    /** The documentation directory a test made, and what `PIG_DOCS_DIR` was before it. */
    private ?string $docs = null;

    private string|false $docsBefore = false;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->docs !== null) {
            unlink($this->docs . '/extensions.md');
            rmdir($this->docs);
            putenv($this->docsBefore === false ? 'PIG_DOCS_DIR' : "PIG_DOCS_DIR={$this->docsBefore}");
            $this->docs = null;
        }
    }

    /** The default prompt carries its `docs` section only where pig's documentation is. */
    private function withDocumentation(): void
    {
        $this->docsBefore = getenv('PIG_DOCS_DIR');
        $this->docs = sys_get_temp_dir() . '/pig-eval-docs-' . bin2hex(random_bytes(4));
        mkdir($this->docs, 0o700, true);
        file_put_contents($this->docs . '/extensions.md', '# Extensions');
        putenv("PIG_DOCS_DIR={$this->docs}");
    }

    public function testPrefersAnExplicitHarnessModel(): void
    {
        $this->assertSame(
            ['provider' => 'anthropic', 'id' => 'claude-opus-4-6'],
            Harness::resolveModelSelection(
                ['provider' => 'anthropic', 'id' => 'claude-opus-4-6'],
                ['PIG_PROVIDER' => 'openai-codex', 'PIG_MODEL' => 'gpt-5.6-sol'],
            ),
        );
    }

    public function testUsesTrimmedEnvironmentDefaults(): void
    {
        $this->assertSame(
            ['provider' => 'openai-codex', 'id' => 'gpt-5.6-sol'],
            Harness::resolveModelSelection(
                null,
                ['PIG_PROVIDER' => ' openai-codex ', 'PIG_MODEL' => ' gpt-5.6-sol '],
            ),
        );
    }

    /**
     * @return list<array{array<string, string>}>
     */
    public static function incompleteEnvironments(): array
    {
        return [
            [[]],
            [['PIG_PROVIDER' => 'openai-codex']],
            [['PIG_MODEL' => 'gpt-5.6-sol']],
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('incompleteEnvironments')]
    public function testRejectsIncompleteModelSelection(array $environment): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => Harness::resolveModelSelection(null, $environment),
            'Select a harness model explicitly',
        );
    }

    public function testRemovesRunnerMetadataAndRestoresTheProcessEnvironment(): void
    {
        $oldHome = getenv('HOME') ?: null;
        $oldAgentDir = getenv('PIG_CODING_AGENT_DIR') ?: null;
        putenv('PIG_EVAL_VARIANT=with_docs');
        $_ENV['PIG_EVAL_VARIANT'] = 'with_docs';
        putenv('PIG_EVAL_ARTIFACT_DIR=/tmp/artifacts');
        $_ENV['PIG_EVAL_ARTIFACT_DIR'] = '/tmp/artifacts';

        try {
            $restore = Harness::applyIsolatedEnvironment('/tmp/eval-home', '/tmp/eval-agent');

            try {
                $this->assertSame('/tmp/eval-home', Config::userHome());
                $this->assertSame('/tmp/eval-agent', getenv('PIG_CODING_AGENT_DIR'));
                $this->assertFalse(getenv('PIG_EVAL_VARIANT'));
                $this->assertFalse(getenv('PIG_EVAL_ARTIFACT_DIR'));
            } finally {
                $restore();
            }

            $this->assertSame($oldHome, getenv('HOME') ?: null);
            $this->assertSame('with_docs', getenv('PIG_EVAL_VARIANT'));
        } finally {
            putenv('PIG_EVAL_VARIANT');
            unset($_ENV['PIG_EVAL_VARIANT'], $_SERVER['PIG_EVAL_VARIANT']);
            putenv('PIG_EVAL_ARTIFACT_DIR');
            unset($_ENV['PIG_EVAL_ARTIFACT_DIR'], $_SERVER['PIG_EVAL_ARTIFACT_DIR']);

            if ($oldHome === null) {
                putenv('HOME');
            } else {
                putenv("HOME={$oldHome}");
            }

            if ($oldAgentDir === null) {
                putenv('PIG_CODING_AGENT_DIR');
            } else {
                putenv("PIG_CODING_AGENT_DIR={$oldAgentDir}");
            }
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function validVariants(): array
    {
        return [['without_docs'], ['with_docs']];
    }

    #[DataProvider('validVariants')]
    public function testAcceptsValidDocumentationVariant(string $variant): void
    {
        $this->assertSame($variant, Harness::resolveDocumentationVariant($variant));
    }

    /**
     * @return list<array{?string}>
     */
    public static function invalidVariants(): array
    {
        return [[null], [''], ['other']];
    }

    #[DataProvider('invalidVariants')]
    public function testRejectsInvalidDocumentationVariant(?string $variant): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): string => Harness::resolveDocumentationVariant($variant),
            'PIG_EVAL_VARIANT',
        );
    }

    public function testStripsOnlyTheDocumentationRoutingSectionFromTheDefaultPrompt(): void
    {
        $this->withDocumentation();
        $prompt = Harness::buildSystemPrompt('/workspace', Harness::DOCUMENTATION_EVAL_TOOLS);

        $this->assertStringContainsString("\n<docs>\nPig documentation (read only", $prompt);
        $this->assertStringContainsString("\nGuidelines:\n", $prompt);
        $this->assertStringContainsString("Current working directory: /workspace", $prompt);
        $this->assertStringContainsString('docs/models.md', $prompt);

        $stripped = Harness::excludePigDocumentation($prompt);
        $this->assertStringContainsString("\nGuidelines:\n", $stripped);
        $this->assertStringContainsString("Current working directory: /workspace", $stripped);
        $this->assertStringNotContainsString('<docs>', $stripped);
        $this->assertStringNotContainsString('Pig documentation', $stripped);
        $this->assertStringNotContainsString('docs/models.md', $stripped);
        $this->assertStringNotContainsString(Config::readmePath(), $stripped);
        $this->assertStringNotContainsString(Config::docsPath(), $stripped);
        $this->assertStringNotContainsString(Config::examplesPath(), $stripped);
    }

    public function testVerifiesThePromptThatWasSent(): void
    {
        $this->withDocumentation();
        $prompt = Harness::buildSystemPrompt('/workspace', Harness::DOCUMENTATION_EVAL_TOOLS);
        $stripped = Harness::excludePigDocumentation($prompt);

        $this->assertSame(
            $stripped,
            Harness::verifySystemPrompt($stripped, ['name' => 'without_docs', 'expectedPigDocumentation' => false]),
        );

        $this->assertThrows(
            RuntimeException::class,
            static fn (): string => Harness::verifySystemPrompt($prompt, ['name' => 'without_docs', 'expectedPigDocumentation' => false]),
            'does not match',
        );
    }

    public function testFailsClosedWhenPromptMarkersAreMissing(): void
    {
        $this->assertThrows(
            RuntimeException::class,
            static fn (): string => Harness::excludePigDocumentation('Instructions'),
            'no Pig documentation section',
        );

        $this->assertThrows(
            RuntimeException::class,
            static fn (): string => Harness::excludePigDocumentation("\n<docs>\nPig documentation\n</docs>"),
            'no working-directory section',
        );
    }

    public function testRejectsDocumentationHarnessesOutsideTheContainerSandbox(): void
    {
        $this->assertThrows(
            RuntimeException::class,
            static fn (): \Closure => Harness::createPigDocumentationEvalHarness(),
            'isolated container sandbox',
        );
    }
}
