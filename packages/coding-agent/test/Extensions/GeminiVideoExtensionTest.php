<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookState;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\CodingAgent\ModelRegistry;
use Pig\Extensions\GeminiVideo\GeminiVideo;
use ReflectionClass;
use RuntimeException;

/**
 * `pig-gemini-video` against a fake Gemini and a fake Antigravity on one loopback port — the real
 * services are not reachable from a test, and the extension's base-URL variables point it here.
 */
final class GeminiVideoExtensionTest extends TestCase
{
    /** Small limits so a "large" video is a few hundred kilobytes, not fifteen megabytes. */
    private const int INLINE = 64 * 1024;

    private const int CHUNK = 48 * 1024;

    private const array ENV = [
        'GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GENAI_API_KEY', 'PIG_GEMINI_VIDEO_MODEL', 'PIG_GEMINI_VIDEO_VIA',
        'PIG_GEMINI_VIDEO_AG_MODEL', 'PIG_GEMINI_VIDEO_DEBUG', 'PIG_GEMINI_BASE_URL', 'PIG_GEMINI_VIDEO_AG_BASE_URL', 'PIG_CODING_AGENT_DIR',
    ];

    private string $dir;

    private FakeGeminiServer $server;

    /** @var array<string, string> */
    private array $settings = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 4) . '/extensions/pig-gemini-video/src/GeminiVideo.php';
        Loop::reset();
        foreach (self::ENV as $name) {
            putenv($name);
        }

        $this->dir = sys_get_temp_dir() . '/pig-gemini-video-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/home', 0o700, true);
        putenv("PIG_CODING_AGENT_DIR={$this->dir}/home");

        $this->server = new FakeGeminiServer();
        putenv('PIG_GEMINI_BASE_URL=' . $this->server->url());
        putenv('PIG_GEMINI_VIDEO_AG_BASE_URL=' . $this->server->url('/ag'));

        file_put_contents("{$this->dir}/small.mp4", random_bytes(1024));
        file_put_contents("{$this->dir}/big.mov", random_bytes(150 * 1024 + 7));
        file_put_contents("{$this->dir}/broken.webm", random_bytes(100 * 1024));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        foreach (self::ENV as $name) {
            putenv($name);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
        Loop::reset();
        parent::tearDown();
    }

    // ---- which way it goes --------------------------------------------------------------------

    public function testAutoPrefersTheAntigravitySubscriptionEvenWithAKey(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);

        $result = $this->analyze('small.mp4', 'what?', $this->signedIn());

        $this->assertSame('antigravity', $result['route']);
        $this->assertSame('gemini-3.8-flash', $result['model']);
        $this->assertStringContainsString('video/mp4 1024 bytes; prompt=what?', $result['text']);
        $this->assertStringNotContainsString('hmm', $result['text'], 'thinking is left out');
    }

    public function testAutoUsesTheKeyForAVideoTooLargeToSendInline(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        $progress = [];

        $result = $this->analyze('big.mov', 'p', $this->signedIn(), $progress);

        $this->assertSame('gemini', $result['route']);
        $this->assertStringContainsString('file video/quicktime', $result['text']);
        $this->assertStringContainsString('too large for Antigravity', implode("\n", $progress));
    }

    public function testAutoFallsBackToTheKeyWhenAntigravityFails(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        $this->server->antigravity = 'quota';

        $result = $this->analyze('small.mp4', 'p', $this->signedIn());

        $this->assertSame('gemini', $result['route']);
        $this->assertStringContainsString('inline video/mp4 1024 bytes', $result['text']);
    }

    public function testAutoWithNeitherSaysBothWaysIn(): void
    {
        $this->expectExceptionMessage('/login antigravity for the Antigravity subscription, or sign in to Google');

        $this->analyze('small.mp4', 'p', $this->context(Auth::inMemory()));
    }

    public function testForcedRoutesStayOnTheirRoute(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        putenv('PIG_GEMINI_VIDEO_VIA=antigravity');
        $this->assertSame('antigravity', $this->analyze('small.mp4', 'p', $this->signedIn())['route']);

        $this->settings['via'] = 'gemini';
        putenv('PIG_GEMINI_VIDEO_VIA');
        $this->assertSame('gemini', $this->analyze('small.mp4', 'p', $this->signedIn())['route'], 'the setting works as the variable does');
    }

    public function testAForcedRouteWithoutItsCredentialSaysWhatIsMissing(): void
    {
        putenv('PIG_GEMINI_VIDEO_VIA=antigravity');
        $this->assertThrowsMessage('Not signed in to Antigravity', fn () => $this->analyze('small.mp4', 'p', $this->context(Auth::inMemory())));

        putenv('PIG_GEMINI_VIDEO_VIA=gemini');
        $this->assertThrowsMessage('No Gemini API key', fn () => $this->analyze('small.mp4', 'p', $this->signedIn()));

        putenv('PIG_GEMINI_VIDEO_VIA=sideways');
        $this->assertThrowsMessage('auto, gemini or antigravity', fn () => $this->analyze('small.mp4', 'p', $this->signedIn()));
    }

    public function testAForcedAntigravityRouteRefusesAVideoTooLargeToSendInline(): void
    {
        putenv('PIG_GEMINI_VIDEO_VIA=antigravity');

        $this->assertThrowsMessage('Antigravity takes videos up to', fn () => $this->analyze('big.mov', 'p', $this->signedIn()));
    }

    public function testAMissingFileAndAFormatGeminiDoesNotTakeAreRefused(): void
    {
        file_put_contents("{$this->dir}/notes.txt", 'x');

        $this->assertThrowsMessage('No such file', fn () => $this->analyze('nope.mp4', 'p', $this->signedIn()));
        $this->assertThrowsMessage('Not a video format', fn () => $this->analyze('notes.txt', 'p', $this->signedIn()));
    }

    // ---- the Gemini developer API -------------------------------------------------------------

    public function testASmallVideoGoesInlineWithTheKey(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        putenv('PIG_GEMINI_VIDEO_VIA=gemini');

        $result = $this->analyze('small.mp4', 'describe', $this->signedIn());

        $this->assertSame('model=gemini-3.8-flash; inline video/mp4 1024 bytes; prompt=describe', $result['text']);
        $this->assertSame(['POST /v1beta/models/gemini-3.8-flash:generateContent'], $this->server->log());
    }

    public function testALargeVideoIsUploadedInChunksWaitedOnAndDeletedAfter(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        putenv('PIG_GEMINI_VIDEO_VIA=gemini');

        $result = $this->analyze('big.mov', 'p', $this->signedIn());

        $this->assertStringContainsString('file video/quicktime ' . $this->server->url('/v1beta/files/abc'), $result['text']);
        $this->assertSame(file_get_contents("{$this->dir}/big.mov"), $this->server->uploaded, 'every byte, in order');
        $this->assertSame([
            'POST /upload/v1beta/files',
            'POST /upload-session/1',
            'POST /upload-session/1',
            'POST /upload-session/1',
            'POST /upload-session/1',
            'GET /v1beta/files/abc',
            'GET /v1beta/files/abc',
            'POST /v1beta/models/gemini-3.8-flash:generateContent',
            'DELETE /v1beta/files/abc',
        ], $this->server->log());
        $this->assertSame('upload, finalize', $this->server->requests[4]['headers']['x-goog-upload-command']);
    }

    public function testAVideoGeminiCannotProcessSaysWhyAndIsStillDeleted(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        putenv('PIG_GEMINI_VIDEO_VIA=gemini');

        $this->assertThrowsMessage('video codec unsupported', fn () => $this->analyze('broken.webm', 'p', $this->signedIn()));
        $this->assertTrue($this->server->deleted);
    }

    public function testAGeminiErrorCarriesGeminisOwnMessage(): void
    {
        putenv('GEMINI_API_KEY=wrong');
        putenv('PIG_GEMINI_VIDEO_VIA=gemini');

        $this->assertThrowsMessage('Gemini answered 403: API key not valid', fn () => $this->analyze('small.mp4', 'p', $this->signedIn()));
    }

    public function testTheModelComesFromTheSettingAndTheVariableOverridesIt(): void
    {
        putenv('GEMINI_API_KEY=' . FakeGeminiServer::KEY);
        putenv('PIG_GEMINI_VIDEO_VIA=gemini');
        $this->settings['model'] = 'from-settings';
        $this->assertSame('from-settings', $this->analyze('small.mp4', 'p', $this->signedIn())['model']);

        putenv('PIG_GEMINI_VIDEO_MODEL=from-env');
        $this->assertSame('from-env', $this->analyze('small.mp4', 'p', $this->signedIn())['model']);
    }

    // ---- Antigravity --------------------------------------------------------------------------

    public function testAnAntigravityEndpointWithoutGenerateContentIsAskedForTheStreamInstead(): void
    {
        $this->server->antigravity = 'nogc';

        $result = $this->analyze('small.mp4', 'p', $this->signedIn());

        $this->assertStringStartsWith('stream: model=', $result['text']);
        $this->assertSame(['POST /ag/v1internal:generateContent', 'POST /ag/v1internal:streamGenerateContent?alt=sse'], $this->server->log());
    }

    public function testAModelAntigravityDoesNotHaveFallsBackToAnOlderOne(): void
    {
        $this->settings['antigravityModel'] = 'missing-model';
        $this->server->missingModels = ['missing-model'];

        $result = $this->analyze('small.mp4', 'p', $this->signedIn());

        $this->assertSame('gemini-3.5-flash', $result['model']);
    }

    public function testTheCatalogueIdGoesOutAsAntigravitysRuntimeIdWhenItsRoutingIsLoaded(): void
    {
        // Measured live: plain `gemini-3.8-flash` is a 404 there, the runtime id answers.
        foreach (glob(dirname(__DIR__, 4) . '/extensions/pig-antigravity/src/*.php') ?: [] as $file) {
            require_once $file;
        }

        $this->assertSame('gemini-3.8-flash-medium', GeminiVideo::wireModel('gemini-3.8-flash'));
        $this->assertSame('no-such-model', GeminiVideo::wireModel('no-such-model'), 'an id the table does not know goes out as written');

        $this->analyze('small.mp4', 'p', $this->signedIn());
        $this->assertSame('gemini-3.8-flash-medium', json_decode($this->server->requests[0]['body'], true)['model']);
    }

    public function testAQuotaWallIsSaidAndTheDebugLogKeepsTheTokenOut(): void
    {
        putenv('PIG_GEMINI_VIDEO_VIA=antigravity');
        putenv('PIG_GEMINI_VIDEO_DEBUG=1');
        $this->server->antigravity = 'quota';

        $message = $this->assertThrowsMessage('quota used up or rate limited (429)', fn () => $this->analyze('small.mp4', 'p', $this->signedIn()));
        $log = (string) file_get_contents("{$this->dir}/home/logs/gemini-video-debug.log");

        $this->assertStringNotContainsString(FakeGeminiServer::TOKEN, $message);
        $this->assertStringContainsString('status=429', $log);
        $this->assertStringNotContainsString(FakeGeminiServer::TOKEN, $log);
        $this->assertStringContainsString('[redacted]', $log);
    }

    // ---- through the extension ----------------------------------------------------------------

    public function testTheExtensionRegistersTheToolAndTheCommandPutsTheAnswerInTheConversation(): void
    {
        $root = dirname(__DIR__, 4);
        [$loaded, $errors] = ExtensionLoader::load($this->dir, cliPaths: ["{$root}/extensions/pig-gemini-video/index.php"], home: "{$this->dir}/home", discover: false);
        $this->assertSame([], $errors);
        $api = $loaded[0]->api;

        $tool = array_values(array_filter($api->tools(), static fn ($t): bool => $t->name === 'analyze_video'))[0] ?? null;
        $this->assertNotNull($tool);

        $result = Async::run(fn () => ($tool->execute)('id', ['path' => 'small.mp4'], null, $this->signedIn(), null));
        $this->assertSame('antigravity', $result->details['route']);
        $this->assertStringContainsString(GeminiVideo::DEFAULT_PROMPT, $result->content[0]->text);

        $sent = [];
        $api->writesTo(static function ($message, $trigger) use (&$sent): void {
            $sent[] = [$message, $trigger];
        }, null);
        $ui = new NoticingUi();
        Async::run(fn () => ($api->commands()['gemini-video']->handler)('small.mp4 what happens', $this->signedIn($ui)));

        $this->assertCount(1, $sent, implode("\n", $ui->notices));
        [$message, $trigger] = $sent[0];
        $this->assertStringStartsWith('🎬 Gemini (antigravity · gemini-3.8-flash) on small.mp4:', $message->content[0]->text);
        $this->assertTrue($message->display);
        $this->assertFalse($trigger, 'shown, not a turn of its own');

        Async::run(fn () => ($api->commands()['gemini-video']->handler)('', $this->signedIn($ui)));
        $this->assertStringContainsString('Usage: /gemini-video', end($ui->notices));
    }

    // ---- helpers ------------------------------------------------------------------------------

    /**
     * @param list<string> $progress
     * @return array{text: string, model: string, route: string, bytes: int}
     */
    private function analyze(string $path, string $prompt, HookContext $ctx, array &$progress = []): array
    {
        $video = new GeminiVideo(fn (string $name): mixed => $this->settings[$name] ?? null, self::INLINE, self::CHUNK, 0.01);

        return Async::run(static function () use ($video, $path, $prompt, $ctx, &$progress): array {
            return $video->analyze($path, $prompt, $ctx, static function (string $line) use (&$progress): void {
                $progress[] = $line;
            });
        });
    }

    private function signedIn(?HookUi $ui = null): HookContext
    {
        $auth = Auth::inMemory();
        $auth->setApiKey('antigravity', (string) json_encode(['token' => FakeGeminiServer::TOKEN, 'projectId' => FakeGeminiServer::PROJECT]));

        return $this->context($auth, $ui);
    }

    /** A context whose model registry reads $auth — the constructor builds one only from a session. */
    private function context(Auth $auth, ?HookUi $ui = null): HookContext
    {
        $ctx = (new ReflectionClass(HookContext::class))->newInstanceWithoutConstructor();
        $dir = $this->dir;
        Closure::bind(function () use ($auth, $ui, $dir): void {
            $this->cwd = $dir;
            $this->store = null;
            $this->model = null;
            $this->ui = $ui ?? new NoUi();
            $this->hasUi = $ui !== null;
            $this->signal = null;
            $this->session = null;
            $this->state = new HookState();
            $this->modelRegistry = new ModelRegistry($auth);
        }, $ctx, HookContext::class)();

        return $ctx;
    }

    private function assertThrowsMessage(string $needle, Closure $call): string
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return $e->getMessage();
        }

        $this->fail("Expected an exception saying: {$needle}");
    }
}
