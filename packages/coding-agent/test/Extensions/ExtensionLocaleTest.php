<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\SessionPool;

final class ExtensionLocaleTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ExtensionApi::resetRegisteredLocales();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ExtensionApi::resetRegisteredLocales();
    }

    public function testExtensionCanRegisterCustomLocaleAndRetrieveIt(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), sys_get_temp_dir() . '/test.php', 'test-ext');

        $api->registerLocale('ja', '日本語', [
            'workspaces' => 'ワークスペース',
            'new_session' => '+ 新規セッション',
        ]);

        $locales = ExtensionApi::registeredLocales();

        $this->assertArrayHasKey('ja', $locales);
        $this->assertSame('日本語', $locales['ja']['label']);
        $this->assertSame('ワークスペース', $locales['ja']['translations']['workspaces']);
        $this->assertSame('+ 新規セッション', $locales['ja']['translations']['new_session']);
    }

    public function testEmptyLocaleStringIsIgnored(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), sys_get_temp_dir() . '/test.php', 'test-ext');

        $api->registerLocale('   ', 'Empty', ['key' => 'val']);

        $this->assertSame([], ExtensionApi::registeredLocales());
    }

    public function testResetRegisteredLocalesClearsState(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), sys_get_temp_dir() . '/test.php', 'test-ext');
        $api->registerLocale('es', 'Español', ['hello' => 'hola']);

        $this->assertNotEmpty(ExtensionApi::registeredLocales());

        ExtensionApi::resetRegisteredLocales();

        $this->assertSame([], ExtensionApi::registeredLocales());
    }

    public function testHttpServerRespondsToApiLocalesRoute(): void
    {
        $api = new ExtensionApi(sys_get_temp_dir(), sys_get_temp_dir() . '/test.php', 'test-ext');
        $api->registerLocale('zh-TW', '繁體中文', [
            'workspaces' => '工作區',
        ]);

        $server = new HttpServer(
            cwd: sys_get_temp_dir(),
            port: 0,
            host: '127.0.0.1'
        );

        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new Connection($serverSock, static fn () => null, static fn () => null);

        $req = [
            'method' => 'GET',
            'path' => '/api/locales',
            'query' => [],
            'headers' => ['host' => '127.0.0.1'],
            'body' => '',
        ];

        // Call handleRequest via reflection
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('handleRequest');
        $method->invoke($server, $conn, $req, 1);

        // Read response from client socket
        $responseRaw = fread($clientSock, 8192);
        $this->assertIsString($responseRaw);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $responseRaw);

        [$head, $body] = explode("\r\n\r\n", $responseRaw, 2);
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('zh-TW', $data['locales']);
        $this->assertSame('繁體中文', $data['locales']['zh-TW']['label']);
        $this->assertSame('工作區', $data['locales']['zh-TW']['translations']['workspaces']);

        $conn->close();
        fclose($clientSock);
    }
}
