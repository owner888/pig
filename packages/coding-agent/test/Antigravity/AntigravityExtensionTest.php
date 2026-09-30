<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Antigravity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Antigravity\ImageGenerator;
use Pig\CodingAgent\Antigravity\QuotaClient;
use Pig\CodingAgent\Extensions\ExtensionLoader;

final class AntigravityExtensionTest extends TestCase
{
    // ---- QuotaClient formatting ---------------------------------------------------------

    public function testProgressBarRendersCorrectFractions(): void
    {
        $this->assertSame('[' . str_repeat('-', 20) . ']', QuotaClient::progressBar(0.0));
        $this->assertSame('[' . str_repeat('█', 10) . str_repeat('-', 10) . ']', QuotaClient::progressBar(0.5));
        $this->assertSame('[' . str_repeat('█', 20) . ']', QuotaClient::progressBar(1.0));
        $this->assertSame('[' . str_repeat('█', 20) . ']', QuotaClient::progressBar(1.5)); // clamped
    }

    public function testFormatResetComputesRelativeCountdown(): void
    {
        $this->assertSame('n/a', QuotaClient::formatReset(null));
        $this->assertSame('n/a', QuotaClient::formatReset(''));

        $future = date('c', time() + 3600 * 2 + 60 * 15);
        $reset = QuotaClient::formatReset($future);
        $this->assertSame('2h 15m', $reset);

        $futureDays = date('c', time() + 86400 * 3 + 3600 * 4);
        $resetDays = QuotaClient::formatReset($futureDays);
        $this->assertSame('3d 4h', $resetDays);

        $past = date('c', time() - 100);
        $this->assertSame('now', QuotaClient::formatReset($past));
    }

    public function testFormatUsageSummaryRendersGroupsAndBuckets(): void
    {
        $usage = [
            'planLabel' => 'Google AI Pro (g1-pro-tier)',
            'groups' => [
                [
                    'displayName' => 'Gemini Quota',
                    'description' => 'Gemini 3 models',
                    'buckets' => [
                        [
                            'bucketId' => 'gemini-5h',
                            'displayName' => 'Gemini (5 hours)',
                            'window' => '5h',
                            'resetTime' => date('c', time() + 3600),
                            'description' => null,
                            'remainingFraction' => 0.85,
                        ],
                    ],
                ],
            ],
            'quotaError' => null,
        ];

        $summary = QuotaClient::formatUsageSummary($usage);

        $this->assertStringContainsString('Google AI Pro', $summary);
        $this->assertStringContainsString('Gemini Quota', $summary);
        $this->assertStringContainsString('85% left', $summary);
        $this->assertStringContainsString('Gemini (5 hours)', $summary);
    }

    public function testFormatModelsListRendersTable(): void
    {
        $usage = [
            'projectId' => 'my-project-123',
            'defaultAgentModelId' => 'gemini-3.8-flash',
            'models' => [
                [
                    'modelId' => 'gemini-3.8-flash',
                    'displayName' => 'Gemini 3.8 Flash',
                    'remainingFraction' => 0.75,
                    'resetTime' => date('c', time() + 1800),
                ],
                [
                    'modelId' => 'tab_completion_model',
                    'displayName' => 'Tab Model',
                    'remainingFraction' => 1.0,
                    'resetTime' => null,
                ],
            ],
        ];

        $list = QuotaClient::formatModelsList($usage, all: false);

        $this->assertStringContainsString('project=my-project-123', $list);
        $this->assertStringContainsString('gemini-3.8-flash', $list);
        $this->assertStringContainsString('75.0%', $list);
        // non-all skips tab_ models
        $this->assertStringNotContainsString('tab_completion_model', $list);
    }

    // ---- ImageGenerator validation & path resolution ------------------------------------

    public function testImageGeneratorRefusesInvalidAspectRatio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported aspect ratio: '3:5'");

        $gen = new ImageGenerator();
        $gen->generate('token', 'proj', 'a photo of a cat', aspectRatio: '3:5');
    }

    public function testImageGeneratorRefusesEmptyPrompt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt cannot be empty');

        $gen = new ImageGenerator();
        $gen->generate('token', 'proj', '   ');
    }

    public function testImageGeneratorResolvesSavePaths(): void
    {
        $cwd = '/Users/dev/project';

        // Default path inside .pig/generated-images/
        $defaultPath = ImageGenerator::resolveSavePath($cwd, null, 'image/png');
        $this->assertStringStartsWith('/Users/dev/project/.pig/generated-images/image-', $defaultPath);
        $this->assertStringEndsWith('.png', $defaultPath);

        // Custom filename
        $custom = ImageGenerator::resolveSavePath($cwd, 'output.jpg', 'image/jpeg');
        $this->assertSame('/Users/dev/project/output.jpg', $custom);

        // Custom directory
        $customDir = ImageGenerator::resolveSavePath($cwd, 'assets/', 'image/webp');
        $this->assertStringStartsWith('/Users/dev/project/assets/image-', $customDir);
        $this->assertStringEndsWith('.webp', $customDir);
    }

    // ---- Antigravity extension loading & capabilities -----------------------------------

    public function testAntigravityExtensionLoadsCommandsAndTool(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = is_file($root . '/extensions/pig-antigravity/index.php')
            ? $root . '/extensions/pig-antigravity/index.php'
            : $root . '/extensions/antigravity/index.php';

        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath]);

        $this->assertSame([], $errors);
        $this->assertNotEmpty($loaded);

        $ext = null;
        foreach ($loaded as $candidate) {
            if ($candidate->name === 'pig-antigravity' || $candidate->name === 'antigravity') {
                $ext = $candidate;
                break;
            }
        }

        $this->assertNotNull($ext);

        // Check tool registration
        $tools = $ext->api->tools();
        $this->assertCount(1, $tools);
        $tool = $tools[0];
        $this->assertSame('generate_image', $tool->name);
        $this->assertSame('Generate image', $tool->label);
        $this->assertArrayHasKey('prompt', $tool->parameters['properties']);
        $this->assertArrayHasKey('aspectRatio', $tool->parameters['properties']);
        $this->assertContains('prompt', $tool->parameters['required']);

        // Check commands registered into hookApi
        $commands = [];
        $hookRunner = new \Pig\CodingAgent\Hooks\HookRunner([new \Pig\CodingAgent\Hooks\LoadedHook($ext->path, $ext->resolved, $ext->api)], $root);
        [$registeredCommands] = $hookRunner->commands();

        $this->assertArrayHasKey('antigravity.usage', $registeredCommands);
        $this->assertArrayHasKey('antigravity.models', $registeredCommands);
        $this->assertArrayHasKey('antigravity.accounts', $registeredCommands);
        $this->assertArrayHasKey('antigravity.doctor', $registeredCommands);
        $this->assertArrayHasKey('antigravity.refresh', $registeredCommands);
        $this->assertArrayHasKey('antigravity.image', $registeredCommands);
    }
}
