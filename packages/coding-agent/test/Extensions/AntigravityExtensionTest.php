<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PigAntigravity\ImageGenerator;
use PigAntigravity\QuotaClient;
use Pig\Ai\Api;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\Models;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;

final class AntigravityExtensionTest extends TestCase
{
    use LoadsAntigravity;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        self::loadAntigravity();
        // An empty home: the loader scans `~/.pig/agent/extensions` as well, and the machine this
        // runs on has a copy of this very extension installed there.
        $this->home = sys_get_temp_dir() . '/pig-ag-ext-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        ExtensionApi::forgetHttpRoutes();
        rmdir($this->home);
    }

    /** @return array{0: list<\Pig\CodingAgent\Extensions\LoadedExtension>, 1: list<\Pig\CodingAgent\Extensions\ExtensionError>} */
    private function loadTheExtension(): array
    {
        $root = dirname(__DIR__, 4);

        return ExtensionLoader::load($this->home, cliPaths: [$root . '/extensions/pig-antigravity/index.php'], home: $this->home);
    }

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
        [$loaded, $errors] = $this->loadTheExtension();

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

    public function testRefreshingTheCatalogWithoutAnAccountSaysToSignInFirst(): void
    {
        // pi-antigravity's `/antigravity.refresh`: no key, no request, and its words.
        putenv("PIG_HOME={$this->home}");

        try {
            [$loaded] = $this->loadTheExtension();
            $ext = $loaded[0];
            [$commands] = (new \Pig\CodingAgent\Hooks\HookRunner([new \Pig\CodingAgent\Hooks\LoadedHook($ext->path, $ext->resolved, $ext->api)], $this->home))->commands();
            $ui = new NoticingUi();

            ($commands['antigravity.refresh']->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true));

            $this->assertSame(['[warning] No Antigravity credentials. Run /login antigravity first.'], $ui->notices);
        } finally {
            putenv('PIG_HOME');
        }
    }

    // ---- what loading the extension puts into the core ----------------------------------

    public function testLoadingTheExtensionRegistersTheProviderAndUnloadingTakesItBack(): void
    {
        // The whole of the move: nothing about this provider is in `packages/` any more, so
        // `--model antigravity/...`, `/login antigravity` and `Stream` only know the name once
        // the extension has loaded — and forget it when the registry is cleared.
        $this->assertNull(Models::find('antigravity', 'gemini-3.8-flash'), 'not before the extension loads');
        $this->assertNull(Auth::signIn('antigravity'));
        $this->assertFalse(Models::isResold('antigravity'));

        [$loaded, $errors] = $this->loadTheExtension();
        $this->assertSame([], $errors);

        $model = Models::find('antigravity', 'gemini-3.8-flash');
        $this->assertNotNull($model);
        $this->assertSame(Api::Extension, $model->api);
        $this->assertInstanceOf(StreamApi::class, ProviderRegistry::apiFor($model));
        $this->assertInstanceOf(OauthFlow::class, Auth::signIn('antigravity'));
        $this->assertSame('Antigravity (Gemini 3, Claude, GPT-OSS)', Auth::signIn('antigravity')?->label());
        // Resold: `claude-sonnet-4-6` bare is still Anthropic's.
        $this->assertTrue(Models::isResold('antigravity'));
        $this->assertNotSame('antigravity', Models::get('claude-sonnet-4-6')?->provider);
        // And the web UI's panel.
        $this->assertNotNull(ExtensionApi::httpRouteFor('/api/accounts/usage'));

        ProviderRegistry::forget();
        ExtensionApi::forgetHttpRoutes();

        $this->assertNull(Models::find('antigravity', 'gemini-3.8-flash'));
        $this->assertNull(Auth::signIn('antigravity'));
    }

    public function testTheExtensionLoadsWithoutTheClientPairAndRefusesOnlyAtSignIn(): void
    {
        // The sign-in row has to be there to say what is missing; refusing at load would make the
        // provider silently absent, which reads as the list being broken.
        putenv('ANTIGRAVITY_CLIENT_ID');
        putenv('ANTIGRAVITY_CLIENT_SECRET');

        [, $errors] = $this->loadTheExtension();
        $this->assertSame([], $errors);

        $flow = Auth::signIn('antigravity');
        $this->assertInstanceOf(OauthFlow::class, $flow);

        $problem = null;

        try {
            $flow->login(static function (): void {
            }, static fn (): ?string => null);
        } catch (\Throwable $caught) {
            $problem = $caught;
        }

        $this->assertNotNull($problem);
        $this->assertStringContainsString('ANTIGRAVITY_CLIENT_ID', $problem->getMessage());
    }
}
