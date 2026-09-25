<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Proxy;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;
use Pig\Test\TunnelServer;

/**
 * Reaching a provider through a proxy: HTTP CONNECT and SOCKS5.
 *
 * The end-to-end cases run against `TunnelServer`, a loopback proxy that forwards for real,
 * because a handshake can be byte-perfect on the wire and still leave one unread byte on the
 * socket — and that shows up as an unexplainable TLS or framing failure a long way from here.
 * The only question worth asking is whether the request arrives at the far end intact.
 *
 * Every one of those cases asks for `provider.invalid`, a name that by RFC 2606 cannot resolve.
 * If pig ever resolved the host itself instead of handing the name to the proxy, the test would
 * fail with a DNS error rather than passing for the wrong reason.
 */
final class ProxyTest extends TestCase
{
    use AssertsThrows;

    private CannedServer $target;

    private ?TunnelServer $proxy = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        HttpClient::useProxy(null);
        $this->target = new CannedServer();
        $this->proxy = null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        HttpClient::useProxy(null);
        $this->proxy?->close();
    }

    /** @return array{0: Proxy, 1: string} the proxy, and the URL to ask for */
    private function tunnelling(string $scheme, string $response, string $authority = 'provider.invalid:443'): array
    {
        $target = $this->target->start([$response]);
        $this->proxy = (new TunnelServer($scheme))->forwardTo(rtrim(substr($target, 7), '/'));

        return [Proxy::parse($this->proxy->start()), 'http://' . $authority . '/v1/messages'];
    }

    // ---- reading a proxy URL -------------------------------------------------------------------

    public function testReadsSchemeHostAndPort(): void
    {
        $proxy = Proxy::parse('http://127.0.0.1:7890');

        $this->assertSame('http', $proxy->scheme);
        $this->assertSame('127.0.0.1', $proxy->host);
        $this->assertSame(7890, $proxy->port);
        $this->assertNull($proxy->user);
    }

    public function testNoSchemeIsAnHttpProxy(): void
    {
        // What people write, and what curl assumes.
        $this->assertSame('http', Proxy::parse('127.0.0.1:7890')->scheme);
    }

    public function testEverySocksSpellingIsSocks5(): void
    {
        foreach (['socks5', 'socks5h', 'socks', 'SOCKS5H'] as $scheme) {
            $this->assertSame('socks5', Proxy::parse("{$scheme}://127.0.0.1:7891")->scheme, $scheme);
        }
    }

    public function testNoPortIsCurlsDefault(): void
    {
        $this->assertSame(1080, Proxy::parse('socks5://127.0.0.1')->port);
        $this->assertSame(1080, Proxy::parse('http://proxy.internal')->port);
    }

    public function testAnHttpsProxyIsRefusedWithTheReason(): void
    {
        // Two TLS layers on one stream, which `stream_socket_enable_crypto()` does not do. Saying
        // so beats a handshake failure nobody can read.
        $error = $this->assertThrows(HttpError::class, static fn () => Proxy::parse('https://proxy:8443'));

        $this->assertStringContainsString('two layers on one socket', $error->getMessage());
        $this->assertStringContainsString('socks5://', $error->getMessage());
    }

    public function testSocks4IsRefusedWithTheReason(): void
    {
        $error = $this->assertThrows(HttpError::class, static fn () => Proxy::parse('socks4://127.0.0.1:1080'));

        $this->assertStringContainsString('cannot carry a hostname', $error->getMessage());
    }

    public function testAnUnknownSchemeNamesItself(): void
    {
        $error = $this->assertThrows(HttpError::class, static fn () => Proxy::parse('quic://127.0.0.1:1'));

        $this->assertStringContainsString('quic', $error->getMessage());
    }

    public function testAnEmptyUrlIsAnError(): void
    {
        $this->assertThrows(HttpError::class, static fn () => Proxy::parse('   '));
    }

    public function testCredentialsArePercentDecoded(): void
    {
        // The reason the encoding exists: a proxy password with a `@` in it.
        $proxy = Proxy::parse('http://me%40work:p%40ss%3Aword@127.0.0.1:7890');

        $this->assertSame('me@work', $proxy->user);
        $this->assertSame('p@ss:word', $proxy->password);
    }

    public function testAnIpv6HostKeepsItsBracketsOnlyWhereTheyBelong(): void
    {
        $proxy = Proxy::parse('http://[::1]:7890');

        $this->assertSame('::1', $proxy->host, 'the address itself, for connecting');
        $this->assertSame('http://[::1]:7890', $proxy->describe(), 'and bracketed again for reading');
    }

    public function testDescribeNeverShowsThePassword(): void
    {
        $described = Proxy::parse('socks5://me:hunter2@127.0.0.1:7891')->describe();

        $this->assertSame('socks5://me@127.0.0.1:7891', $described);
        $this->assertStringNotContainsString('hunter2', $described);
    }

    // ---- the environment -----------------------------------------------------------------------

    public function testHttpsProxyWinsOverAllProxy(): void
    {
        $proxy = Proxy::fromEnvironment([
            'all_proxy' => 'socks5://127.0.0.1:1',
            'https_proxy' => 'http://127.0.0.1:2',
        ]);

        $this->assertSame(2, $proxy?->port);
    }

    public function testLowercaseWinsOverUppercase(): void
    {
        $proxy = Proxy::fromEnvironment([
            'HTTPS_PROXY' => 'http://127.0.0.1:1',
            'https_proxy' => 'http://127.0.0.1:2',
        ]);

        $this->assertSame(2, $proxy?->port, "curl's precedence");
    }

    public function testAllProxyIsUsedWhenThereIsNoHttpsProxy(): void
    {
        $this->assertSame('socks5', Proxy::fromEnvironment(['ALL_PROXY' => 'socks5://127.0.0.1:1080'])?->scheme);
    }

    public function testAnEmptyVariableMeansNoProxyAndStopsTheSearch(): void
    {
        // `https_proxy= pig ...` is how a script turns one off for one command, so an empty value
        // cannot fall through to `all_proxy` — that would make the override do nothing.
        $this->assertNull(Proxy::fromEnvironment([
            'https_proxy' => '',
            'all_proxy' => 'socks5://127.0.0.1:1080',
        ]));
    }

    public function testNothingSetIsNoProxy(): void
    {
        $this->assertNull(Proxy::fromEnvironment([]));
        $this->assertNull(Proxy::fromEnvironment(['PATH' => '/usr/bin', 'HTTPS_PROXY' => false]));
    }

    public function testHttpProxyIsNotRead(): void
    {
        // Deliberate: it governs `http://` targets, every provider is `https://`, and the only
        // plain-HTTP endpoints pig has are local ones that go direct anyway.
        $this->assertNull(Proxy::fromEnvironment(['http_proxy' => 'http://127.0.0.1:7890']));
    }

    public function testNoProxyBecomesTheBypassList(): void
    {
        $proxy = Proxy::fromEnvironment([
            'https_proxy' => 'http://127.0.0.1:7890',
            'no_proxy' => 'example.com, .internal ,,api.test',
        ]);

        $this->assertSame(['example.com', '.internal', 'api.test'], $proxy?->bypass);
    }

    // ---- who goes direct -----------------------------------------------------------------------

    public function testLoopbackIsAlwaysDirect(): void
    {
        $proxy = Proxy::parse('http://127.0.0.1:7890');

        // Not a convenience: pig's own OAuth callback listens on 127.0.0.1, and a tunnel through a
        // proxy to reach pig itself cannot work.
        foreach (['localhost', 'LOCALHOST', '127.0.0.1', '127.0.0.53', '::1', '[::1]'] as $host) {
            $this->assertTrue($proxy->bypasses($host), $host);
        }

        $this->assertFalse($proxy->bypasses('api.anthropic.com'));
    }

    public function testAnEntryMatchesTheHostAndItsSubdomains(): void
    {
        $proxy = Proxy::parse('http://127.0.0.1:7890', ['example.com', '.internal', 'api.test:443']);

        $this->assertTrue($proxy->bypasses('example.com'));
        $this->assertTrue($proxy->bypasses('api.example.com'), 'a domain suffix');
        $this->assertTrue($proxy->bypasses('anything.internal'), 'a leading dot is tolerated');
        $this->assertTrue($proxy->bypasses('api.test'), 'a port on the entry is ignored');
    }

    public function testASuffixThatIsNotASubdomainIsNotAMatch(): void
    {
        // The mistake this exists to prevent: `str_ends_with($host, 'example.com')` would send
        // `notexample.com` direct, and `evilexample.com` is somebody else's machine.
        $this->assertFalse(Proxy::parse('http://127.0.0.1:1', ['example.com'])->bypasses('notexample.com'));
    }

    public function testAStarBypassesEverything(): void
    {
        $this->assertTrue(Proxy::parse('http://127.0.0.1:1', ['*'])->bypasses('api.anthropic.com'));
    }

    // ---- HTTP CONNECT, end to end --------------------------------------------------------------

    public function testTheRequestArrivesThroughAConnectTunnel(): void
    {
        [$proxy, $url] = $this->tunnelling(
            'http',
            "HTTP/1.1 200 OK\r\nContent-Length: 11\r\n\r\n{\"ok\":true}",
        );

        $body = Async::run(static function () use ($proxy, $url): string {
            return (new HttpClient(5.0, $proxy))->send(new Request('POST', $url, [], '{"model":"x"}'))
                ->body->all();
        });

        $this->assertSame('{"ok":true}', $body, 'the answer came back through the tunnel');
        $this->assertStringContainsString('POST /v1/messages HTTP/1.1', $this->target->receivedHead());
        $this->assertStringContainsString('host: provider.invalid', $this->target->receivedHead());
        $this->assertStringContainsString('{"model":"x"}', $this->target->received());
    }

    public function testTheConnectLineNamesTheTargetAndNotTheProxy(): void
    {
        [$proxy, $url] = $this->tunnelling('http', "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n");

        Async::run(static fn (): string => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))->body->all());

        $handshake = (string) $this->proxy?->handshake();

        $this->assertStringContainsString("CONNECT provider.invalid:443 HTTP/1.1\r\n", $handshake);
        $this->assertStringContainsString("Host: provider.invalid:443\r\n", $handshake);
        $this->assertSame('provider.invalid:443', $this->proxy?->requested());
    }

    public function testCredentialsGoInProxyAuthorization(): void
    {
        [$proxy, $url] = $this->tunnelling('http', "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->requireCredentials();
        $withCredentials = Proxy::parse('http://me:hunter2@' . $proxy->host . ':' . $proxy->port);

        Async::run(static fn (): string => (new HttpClient(5.0, $withCredentials))
            ->send(new Request('GET', $url))->body->all());

        $this->assertStringContainsString(
            'Proxy-Authorization: Basic ' . base64_encode('me:hunter2'),
            (string) $this->proxy?->handshake(),
        );
    }

    public function testARefusedConnectSaysWhatTheProxyAnswered(): void
    {
        [$proxy, $url] = $this->tunnelling('http', "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->refuseWith(403);

        $error = $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))),
        );

        $this->assertStringContainsString('refused CONNECT provider.invalid:443', $error->getMessage());
        $this->assertStringContainsString('403', $error->getMessage());
        $this->assertStringContainsString($proxy->describe(), $error->getMessage(), 'and which proxy it was');
    }

    public function testAProxyDemandingCredentialsThatHasNoneSaysSo(): void
    {
        [$proxy, $url] = $this->tunnelling('http', "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->requireCredentials();

        $error = $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))),
        );

        $this->assertStringContainsString('407', $error->getMessage());
    }

    // ---- SOCKS5, end to end --------------------------------------------------------------------

    public function testTheRequestArrivesThroughASocks5Proxy(): void
    {
        [$proxy, $url] = $this->tunnelling(
            'socks5',
            "HTTP/1.1 200 OK\r\nContent-Length: 11\r\n\r\n{\"ok\":true}",
        );

        $body = Async::run(static function () use ($proxy, $url): string {
            return (new HttpClient(5.0, $proxy))->send(new Request('POST', $url, [], '{"model":"x"}'))
                ->body->all();
        });

        // Which also proves the bound address in the reply was read past: one byte left there and
        // this would be the first thing the response parser saw.
        $this->assertSame('{"ok":true}', $body);
        $this->assertStringContainsString('{"model":"x"}', $this->target->received());
    }

    public function testSocks5SendsTheHostnameForTheProxyToResolve(): void
    {
        [$proxy, $url] = $this->tunnelling('socks5', "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n");

        Async::run(static fn (): string => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))->body->all());

        // `socks5h` behaviour for both spellings, on purpose: in the network that makes a proxy
        // necessary the local resolver is the other thing that does not work.
        $this->assertSame('provider.invalid:443', $this->proxy?->requested());
        $this->assertStringContainsString(
            "\x03" . chr(strlen('provider.invalid')) . 'provider.invalid',
            (string) $this->proxy?->handshake(),
            'address type 0x03, a name',
        );
    }

    public function testSocks5SendsAnIpAsAnAddress(): void
    {
        [$proxy, $url] = $this->tunnelling(
            'socks5',
            "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n",
            '203.0.113.7:443',
        );

        Async::run(static fn (): string => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))->body->all());

        $this->assertSame('203.0.113.7:443', $this->proxy?->requested(), 'address type 0x01');
    }

    public function testSocks5CredentialsGoThroughTheSubNegotiation(): void
    {
        [$proxy, $url] = $this->tunnelling('socks5', "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->requireCredentials();
        $withCredentials = Proxy::parse('socks5://me:hunter2@' . $proxy->host . ':' . $proxy->port);

        Async::run(static fn (): string => (new HttpClient(5.0, $withCredentials))
            ->send(new Request('GET', $url))->body->all());

        $this->assertStringContainsString('user=me password=hunter2', (string) $this->proxy?->handshake());
    }

    public function testASocks5RefusalIsNamedInWords(): void
    {
        [$proxy, $url] = $this->tunnelling('socks5', "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->refuseWith(0x04);

        $error = $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))),
        );

        // A reply code is four bits of information; the word is what somebody can act on.
        $this->assertStringContainsString('host unreachable', $error->getMessage());
        $this->assertStringContainsString('provider.invalid:443', $error->getMessage());
    }

    public function testASocks5ProxyDemandingCredentialsThatHasNoneSaysSo(): void
    {
        [$proxy, $url] = $this->tunnelling('socks5', "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $this->proxy?->requireCredentials();

        $error = $this->assertThrows(
            HttpError::class,
            static fn () => Async::run(static fn () => (new HttpClient(5.0, $proxy))->send(new Request('GET', $url))),
        );

        $this->assertStringContainsString('username and password', $error->getMessage());
    }

    public function testAProxyThatIsNotThereSaysItWasTheProxy(): void
    {
        // The message this replaces was `Cannot connect to 127.0.0.1:7890`, which does not say
        // whose address that is. Somebody whose Clash is not running needs to be told it is the
        // proxy's, or they go looking at the provider.
        $unused = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr((string) stream_socket_get_name($unused, false), strlen('127.0.0.1:'));
        fclose($unused);

        $proxy = Proxy::parse("socks5://127.0.0.1:{$port}");

        $error = $this->assertThrows(HttpError::class, static fn () => Async::run(
            static fn () => (new HttpClient(2.0, $proxy))->send(new Request('GET', 'http://provider.invalid/')),
        ));

        $this->assertStringContainsString('Cannot reach the proxy', $error->getMessage());
        $this->assertStringContainsString("socks5://127.0.0.1:{$port}", $error->getMessage());
        $this->assertStringContainsString('provider.invalid:80', $error->getMessage(), 'and where it was going');
    }

    // ---- the client ----------------------------------------------------------------------------

    public function testABypassedHostNeverTouchesTheProxy(): void
    {
        $target = $this->target->start(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi"]);
        $this->proxy = new TunnelServer('http');
        $proxy = Proxy::parse($this->proxy->start(), ['*']);

        $body = Async::run(static fn (): string => (new HttpClient(5.0, $proxy))
            ->send(new Request('GET', $target))->body->all());

        $this->assertSame('hi', $body);
        $this->assertSame(0, $this->proxy->connections(), 'the proxy was never dialled');
    }

    public function testUseProxyAppliesToAClientThatWasGivenNone(): void
    {
        [$proxy, $url] = $this->tunnelling('http', "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi");
        HttpClient::useProxy($proxy);

        // The point of the process-wide default: the five providers and four OAuth flows all say
        // `new HttpClient()` and none of them is handed one.
        $body = Async::run(static fn (): string => (new HttpClient(5.0))->send(new Request('GET', $url))->body->all());

        $this->assertSame('hi', $body);
        $this->assertSame($proxy, HttpClient::proxy());
    }

    public function testAClientWithItsOwnProxyIgnoresTheDefault(): void
    {
        $target = $this->target->start(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi"]);
        $this->proxy = new TunnelServer('http');
        HttpClient::useProxy(Proxy::parse($this->proxy->start()));

        // `['*']` on this one, so it goes direct while the default would have tunnelled.
        $own = Proxy::parse('http://127.0.0.1:1', ['*']);

        $body = Async::run(static fn (): string => (new HttpClient(5.0, $own))
            ->send(new Request('GET', $target))->body->all());

        $this->assertSame('hi', $body);
        $this->assertSame(0, $this->proxy->connections());
    }
}
