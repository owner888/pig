<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;

/**
 * Where an AWS service answers: an endpoint configured in the environment or the shared files, the
 * partitions (`@aws-sdk/core`'s `partitions.json`, matched by
 * region name, then by each partition's `regionRegex`, else `aws`) and the Bedrock runtime's own
 * endpoint rules (`client-bedrock-runtime/endpoint/bdd.js`).
 *
 * @internal
 */
final class Endpoints
{
    /** id => [regionRegex, dnsSuffix, dualStackDnsSuffix], in `partitions.json`'s order. */
    private const array PARTITIONS = [
        'aws' => ['^(us|eu|ap|sa|ca|me|af|il|mx)\-\w+\-\d+$', 'amazonaws.com', 'api.aws'],
        'aws-cn' => ['^cn\-\w+\-\d+$', 'amazonaws.com.cn', 'api.amazonwebservices.com.cn'],
        'aws-eusc' => ['^eusc\-(de)\-\w+\-\d+$', 'amazonaws.eu', 'api.amazonwebservices.eu'],
        'aws-iso' => ['^us\-iso\-\w+\-\d+$', 'c2s.ic.gov', 'api.aws.ic.gov'],
        'aws-iso-b' => ['^us\-isob\-\w+\-\d+$', 'sc2s.sgov.gov', 'api.aws.scloud'],
        'aws-iso-e' => ['^eu\-isoe\-\w+\-\d+$', 'cloud.adc-e.uk', 'api.cloud-aws.adc-e.uk'],
        'aws-iso-f' => ['^us\-isof\-\w+\-\d+$', 'csp.hci.ic.gov', 'api.aws.hci.ic.gov'],
        'aws-us-gov' => ['^us\-gov\-\w+\-\d+$', 'amazonaws.com', 'api.aws'],
    ];

    /** The named regions each partition lists, which win over the regexes. */
    private const array REGIONS = [
        'aws-cn' => ['aws-cn-global', 'cn-north-1', 'cn-northwest-1'],
        'aws-eusc' => ['eusc-de-east-1'],
        'aws-iso' => ['aws-iso-global', 'us-iso-east-1', 'us-iso-west-1'],
        'aws-iso-b' => ['aws-iso-b-global', 'us-isob-east-1', 'us-isob-west-1'],
        'aws-iso-e' => ['aws-iso-e-global', 'eu-isoe-west-1'],
        'aws-iso-f' => ['aws-iso-f-global', 'us-isof-east-1', 'us-isof-south-1'],
        'aws-us-gov' => ['aws-us-gov-global', 'us-gov-east-1', 'us-gov-west-1'],
    ];

    /**
     * `aws.partition(region)`: every partition here supports FIPS and dual-stack.
     *
     * @return array{name: string, dnsSuffix: string, dualStackDnsSuffix: string}
     */
    public static function partition(string $region): array
    {
        foreach (self::REGIONS as $id => $regions) {
            if (in_array($region, $regions, true)) {
                return self::outputs($id);
            }
        }

        foreach (self::PARTITIONS as $id => [$regex]) {
            if (preg_match('/' . $regex . '/', $region) === 1) {
                return self::outputs($id);
            }
        }

        return self::outputs('aws');
    }

    /**
     * The Bedrock runtime's endpoint rules: a configured endpoint as it is (refused with FIPS or
     * dual-stack, which a custom endpoint cannot have), else `bedrock-runtime[-fips].<region>.<suffix>`.
     */
    public static function bedrockRuntime(?string $endpoint, ?string $region, bool $useFips, bool $useDualStack): string
    {
        if ($endpoint !== null) {
            if ($useFips) {
                throw new RuntimeException('Invalid Configuration: FIPS and custom endpoint are not supported');
            }

            if ($useDualStack) {
                throw new RuntimeException('Invalid Configuration: Dualstack and custom endpoint are not supported');
            }

            return $endpoint;
        }

        if ($region === null) {
            throw new RuntimeException('Invalid Configuration: Missing Region');
        }

        $partition = self::partition($region);
        $suffix = $useDualStack ? $partition['dualStackDnsSuffix'] : $partition['dnsSuffix'];

        return 'https://bedrock-runtime' . ($useFips ? '-fips' : '') . ".{$region}.{$suffix}";
    }

    /**
     * smithy's `getEndpointFromConfig(serviceId)`: an endpoint configured outside the code —
     * `AWS_ENDPOINT_URL_<SERVICE ID>` (upper case, spaces as `_`), `AWS_ENDPOINT_URL`, then the
     * profile's `[services …]` section (`<service id>.endpoint_url`, lower case) or its own
     * `endpoint_url` — unless `AWS_IGNORE_CONFIGURED_ENDPOINT_URLS` / `ignore_configured_endpoint_urls`
     * says to ignore them all. The profile is `AWS_PROFILE`'s or `default`, not the client's.
     */
    public static function configured(string $serviceId): ?string
    {
        $ignore = SharedConfig::loadConfig(
            static fn (): ?bool => getenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS') === false
                ? null
                : SharedConfig::booleanSelector(['AWS_IGNORE_CONFIGURED_ENDPOINT_URLS' => (string) getenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS')], 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS', 'environment variable'),
            static fn (array $values): ?bool => SharedConfig::booleanSelector($values, 'ignore_configured_endpoint_urls', 'config'),
            static fn (): bool => false,
            null,
        );

        if ($ignore === true) {
            return null;
        }

        $parts = explode(' ', $serviceId);
        $fromEnv = SharedConfig::env(implode('_', ['AWS_ENDPOINT_URL', ...array_map('strtoupper', $parts)])) ?? SharedConfig::env('AWS_ENDPOINT_URL');

        if ($fromEnv !== null) {
            return $fromEnv;
        }

        $files = SharedConfig::load();
        $name = SharedConfig::profileName(null);
        $profile = [...($files['configFile'][$name] ?? []), ...($files['credentialsFile'][$name] ?? [])];

        if (isset($profile['services'])) {
            $section = $files['configFile']['services.' . $profile['services']] ?? null;

            if ($section === null) {
                throw new RuntimeException("The services section \"{$profile['services']}\" specified in the profile is not present in the shared configuration file.");
            }

            $fromServices = $section[implode('_', array_map('strtolower', $parts)) . '.endpoint_url'] ?? null;

            if ($fromServices !== null && $fromServices !== '') {
                return $fromServices;
            }
        }

        $fromProfile = $profile['endpoint_url'] ?? null;

        return $fromProfile !== null && $fromProfile !== '' ? $fromProfile : null;
    }

    /** @return array{name: string, dnsSuffix: string, dualStackDnsSuffix: string} */
    private static function outputs(string $id): array
    {
        return ['name' => $id, 'dnsSuffix' => self::PARTITIONS[$id][1], 'dualStackDnsSuffix' => self::PARTITIONS[$id][2]];
    }
}
