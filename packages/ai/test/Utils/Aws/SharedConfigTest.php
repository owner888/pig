<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils\Aws;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Aws\SharedConfig;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * The shared `~/.aws` files, read as smithy's `parseIni()` and `getConfigData()` read them.
 *
 * The end-to-end reading — a profile's region, an assumed role through its source profile, a
 * `credential_process` — is `BedrockTest`'s `a` and `k` cases, against upstream's own runs.
 */
final class SharedConfigTest extends TestCase
{
    use AssertsThrows;

    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        $this->saved = [];
    }

    public function testAProfileSectionWithoutQuotesIsAProfile(): void
    {
        // smithy's `/^([\w-]+)\s(["'])?([\w-@+.%:/]+)\2$/`: JavaScript lets `\2` match nothing when
        // the quote group did not take part; PCRE fails it, which made `[profile work]` a section
        // named "profile work" until the group was written to always take part.
        $parsed = SharedConfig::parseIni("[profile work]\nregion = ap-southeast-1\n[profile \"quoted\"]\nregion = eu-west-1\n");

        self::assertSame(['profile.work' => ['region' => 'ap-southeast-1'], 'profile.quoted' => ['region' => 'eu-west-1']], $parsed);
    }

    public function testCommentsSubSectionsAndUnknownSectionTypesAreSmithys(): void
    {
        $parsed = SharedConfig::parseIni(implode("\n", [
            '# a comment',
            '[default]',
            'region = us-east-1 ; trailing comment',
            'url = https://example.com/#fragment',
            's3 =',
            '  max_concurrent_requests = 10',
            'output = json',
            '[sso-session corp]',
            'sso_region = us-east-1',
            '[weird thing]',
            'ignored = yes',
        ]));

        self::assertSame([
            'default' => [
                'region' => 'us-east-1',
                // `#` only starts a comment at the line's start or after white space.
                'url' => 'https://example.com/#fragment',
                's3.max_concurrent_requests' => '10',
                'output' => 'json',
            ],
            'sso-session.corp' => ['sso_region' => 'us-east-1'],
        ], $parsed);
    }

    public function testAProtoProfileIsRefused(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => SharedConfig::parseIni("[profile __proto__]\nx = 1\n"), 'Found invalid profile name "profile __proto__"');
    }

    public function testTheCredentialsFilesKeysWinOverTheConfigFiles(): void
    {
        $dir = sys_get_temp_dir() . '/pig-aws-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/config", "[default]\nregion = eu-west-1\n[profile work]\nregion = ap-southeast-1\naws_access_key_id = FROMCONFIG\n[services local]\nbedrock_runtime =\n  endpoint_url = http://localhost:4000\n");
        file_put_contents("{$dir}/credentials", "[work]\naws_access_key_id = FROMCREDENTIALS\naws_secret_access_key = s\n");
        $this->set('AWS_CONFIG_FILE', "{$dir}/config");
        $this->set('AWS_SHARED_CREDENTIALS_FILE', "{$dir}/credentials");

        $profiles = SharedConfig::profiles();

        self::assertSame(['region' => 'ap-southeast-1', 'aws_access_key_id' => 'FROMCREDENTIALS', 'aws_secret_access_key' => 's'], $profiles['work']);
        self::assertSame(['region' => 'eu-west-1'], $profiles['default']);
        // `[services name]` keeps its prefix, and is not a profile.
        self::assertSame(['bedrock_runtime.endpoint_url' => 'http://localhost:4000'], $profiles['services.local']);

        unlink("{$dir}/config");
        unlink("{$dir}/credentials");
        rmdir($dir);
    }

    public function testTheProfileNameIsTheAskedForOneThenAwsProfileThenDefault(): void
    {
        $this->set('AWS_PROFILE', 'fromenv');
        self::assertSame('asked', SharedConfig::profileName('asked'));
        self::assertSame('fromenv', SharedConfig::profileName(null));

        $this->set('AWS_PROFILE', '');
        self::assertSame('default', SharedConfig::profileName(null));
    }

    public function testABooleanSettingIsTrueFalseOrRefused(): void
    {
        self::assertTrue(SharedConfig::booleanSelector(['use_fips_endpoint' => 'true'], 'use_fips_endpoint', 'config'));
        self::assertNull(SharedConfig::booleanSelector([], 'use_fips_endpoint', 'config'));
        $this->assertThrows(
            RuntimeException::class,
            static fn () => SharedConfig::booleanSelector(['use_fips_endpoint' => 'yes'], 'use_fips_endpoint', 'config'),
            'Cannot load config "use_fips_endpoint". Expected "true" or "false", got yes.',
        );
    }

    private function set(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv("{$name}={$value}");
    }
}
