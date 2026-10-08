<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OpenAiCodex;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Throwable;

/**
 * OpenAI's ChatGPT sign-in for `openai-codex`, replayed against what upstream did with the same input.
 *
 * `fixtures/codex-oauth/*.json` were recorded by running upstream's `auth/oauth/openai-codex.ts` under
 * Node against a canned auth server (its `fetch` pointed at it): the device-code flow through a 403, a
 * pending error code and the answer, the device endpoint refused, an interval that is not a number, a
 * failed poll, a poll answer without its verifier; the browser flow finished by a pasted redirect URL,
 * a paste with another sign-in's state, a refused exchange, a token answer missing fields, a token
 * with no account id, a paste with no code, a method nobody offers; and the refresh, answered and
 * refused. Each is replayed through pig and held to the record — the requests (method, path,
 * content type, body), the questions asked, and the credential or the error.
 *
 * Not compared: the PKCE verifier and the state, which are random on both sides (the verifier is
 * checked to be what the authorize URL's challenge was made from), and the clock — upstream's was
 * frozen, so `expires` is checked as now plus the lifetime.
 */
final class OpenAiCodexOauthTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        putenv('PI_OAUTH_CALLBACK_HOST');
    }

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        foreach (glob(__DIR__ . '/../fixtures/codex-oauth/*.json') ?: [] as $file) {
            $name = basename($file, '.json');

            yield $name => [$name];
        }
    }

    #[DataProvider('cases')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . "/../fixtures/codex-oauth/{$case}.json"), true);
        $scenario = $fixture['scenario'];
        $upstream = $fixture['upstream'];

        $server = new CannedServer();
        $base = rtrim($server->startSequence(array_map(self::reply(...), $scenario['responses'])), '/');
        // The browser's loopback on a free port: the paste box is what these scenarios answer with.
        $callback = new CallbackServer(self::freePort(), OpenAiCodex::CALLBACK_PATH, 'OpenAI');
        $flow = new OpenAiCodex(authBaseUrl: $base, server: $callback);

        $events = [];
        $authUrl = '';
        $result = null;
        $thrown = null;
        $started = Timestamp::nowMs();

        Async::run(static function () use ($flow, $scenario, &$events, &$authUrl, &$result, &$thrown): void {
            try {
                if (isset($scenario['refresh'])) {
                    $result = $flow->refresh($scenario['refresh']);

                    return;
                }

                $result = $flow->login(
                    static function (string $url, ?string $instructions) use (&$events, &$authUrl): void {
                        $events[] = ['auth', $url, $instructions];
                        $authUrl = $url;
                    },
                    static function (string $message, string $placeholder) use (&$events, &$authUrl, $scenario): ?string {
                        $events[] = ['prompt', $message, $placeholder];
                        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);

                        return str_replace('STATE', (string) $query['state'], $scenario['paste']);
                    },
                    null,
                    static function (string $message, array $options) use (&$events, $scenario): ?string {
                        $events[] = ['select', $message, $options];

                        return $scenario['method'];
                    },
                );
            } catch (Throwable $error) {
                $thrown = $error->getMessage();
            }
        });

        $received = self::requests($server->received());
        $server->stop();

        // What went out.
        $this->assertCount(count($upstream['requests']), $received, $case);

        foreach ($upstream['requests'] as $index => $want) {
            $got = $received[$index];
            $this->assertSame([$want['method'], $want['url'], $want['contentType']], [$got['method'], $got['path'], $got['contentType']], "{$case} request {$index}");
            // The browser flow's verifier is random on both sides: everything else in the body is not.
            $this->assertSame(
                preg_replace('/code_verifier=[A-Za-z0-9_-]{43}/', 'code_verifier=<random>', $want['body']),
                preg_replace('/code_verifier=[A-Za-z0-9_-]{43}/', 'code_verifier=<random>', $got['body']),
                "{$case} request {$index} body",
            );
        }

        // What was asked and shown, in upstream's order.
        $this->assertSame(self::upstreamEvents($upstream['events'], $base), self::pigEvents($events), $case);

        if (isset($upstream['thrown'])) {
            $this->assertNull($result, $case);
            $this->assertSame($upstream['thrown'], $thrown, $case);

            return;
        }

        $this->assertNull($thrown, $case);
        $this->assertInstanceOf(Credentials::class, $result);
        $this->assertSame(
            [$upstream['result']['access'], $upstream['result']['refresh'], $upstream['result']['accountId']],
            [$result->access, $result->refresh, $result->accountId],
            $case,
        );
        // `Date.now() + expires_in * 1000`, no margin; upstream's clock was frozen.
        $lifetime = $upstream['result']['expires'] - (int) gmmktime(3, 4, 5, 1, 2, 2026) * 1000;
        $this->assertGreaterThanOrEqual($started + $lifetime, $result->expires);
        $this->assertLessThanOrEqual(Timestamp::nowMs() + $lifetime, $result->expires);

        // A pasted code went out with the verifier the authorize URL's challenge was made from.
        if (($scenario['method'] ?? null) === 'browser') {
            parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);
            parse_str($received[0]['body'], $form);
            $this->assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', (string) $form['code_verifier'], true)), '+/', '-_'), '='));
        }
    }

    public function testTheBrowsersCallbackWinsAndClosesThePasteBox(): void
    {
        // `waitForCallbackOrManualInput()`: the callback answers first, the paste box is told to stop
        // (its signal aborts and it answers null), and the code is exchanged for the localhost redirect.
        $server = new CannedServer();
        $token = self::jwt(['https://api.openai.com/auth' => ['chatgpt_account_id' => 'acc_9']]);
        $base = rtrim($server->start(self::reply(['body' => ['access_token' => $token, 'refresh_token' => 'rt', 'expires_in' => 60]])), '/');
        $port = self::freePort();
        $flow = new OpenAiCodex(authBaseUrl: $base, server: new CallbackServer($port, OpenAiCodex::CALLBACK_PATH, 'OpenAI'));
        $boxClosed = false;

        $credentials = Async::run(static function () use ($flow, $port, &$boxClosed): ?Credentials {
            return $flow->login(
                static function (string $url) use ($port): void {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    Async::spawn(static fn () => self::get($port, OpenAiCodex::CALLBACK_PATH . '?code=from_browser&state=' . $query['state']));
                },
                static function (string $message, string $placeholder, bool $allowEmpty, ?\Pig\Async\AbortSignal $signal) use (&$boxClosed): ?string {
                    $closed = new \Pig\Async\Deferred();
                    $signal?->onAbort(static fn () => $closed->complete(null));
                    $closed->future->await();
                    $boxClosed = true;

                    return null;
                },
                null,
                static fn (): string => OpenAiCodex::METHOD_BROWSER,
            );
        });

        $this->assertTrue($boxClosed);
        $this->assertSame('acc_9', $credentials?->accountId);
        $this->assertStringContainsString('code=from_browser&code_verifier=', $server->received());
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A1455%2Fauth%2Fcallback', $server->received());
        $server->stop();
    }

    public function testEscapingThePasteBoxIsNobodyFinishing(): void
    {
        $flow = new OpenAiCodex(authBaseUrl: 'http://127.0.0.1:9', server: new CallbackServer(self::freePort(), OpenAiCodex::CALLBACK_PATH, 'OpenAI'));

        $credentials = Async::run(static fn (): ?Credentials => $flow->login(
            static function (): void {
            },
            static fn (): ?string => null,
            null,
            static fn (): string => OpenAiCodex::METHOD_BROWSER,
        ));

        $this->assertNull($credentials);
        // And escaping the choice is too.
        $this->assertNull(Async::run(static fn (): ?Credentials => $flow->login(static function (): void {
        }, static fn (): ?string => null, null, static fn (): ?string => null)));
    }

    /** @param array<string, mixed> $payload */
    private static function jwt(array $payload): string
    {
        $encode = static fn (array $part): string => rtrim(base64_encode((string) json_encode($part)), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode($payload) . '.sig';
    }

    public function testTheProviderRenewsThroughTheFlowAndSendsTheAccessToken(): void
    {
        $this->assertSame('OpenAI (ChatGPT Plus/Pro)', Provider::OpenAiCodex->label());
        $this->assertTrue(Provider::OpenAiCodex->isSubscription());
        $this->assertSame('tok', Provider::OpenAiCodex->apiKey(new Credentials('r', 'tok', 0, accountId: 'acc')));
    }

    public function testTheCallbackServerKeepsWaitingThroughAnotherSignInsState(): void
    {
        // Upstream's `startOAuthCallbackServer({state})`: a callback with the wrong state is answered
        // `400 State mismatch.` and the wait goes on; the right one finishes it.
        $port = self::freePort();
        $server = new CallbackServer($port, OpenAiCodex::CALLBACK_PATH, 'OpenAI', 'st_1');
        $server->listen();
        $pages = [];

        $result = Async::run(static function () use ($server, $port, &$pages): ?array {
            Async::spawn(static function () use ($port, &$pages): void {
                foreach (['state=other&code=c0', 'state=st_1', 'state=st_1&code=c1'] as $query) {
                    $pages[] = self::get($port, OpenAiCodex::CALLBACK_PATH . '?' . $query);
                }
            });

            return $server->await();
        });
        $server->close();

        $this->assertSame(['code' => 'c1', 'state' => 'st_1'], $result);
        $this->assertStringStartsWith('HTTP/1.1 400', $pages[0]);
        $this->assertStringContainsString('State mismatch.', $pages[0]);
        $this->assertStringContainsString('Missing authorization code.', $pages[1]);
    }

    /**
     * @param array{status?: int, headers?: array<string, string>, body: mixed} $reply
     * @return list<string>
     */
    private static function reply(array $reply): array
    {
        $status = $reply['status'] ?? 200;
        $body = is_string($reply['body']) ? $reply['body'] : (string) json_encode($reply['body'], JSON_UNESCAPED_SLASHES);
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        };

        return ["HTTP/1.1 {$status} {$reason}\r\ncontent-type: application/json\r\ncontent-length: " . strlen($body) . "\r\n\r\n" . $body];
    }

    /** @return list<array{method: string, path: string, contentType: string|null, body: string}> */
    private static function requests(string $received): array
    {
        $out = [];

        foreach (preg_split('/(?=POST \/)/', $received, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
            [$head, $body] = explode("\r\n\r\n", $one, 2) + [1 => ''];
            $lines = explode("\r\n", $head);
            $line = explode(' ', (string) array_shift($lines));
            $type = null;

            foreach ($lines as $header) {
                [$name, $value] = explode(':', $header, 2);

                if (strtolower($name) === 'content-type') {
                    $type = ltrim($value);
                }
            }

            $out[] = ['method' => $line[0], 'path' => $line[1] ?? '', 'contentType' => $type, 'body' => $body];
        }

        return $out;
    }

    /**
     * Upstream's interaction, in pig's terms: a select, an `auth_url` or `device_code` notice (pig's
     * `$onAuth`), a manual-code prompt.
     *
     * @param list<array<string, mixed>> $events
     * @return list<list<mixed>>
     */
    private static function upstreamEvents(array $events, string $base): array
    {
        $out = [];

        foreach ($events as $event) {
            if (($event['prompt'] ?? null) === 'select') {
                $out[] = ['select', $event['message'], array_map(static fn (array $o): array => [$o['id'], $o['label']], $event['options'])];
            } elseif (($event['prompt'] ?? null) === 'manual_code') {
                $out[] = ['prompt', $event['message'], $event['placeholder']];
            } elseif (($event['type'] ?? null) === 'auth_url') {
                $out[] = ['auth', str_replace('https://auth.openai.com', $base, $event['url']), $event['params'], $event['instructions']];
            } elseif (($event['type'] ?? null) === 'device_code') {
                $out[] = ['auth', $event['verificationUri'], "Enter code: {$event['userCode']}"];
            }
        }

        return $out;
    }

    /**
     * @param list<list<mixed>> $events
     * @return list<list<mixed>>
     */
    private static function pigEvents(array $events): array
    {
        $out = [];

        foreach ($events as $event) {
            if ($event[0] === 'auth' && str_contains($event[1], '/oauth/authorize?')) {
                [$url, $query] = explode('?', $event[1], 2);
                $params = [];

                foreach (explode('&', $query) as $pair) {
                    [$name, $value] = explode('=', $pair, 2);
                    $params[] = [urldecode($name), $name === 'state' || $name === 'code_challenge' ? '<random>' : urldecode($value)];
                }

                $out[] = ['auth', $url, $params, $event[2]];

                continue;
            }

            $out[] = $event;
        }

        return $out;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** One GET on the loop, the whole answer back. */
    private static function get(int $port, string $target): string
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        self::assertNotFalse($client);
        fwrite($client, "GET {$target} HTTP/1.1\r\nhost: localhost\r\n\r\n");
        stream_set_blocking($client, false);
        $answer = '';

        while (!feof($client)) {
            $done = new \Pig\Async\Deferred();
            $watcher = Loop::get()->onReadable($client, static function () use ($done): void {
                if (!$done->isComplete()) {
                    $done->complete(true);
                }
            });
            $done->future->await();
            Loop::get()->cancel($watcher);
            $answer .= (string) fread($client, 8192);
        }

        fclose($client);

        return $answer;
    }
}
