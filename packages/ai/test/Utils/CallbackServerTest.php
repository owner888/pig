<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;

/**
 * The one request a browser makes back to this machine at the end of a Google sign-in.
 *
 * A real socket on a real port every time — there is nothing to fake about "did the port bind"
 * and "did the request parse", which are the two things this exists to do. The port is asked of
 * the operating system rather than fixed at 8085, so the suite never fights with a pig that
 * happens to be signing in.
 *
 * **The pretend browser must not block.** It is driven from inside a loop callback, and the
 * reply it is waiting for is something the same loop has to produce — so a blocking
 * `stream_get_contents()` there is a deadlock, which is exactly how the first version of this
 * file hung. It writes its request and reads the answer through a watcher, like everything else
 * on this loop.
 */
final class CallbackServerTest extends TestCase
{
    use AssertsThrows;

    private CallbackServer $server;

    private int $port;

    /** @var list<resource> */
    private array $clients = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->port = self::freePort();
        $this->server = new CallbackServer($this->port);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->close();

        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->clients = [];
    }

    /** Asked of the operating system rather than guessed, so two runs never collide. */
    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            self::fail('cannot open a probe socket');
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Pretend to be the browser: one GET, and whatever comes back collected as it arrives.
     *
     * @param string|null $reply filled in as the answer arrives, for the tests that read it
     */
    private function ask(string $target, ?string &$reply = null): void
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2.0);

        if ($client === false) {
            self::fail("cannot reach the callback server: {$errstr}");
        }

        stream_set_blocking($client, false);
        fwrite($client, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $this->clients[] = $client;

        $collected = '';
        $watcher = null;
        $watcher = Loop::get()->onReadable($client, static function (mixed $peer) use (&$collected, &$reply, &$watcher): void {
            $chunk = fread($peer, 8192);

            if ($chunk === false || $chunk === '') {
                // Cancelled before the stream is closed anywhere: a closed stream still in the
                // watcher list is what makes `stream_select()` fail naming nothing useful.
                Loop::get()->cancel((string) $watcher);

                return;
            }

            $collected .= $chunk;
            $reply = $collected;
        });
    }

    /** Run the await, with the browser's request arriving once the loop is turning. */
    private function awaitWith(string $target, ?AbortController $controller = null): mixed
    {
        return Async::run(function () use ($target, $controller): mixed {
            $this->server->listen();

            Loop::get()->defer(function () use ($target): void {
                $this->ask($target);
            });

            return $this->server->await($controller?->signal);
        });
    }

    // ---- taking the port ------------------------------------------------------------------

    public function testTheRedirectUriIsWhatGoogleHasToBeTold(): void
    {
        $this->assertSame("http://localhost:{$this->port}/oauth2callback", $this->server->redirectUri());
    }

    public function testThePortIsEightyEightyFiveUnlessSomebodySaysOtherwise(): void
    {
        // Baked into the redirect URI registered with Google, so picking a free port instead
        // would be refused by Google rather than by the socket.
        $this->assertSame(8085, CallbackServer::PORT);
        $this->assertSame('http://localhost:8085/oauth2callback', (new CallbackServer())->redirectUri());
    }

    public function testAPortSomebodyElseHoldsIsReportedByName(): void
    {
        $held = stream_socket_server("tcp://127.0.0.1:{$this->port}");

        $this->assertTrue(is_resource($held));

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->server->listen(),
        );

        // Named, because the alternative is a sign-in that sends somebody to a browser and
        // then cannot hear the answer.
        $this->assertStringContainsString((string) $this->port, $problem->getMessage());
        $this->assertStringContainsString('only redirect to that exact port', $problem->getMessage());

        fclose($held);
    }

    public function testAwaitingWithoutListeningFirstSaysSo(): void
    {
        $this->assertThrows(
            OauthError::class,
            fn (): mixed => Async::run(fn (): mixed => $this->server->await()),
            'never started',
        );
    }

    // ---- what the browser brings ----------------------------------------------------------

    public function testTheCodeAndStateComeBackOffTheQuery(): void
    {
        $answer = $this->awaitWith('/oauth2callback?code=the-code&state=the-state');

        $this->assertSame(['code' => 'the-code', 'state' => 'the-state'], $answer);
    }

    public function testAnUrlEncodedCodeIsDecodedOnce(): void
    {
        // Google's codes contain a `/`, and `4%2F` is what arrives.
        $answer = $this->awaitWith('/oauth2callback?code=4%2F0AbCd&state=s');

        $this->assertSame('4/0AbCd', $answer['code']);
    }

    public function testTheBrowserIsToldItWorked(): void
    {
        $seen = null;

        Async::run(function () use (&$seen): void {
            $this->server->listen();

            Loop::get()->defer(function () use (&$seen): void {
                $this->ask('/oauth2callback?code=c&state=s', $seen);
            });

            $this->server->await();

            // The reply is written before the waiting ends, but reading it needs a turn of the
            // loop that this fiber is what keeps alive.
            Async::delay(0.05);
        });

        // The browser is where the person is looking, so it is what gets told first.
        $this->assertStringContainsString('200 OK', (string) $seen);
        $this->assertStringContainsString('Signed in', (string) $seen);
        $this->assertStringContainsString('close this window', (string) $seen);
    }

    public function testSayingNoOnGooglesConsentScreenIsARefusalAndNotACancellation(): void
    {
        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->awaitWith('/oauth2callback?error=access_denied'),
        );

        // Something was said, and it was no — which is different from walking away.
        $this->assertStringContainsString('access_denied', $problem->getMessage());
    }

    public function testACallbackWithNothingUsefulInItIsRefused(): void
    {
        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->awaitWith('/oauth2callback?state=s'),
            'without a code',
        );
    }

    public function testSomeOtherPathIsA404AndIsNotTheAnswer(): void
    {
        $other = null;

        $answer = Async::run(function () use (&$other): mixed {
            $this->server->listen();

            Loop::get()->defer(function () use (&$other): void {
                // What a browser does behind the callback. Reading it as the answer would end
                // the sign-in with nothing in hand.
                $this->ask('/favicon.ico', $other);
                $this->ask('/oauth2callback?code=c&state=s');
            });

            $result = $this->server->await();
            Async::delay(0.05);

            return $result;
        });

        $this->assertStringContainsString('404 Not Found', (string) $other);
        $this->assertSame(['code' => 'c', 'state' => 's'], $answer);
    }

    // ---- giving up ------------------------------------------------------------------------

    public function testTheWaitingCanBeCalledOff(): void
    {
        $answer = Async::run(function (): mixed {
            $this->server->listen();
            $controller = new AbortController();

            Loop::get()->delay(0.02, static fn () => $controller->abort('Cancelled'));

            // Nobody ever opens the browser. Escape has to be able to end this, or the fiber
            // waits for as long as the person is away from their desk.
            return $this->server->await($controller->signal);
        });

        $this->assertNull($answer);
    }

    public function testClosingTwiceIsNotAProblem(): void
    {
        $this->server->listen();

        // Which is what a `finally` needs, and a flow that failed half way through has one.
        $this->server->close();
        $this->server->close();

        $this->assertTrue(true);
    }

    public function testThePortIsGivenBackSoASecondAttemptCanTakeIt(): void
    {
        $this->server->listen();
        $this->server->close();

        // A sign-in that was cancelled must not leave the port held, or the next attempt
        // reports that something else has it — and that something else is pig.
        $second = new CallbackServer($this->port);
        $second->listen();
        $second->close();

        $this->assertTrue(true);
    }
}
