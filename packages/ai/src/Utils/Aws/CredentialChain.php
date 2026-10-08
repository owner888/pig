<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use RuntimeException;
use Throwable;

/**
 * The AWS SDK for JavaScript's default credential chain — `@aws-sdk/credential-provider-node`'s
 * `defaultProvider()` and the providers it chains — which is what upstream's Bedrock client falls back
 * on whenever it is not handed credentials itself.
 *
 * In the SDK's order, each link either answering or saying "not me" (`CredentialsProviderError` with
 * `tryNextLink`, the default), which moves on to the next:
 *
 * 1. **the environment** — `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` (+ `AWS_SESSION_TOKEN`,
 *    `AWS_CREDENTIAL_EXPIRATION`, `AWS_ACCOUNT_ID`), skipped outright when a profile is named, either
 *    to the client or in `AWS_PROFILE`;
 * 2. **SSO from the client's own SSO fields** — which upstream never passes, so it always declines;
 * 3. **the shared files** (`fromIni`) — a profile's static keys, an assumed role (`role_arn` with
 *    `source_profile` or `credential_source`, through STS `AssumeRole`), web identity (`role_arn` +
 *    `web_identity_token_file`, through STS `AssumeRoleWithWebIdentity`), `credential_process`, and
 *    IAM Identity Center (`sso_session` or the legacy `sso_start_url` keys, from the token
 *    `aws sso login` caches, refreshed through SSO-OIDC when an `sso_session` one is about to expire);
 * 4. **`credential_process`** on the profile, on its own (`fromProcess`);
 * 5. **web identity from the environment** — `AWS_WEB_IDENTITY_TOKEN_FILE` + `AWS_ROLE_ARN`;
 * 6. **the container or the instance** — ECS/EKS (`AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` or
 *    `_FULL_URI`, with `AWS_CONTAINER_AUTHORIZATION_TOKEN[_FILE]`), else the EC2 instance metadata
 *    service (IMDSv2, with the v1 fallback the SDK makes) unless `AWS_EC2_METADATA_DISABLED` says no;
 * 7. "Could not load credentials from any providers".
 *
 * The texts are the SDK's, because the last one standing is the turn's error message. What is not
 * here: the `login_session` profiles of the newer `aws login` (refused by name), MFA prompts (the SDK
 * refuses those too without a callback, and pig has none to give), and the static-stability extension
 * of expired instance credentials.
 *
 * @internal
 */
final class CredentialChain
{
    /** `@smithy/credential-provider-imds`: one second, no retries, unless configured otherwise. */
    private const float METADATA_TIMEOUT = 1.0;

    /** `@aws-sdk/credential-provider-http`: one second per try, three retries a second apart. */
    private const int HTTP_PROVIDER_RETRIES = 3;

    private const string ECS_HOST = 'http://169.254.170.2';

    private const string IMDS_PATH = '/latest/meta-data/iam/security-credentials/';

    /** The SSO token provider's refresh window, `EXPIRE_WINDOW_MS`. */
    private const int SSO_EXPIRE_WINDOW_MS = 300_000;

    private const string SSO_REFRESH_MESSAGE = "To refresh this SSO session run 'aws sso login' with the corresponding profile.";

    /**
     * `@aws-sdk/nested-clients`' version in upstream's lockfile: the STS, SSO and SSO-OIDC clients the
     * credential providers build, whose user agent names it (`aws-sdk-js/3.997.45 … api/sts#3.997.45`).
     */
    public const string NESTED_CLIENTS_VERSION = '3.997.45';

    /**
     * @param string|null $profile the client's `profile` — upstream's `options.profile`, a scoped
     *        `AWS_PROFILE`, or the ambient one
     * @param string|null $region the client's resolved region, for the STS and SSO clients the
     *        providers build (their `parentClientConfig.region`)
     * @param (Closure(): int)|null $now milliseconds, for tests
     * @param int $maxAttempts the STS and SSO clients' own, which they resolve as the Bedrock client
     *        does (`AWS_MAX_ATTEMPTS`, the profile's `max_attempts`, three)
     * @param (Closure(int): void)|null $sleep the retry back-off's wait, for tests
     */
    public function __construct(
        private readonly ?string $profile,
        private readonly ?string $region,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?AbortSignal $signal = null,
        private readonly ?Closure $now = null,
        private readonly int $maxAttempts = 3,
        private readonly ?Closure $sleep = null,
    ) {
    }

    /** `defaultProvider(init)()`: the first link that answers, or the last error standing. */
    public function resolve(): Credentials
    {
        $links = [
            fn (): Credentials => $this->fromEnvUnlessProfiled(),
            static fn (): Credentials => throw new CredentialsProviderError('Skipping SSO provider in default chain (inputs do not include SSO fields).'),
            fn (): Credentials => $this->fromIni(),
            fn (): Credentials => $this->fromProcess(SharedConfig::profileName($this->profile), SharedConfig::profiles()),
            fn (): Credentials => $this->fromTokenFile(null, null, null),
            fn (): Credentials => $this->remote(),
            static fn (): Credentials => throw new CredentialsProviderError('Could not load credentials from any providers', false),
        ];

        return self::chain($links);
    }

    /**
     * smithy's `chain()`: each provider in turn; a `CredentialsProviderError` that allows it moves on,
     * anything else ends the chain with itself.
     *
     * @param list<Closure(): Credentials> $providers
     */
    private static function chain(array $providers): Credentials
    {
        $last = null;

        foreach ($providers as $provider) {
            try {
                return $provider();
            } catch (CredentialsProviderError $error) {
                if (!$error->tryNextLink) {
                    throw $error;
                }

                $last = $error;
            }
        }

        throw $last ?? new CredentialsProviderError('No providers in chain');
    }

    private function fromEnvUnlessProfiled(): Credentials
    {
        $profile = $this->profile !== null && $this->profile !== '' ? $this->profile : SharedConfig::env('AWS_PROFILE');

        if ($profile !== null) {
            throw new CredentialsProviderError('AWS_PROFILE is set, skipping fromEnv provider.');
        }

        return self::fromEnv();
    }

    /** `@aws-sdk/credential-provider-env`'s `fromEnv()`. */
    public static function fromEnv(): Credentials
    {
        $accessKeyId = SharedConfig::env('AWS_ACCESS_KEY_ID');
        $secretAccessKey = SharedConfig::env('AWS_SECRET_ACCESS_KEY');

        if ($accessKeyId === null || $secretAccessKey === null) {
            throw new CredentialsProviderError('Unable to find environment variable credentials.');
        }

        $expiry = SharedConfig::env('AWS_CREDENTIAL_EXPIRATION');

        return (new Credentials(
            $accessKeyId,
            $secretAccessKey,
            SharedConfig::env('AWS_SESSION_TOKEN'),
            $expiry !== null ? self::parseDate($expiry) : null,
            SharedConfig::env('AWS_ACCOUNT_ID'),
        ))->withFeature('CREDENTIALS_ENV_VARS', 'g');
    }

    /** `@aws-sdk/credential-provider-ini`'s `fromIni()`. */
    private function fromIni(): Credentials
    {
        return $this->resolveProfileData(SharedConfig::profileName($this->profile), SharedConfig::profiles(), [], false);
    }

    /**
     * `resolveProfileData()`.
     *
     * @param array<string, array<string, string>> $profiles
     * @param array<string, true> $visited
     */
    private function resolveProfileData(string $name, array $profiles, array $visited, bool $isAssumeRoleRecursiveCall): Credentials
    {
        $data = $profiles[$name] ?? null;

        if ($visited !== [] && self::isStatic($data)) {
            return self::staticCredentials($data);
        }

        if ($isAssumeRoleRecursiveCall || self::isAssumeRole($data)) {
            return $this->assumeRoleCredentials($name, $profiles, $visited);
        }

        if (self::isStatic($data)) {
            return self::staticCredentials($data);
        }

        if (is_array($data) && isset($data['web_identity_token_file'], $data['role_arn'])) {
            return $this->fromTokenFile($data['web_identity_token_file'], $data['role_arn'], $data['role_session_name'] ?? null)
                ->withFeature('CREDENTIALS_PROFILE_STS_WEB_ID_TOKEN', 'q');
        }

        if (is_array($data) && isset($data['credential_process'])) {
            return $this->fromProcess($name, $profiles)->withFeature('CREDENTIALS_PROFILE_PROCESS', 'v');
        }

        if (is_array($data) && self::isSso($data)) {
            return $this->fromSso($name, $profiles, $data);
        }

        if (is_array($data) && isset($data['login_session'])) {
            // `resolveLoginCredentials()` (the `aws login` sessions): not ported, and said so.
            throw new CredentialsProviderError("Profile {$name} uses login_session credentials (aws login), which pig does not read; use another credential source for this profile.", false);
        }

        throw new CredentialsProviderError("Could not resolve credentials using profile: [{$name}] in configuration/credentials file(s).");
    }

    /** @param array<string, string>|null $data */
    private static function isStatic(?array $data): bool
    {
        return is_array($data) && isset($data['aws_access_key_id'], $data['aws_secret_access_key']);
    }

    /** @param array<string, string> $data */
    private static function staticCredentials(array $data): Credentials
    {
        return (new Credentials(
            $data['aws_access_key_id'],
            $data['aws_secret_access_key'],
            $data['aws_session_token'] ?? null,
            null,
            $data['aws_account_id'] ?? null,
        ))->withFeature('CREDENTIALS_PROFILE', 'n');
    }

    /** `isAssumeRoleProfile()`: a `role_arn` and exactly one of `source_profile` and `credential_source`. */
    private static function isAssumeRole(?array $data): bool
    {
        return is_array($data)
            && isset($data['role_arn'])
            && (isset($data['source_profile']) !== isset($data['credential_source']));
    }

    /** @param array<string, string> $data */
    private static function isSso(array $data): bool
    {
        foreach (['sso_start_url', 'sso_account_id', 'sso_session', 'sso_region', 'sso_role_name'] as $key) {
            if (isset($data[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * `resolveAssumeRoleCredentials()`.
     *
     * @param array<string, array<string, string>> $profiles
     * @param array<string, true> $visited
     */
    private function assumeRoleCredentials(string $name, array $profiles, array $visited): Credentials
    {
        $data = $profiles[$name] ?? [];
        $sourceProfile = $data['source_profile'] ?? null;

        if ($sourceProfile !== null && isset($visited[$sourceProfile])) {
            throw new CredentialsProviderError(
                'Detected a cycle attempting to resolve credentials for profile ' . SharedConfig::profileName($this->profile)
                . '. Profiles visited: ' . implode(', ', array_keys($visited)),
            );
        }

        $source = $sourceProfile !== null
            ? fn (): Credentials => $this->resolveProfileData(
                $sourceProfile,
                $profiles,
                [...$visited, $sourceProfile => true],
                !isset($profiles[$sourceProfile]['role_arn']) && isset($profiles[$sourceProfile]['credential_source']),
            )
            : $this->credentialSource($data['credential_source'] ?? '', $name);

        if (!isset($data['role_arn']) && isset($data['credential_source'])) {
            return $source()->withFeature('CREDENTIALS_PROFILE_SOURCE_PROFILE', 'o');
        }

        if (isset($data['mfa_serial'])) {
            throw new CredentialsProviderError("Profile {$name} requires multi-factor authentication, but no MFA code callback was provided.", false);
        }

        $params = [
            'RoleArn' => $data['role_arn'],
            'RoleSessionName' => ($data['role_session_name'] ?? '') !== '' ? $data['role_session_name'] : 'aws-sdk-js-' . $this->nowMs(),
            'DurationSeconds' => (string) (int) ($data['duration_seconds'] ?? '3600'),
        ];

        if (isset($data['external_id'])) {
            $params['ExternalId'] = $data['external_id'];
        }

        return $this->assumeRole($source(), $params, $data['region'] ?? $this->region)
            ->withFeature('CREDENTIALS_PROFILE_SOURCE_PROFILE', 'o');
    }

    /**
     * `resolveCredentialSource()`.
     *
     * @return Closure(): Credentials
     */
    private function credentialSource(string $source, string $profile): Closure
    {
        return match ($source) {
            'EcsContainer' => fn (): Credentials => self::chain([
                fn (): Credentials => $this->fromHttp(),
                fn (): Credentials => $this->fromContainerMetadata(),
            ])->withFeature('CREDENTIALS_PROFILE_NAMED_PROVIDER', 'p'),
            'Ec2InstanceMetadata' => fn (): Credentials => $this->fromInstanceMetadata()->withFeature('CREDENTIALS_PROFILE_NAMED_PROVIDER', 'p'),
            'Environment' => static fn (): Credentials => self::fromEnv()->withFeature('CREDENTIALS_PROFILE_NAMED_PROVIDER', 'p'),
            default => throw new CredentialsProviderError("Unsupported credential source in profile {$profile}. Got {$source}, expected EcsContainer or Ec2InstanceMetadata or Environment."),
        };
    }

    /**
     * `@aws-sdk/credential-provider-process`'s `resolveProcessCredentials()`.
     *
     * @param array<string, array<string, string>> $profiles
     */
    private function fromProcess(string $name, array $profiles): Credentials
    {
        $profile = $profiles[$name] ?? null;

        if ($profile === null) {
            throw new CredentialsProviderError("Profile {$name} could not be found in shared credentials file.");
        }

        $command = $profile['credential_process'] ?? null;

        if ($command === null) {
            throw new CredentialsProviderError("Profile {$name} did not contain credential_process.");
        }

        try {
            $stdout = self::exec($command);
            $data = json_decode(trim($stdout), true);

            if (!is_array($data)) {
                throw new RuntimeException("Profile {$name} credential_process returned invalid JSON.");
            }

            if (($data['Version'] ?? null) !== 1) {
                throw new RuntimeException("Profile {$name} credential_process did not return Version 1.");
            }

            if (!isset($data['AccessKeyId'], $data['SecretAccessKey'])) {
                throw new RuntimeException("Profile {$name} credential_process returned invalid credentials.");
            }

            $expiration = is_string($data['Expiration'] ?? null) && $data['Expiration'] !== '' ? self::parseDate($data['Expiration']) : null;

            if ($expiration !== null && $expiration < $this->nowMs()) {
                throw new RuntimeException("Profile {$name} credential_process returned expired credentials.");
            }

            $accountId = is_string($data['AccountId'] ?? null) && $data['AccountId'] !== '' ? $data['AccountId'] : ($profile['aws_account_id'] ?? null);
        } catch (Throwable $error) {
            throw new CredentialsProviderError($error->getMessage(), true, $error);
        }

        return (new Credentials(
            (string) $data['AccessKeyId'],
            (string) $data['SecretAccessKey'],
            is_string($data['SessionToken'] ?? null) && $data['SessionToken'] !== '' ? $data['SessionToken'] : null,
            $expiration,
            $accountId,
        ))->withFeature('CREDENTIALS_PROCESS', 'w');
    }

    /** Node's `child_process.exec()`: the command through the shell, stdout back, a failure as its message. */
    private static function exec(string $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("Command failed: {$command}");
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new RuntimeException("Command failed: {$command}\n{$stderr}");
        }

        return $stdout;
    }

    /** `@aws-sdk/credential-provider-web-identity`'s `fromTokenFile()`. */
    private function fromTokenFile(?string $tokenFile, ?string $roleArn, ?string $roleSessionName): Credentials
    {
        $envTokenFile = SharedConfig::env('AWS_WEB_IDENTITY_TOKEN_FILE');
        $tokenFile ??= $envTokenFile;
        $roleArn ??= SharedConfig::env('AWS_ROLE_ARN');
        $roleSessionName ??= SharedConfig::env('AWS_ROLE_SESSION_NAME');

        if ($tokenFile === null || $roleArn === null) {
            throw new CredentialsProviderError('Web identity configuration not specified');
        }

        if (!is_file($tokenFile) || !is_readable($tokenFile)) {
            throw new RuntimeException("ENOENT: no such file or directory, open '{$tokenFile}'");
        }

        $credentials = $this->assumeRoleWithWebIdentity([
            'RoleArn' => $roleArn,
            'RoleSessionName' => $roleSessionName ?? 'aws-sdk-js-session-' . $this->nowMs(),
            'WebIdentityToken' => (string) file_get_contents($tokenFile),
        ], $this->region);

        return $tokenFile === $envTokenFile
            ? $credentials->withFeature('CREDENTIALS_ENV_VARS_STS_WEB_ID_TOKEN', 'h')
            : $credentials;
    }

    /**
     * `@aws-sdk/credential-provider-sso`'s `fromSSO()` for a profile, and `resolveSSOCredentials()`.
     *
     * @param array<string, array<string, string>> $profiles
     * @param array<string, string> $profile
     */
    private function fromSso(string $name, array $profiles, array $profile): Credentials
    {
        $session = $profile['sso_session'] ?? null;

        if ($session !== null) {
            $sessionData = SharedConfig::ssoSessions()[$session] ?? [];
            $profile['sso_region'] = $sessionData['sso_region'] ?? '';
            $profile['sso_start_url'] = $sessionData['sso_start_url'] ?? '';
        }

        foreach (['sso_start_url', 'sso_account_id', 'sso_region', 'sso_role_name'] as $key) {
            if (($profile[$key] ?? '') === '') {
                throw new CredentialsProviderError(
                    'Profile is configured with invalid SSO credentials. Required parameters "sso_account_id", '
                    . '"sso_region", "sso_role_name", "sso_start_url". Got ' . implode(', ', array_keys($profile))
                    . "\nReference: https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html",
                    false,
                );
            }
        }

        if ($session !== null) {
            try {
                $accessToken = $this->ssoSessionToken($name, $session);
            } catch (Throwable $error) {
                throw new CredentialsProviderError($error->getMessage(), false, $error);
            }
        } else {
            $refresh = 'To refresh this SSO session run aws sso login with the corresponding profile.';
            $token = self::readSsoToken($profile['sso_start_url']);

            if ($token === null) {
                throw new CredentialsProviderError("The SSO session associated with this profile is invalid. {$refresh}", false);
            }

            if (self::parseDate((string) ($token['expiresAt'] ?? '')) - $this->nowMs() <= 0) {
                throw new CredentialsProviderError("The SSO session associated with this profile has expired. {$refresh}", false);
            }

            $accessToken = (string) ($token['accessToken'] ?? '');
        }

        $region = $profile['sso_region'];
        $url = rtrim(Endpoints::configured('SSO') ?? "https://portal.sso.{$region}." . Endpoints::partition($region)['dnsSuffix'], '/')
            . '/federation/credentials?account_id=' . rawurlencode($profile['sso_account_id'])
            . '&role_name=' . rawurlencode($profile['sso_role_name']);

        try {
            [, , $body] = $this->call(
                'sso',
                [],
                $url,
                static fn (array $sdk): Request => new Request('GET', $url, ['x-amz-sso_bearer_token' => $accessToken, ...$sdk]),
                static fn (int $status, array $headers, string $body): ServiceError => RestJson::error($status, $headers, $body, false),
            );
        } catch (ServiceError $error) {
            // `new CredentialsProviderError(e)`: the SDK error stringified, `<name>: <message>`.
            throw new CredentialsProviderError("{$error->name}: {$error->getMessage()}", false, $error);
        } catch (Throwable $error) {
            throw new CredentialsProviderError($error->getMessage(), false, $error);
        }

        $role = json_decode($body, true)['roleCredentials'] ?? [];

        if (!is_array($role) || !isset($role['accessKeyId'], $role['secretAccessKey'], $role['sessionToken'], $role['expiration'])) {
            throw new CredentialsProviderError('SSO returns an invalid temporary credential.', false);
        }

        return (new Credentials(
            (string) $role['accessKeyId'],
            (string) $role['secretAccessKey'],
            (string) $role['sessionToken'],
            (int) $role['expiration'],
            is_string($role['accountId'] ?? null) ? $role['accountId'] : null,
        ))
            ->withFeature($session !== null ? 'CREDENTIALS_SSO' : 'CREDENTIALS_SSO_LEGACY', $session !== null ? 's' : 'u')
            ->withFeature($session !== null ? 'CREDENTIALS_PROFILE_SSO' : 'CREDENTIALS_PROFILE_SSO_LEGACY', $session !== null ? 'r' : 't');
    }

    /** `@aws-sdk/token-providers`' `fromSso()`: the cached token, refreshed through SSO-OIDC near its end. */
    private function ssoSessionToken(string $profile, string $session): string
    {
        $sessionData = SharedConfig::ssoSessions()[$session] ?? null;

        if ($sessionData === null) {
            throw new RuntimeException("Sso session '{$session}' could not be found in shared credentials file.");
        }

        foreach (['sso_start_url', 'sso_region'] as $key) {
            if (($sessionData[$key] ?? '') === '') {
                throw new RuntimeException("Sso session '{$session}' is missing required property '{$key}'.");
            }
        }

        $token = self::readSsoToken($session);

        if ($token === null) {
            throw new RuntimeException("The SSO session token associated with profile={$profile} was not found or is invalid. " . self::SSO_REFRESH_MESSAGE);
        }

        foreach (['accessToken', 'expiresAt'] as $key) {
            if (!array_key_exists($key, $token)) {
                throw new RuntimeException("Value not present for '{$key}' in SSO Token. " . self::SSO_REFRESH_MESSAGE);
            }
        }

        $expiration = self::parseDate((string) $token['expiresAt']);

        if ($expiration - $this->nowMs() > self::SSO_EXPIRE_WINDOW_MS) {
            return (string) $token['accessToken'];
        }

        foreach (['clientId', 'clientSecret', 'refreshToken'] as $key) {
            if (!array_key_exists($key, $token)) {
                throw new RuntimeException("Value not present for '{$key}' in SSO Token. Cannot refresh. " . self::SSO_REFRESH_MESSAGE);
            }
        }

        $region = $sessionData['sso_region'];

        $url = rtrim(Endpoints::configured('SSO OIDC') ?? "https://oidc.{$region}." . Endpoints::partition($region)['dnsSuffix'], '/') . '/token';
        $request = (string) json_encode([
            'clientId' => $token['clientId'],
            'clientSecret' => $token['clientSecret'],
            'grantType' => 'refresh_token',
            'refreshToken' => $token['refreshToken'],
        ], JSON_UNESCAPED_SLASHES);

        try {
            [$status, , $body] = $this->call(
                'sso-oidc',
                [],
                $url,
                static fn (array $sdk): Request => new Request('POST', $url, ['content-type' => 'application/json', 'content-length' => (string) strlen($request), ...$sdk], $request),
                static fn (int $status, array $headers, string $body): ServiceError => RestJson::error($status, $headers, $body, false),
            );
            $fresh = json_decode($body, true);

            if (!is_array($fresh) || !isset($fresh['accessToken'], $fresh['expiresIn'])) {
                throw new RuntimeException("SSO-OIDC CreateToken answered {$status} without a token");
            }
        } catch (Throwable $error) {
            // The SDK's `catch (error) { validateTokenExpiry(existingToken); return existingToken; }`:
            // a failed refresh keeps a token that is still good, and refuses one that is not.
            if ($expiration < $this->nowMs()) {
                throw new RuntimeException('Token is expired. ' . self::SSO_REFRESH_MESSAGE, 0, $error);
            }

            return (string) $token['accessToken'];
        }

        $refreshed = [
            ...$token,
            'accessToken' => $fresh['accessToken'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s', intdiv($this->nowMs() + (int) $fresh['expiresIn'] * 1000, 1000)) . '.000Z',
            'refreshToken' => $fresh['refreshToken'] ?? null,
        ];
        $path = self::ssoTokenPath($session);

        // The SDK writes the refreshed token back and ignores a failure to (`catch (error) {}`): the
        // token in hand is good either way. Checked first rather than suppressed.
        if (is_writable($path)) {
            file_put_contents($path, (string) json_encode($refreshed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return (string) $fresh['accessToken'];
    }

    /** @return array<string, mixed>|null `getSSOTokenFromFile()`, null where it would throw */
    private static function readSsoToken(string $id): ?array
    {
        $path = self::ssoTokenPath($id);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $token = json_decode((string) file_get_contents($path), true);

        return is_array($token) ? $token : null;
    }

    /** `getSSOTokenFilepath()`: `~/.aws/sso/cache/<sha1(id)>.json`. */
    private static function ssoTokenPath(string $id): string
    {
        return SharedConfig::homeDir() . '/.aws/sso/cache/' . sha1($id) . '.json';
    }

    /** `remoteProvider()`: the container when it says so, otherwise the instance — unless that is switched off. */
    private function remote(): Credentials
    {
        if (SharedConfig::env('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI') !== null || SharedConfig::env('AWS_CONTAINER_CREDENTIALS_FULL_URI') !== null) {
            return self::chain([
                fn (): Credentials => $this->fromHttp(),
                fn (): Credentials => $this->fromContainerMetadata(),
            ]);
        }

        $disabled = SharedConfig::env('AWS_EC2_METADATA_DISABLED');

        if ($disabled !== null && $disabled !== 'false') {
            throw new CredentialsProviderError('EC2 Instance Metadata Service access disabled');
        }

        return $this->fromInstanceMetadata();
    }

    /** `@aws-sdk/credential-provider-http`'s `fromHttp()`. */
    private function fromHttp(): Credentials
    {
        $relative = SharedConfig::env('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        $full = SharedConfig::env('AWS_CONTAINER_CREDENTIALS_FULL_URI');
        $token = SharedConfig::env('AWS_CONTAINER_AUTHORIZATION_TOKEN');
        $tokenFile = SharedConfig::env('AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE');

        $url = match (true) {
            $relative !== null => self::ECS_HOST . $relative,
            $full !== null => $full,
            default => throw new CredentialsProviderError("No HTTP credential provider host provided.\nSet AWS_CONTAINER_CREDENTIALS_FULL_URI or AWS_CONTAINER_CREDENTIALS_RELATIVE_URI."),
        };

        self::checkContainerUrl($url);
        $attempt = function () use ($url, $token, $tokenFile): Credentials {
            $headers = [];

            if ($tokenFile !== null) {
                $headers['Authorization'] = self::validateContainerToken((string) file_get_contents($tokenFile));
            } elseif ($token !== null) {
                $headers['Authorization'] = self::validateContainerToken($token);
            }

            try {
                [$status, , $body] = $this->send(new Request('GET', $url, $headers), self::METADATA_TIMEOUT);
            } catch (Throwable $error) {
                throw new CredentialsProviderError('Error: ' . $error->getMessage(), true, $error);
            }

            if ($status === 200) {
                $parsed = json_decode($body, true);

                if (!is_array($parsed) || !is_string($parsed['AccessKeyId'] ?? null) || !is_string($parsed['SecretAccessKey'] ?? null)
                    || !is_string($parsed['Token'] ?? null) || !is_string($parsed['Expiration'] ?? null)) {
                    throw new CredentialsProviderError('HTTP credential provider response not of the required format, an object matching: '
                        . '{ AccessKeyId: string, SecretAccessKey: string, Token: string, Expiration: string(rfc3339) }');
                }

                return (new Credentials($parsed['AccessKeyId'], $parsed['SecretAccessKey'], $parsed['Token'], self::parseDate($parsed['Expiration'])))
                    ->withFeature('CREDENTIALS_HTTP', 'z');
            }

            throw new CredentialsProviderError("Server responded with status: {$status}");
        };

        // `retryWrapper(toRetry, 3, 1000)`: three tries a second apart, then a last one that throws.
        for ($i = 0; $i < self::HTTP_PROVIDER_RETRIES; $i++) {
            try {
                return $attempt();
            } catch (Throwable) {
                Async::delay(self::METADATA_TIMEOUT);
            }
        }

        return $attempt();
    }

    /** `checkUrl()`: HTTPS, or a loopback, ECS or EKS address. */
    private static function checkContainerUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (is_array($parts) && ($parts['scheme'] ?? '') === 'https') {
            return;
        }

        if (in_array($host, ['169.254.170.2', '169.254.170.23', '[fd00:ec2::23]', '[::1]', 'localhost'], true)
            || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1) {
            return;
        }

        throw new CredentialsProviderError("URL not accepted. It must either be HTTPS or match one of the following:\n"
            . "  - loopback CIDR 127.0.0.0/8 or [::1/128]\n"
            . "  - ECS container host 169.254.170.2\n"
            . '  - EKS container host 169.254.170.23 or [fd00:ec2::23]');
    }

    private static function validateContainerToken(string $token): string
    {
        if (str_contains($token, "\r\n")) {
            throw new CredentialsProviderError('Authorization token contains invalid \\r\\n sequence.');
        }

        return $token;
    }

    /** `@smithy/credential-provider-imds`' `fromContainerMetadata()`. */
    private function fromContainerMetadata(): Credentials
    {
        $relative = SharedConfig::env('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        $full = SharedConfig::env('AWS_CONTAINER_CREDENTIALS_FULL_URI');

        if ($relative !== null) {
            $url = self::ECS_HOST . $relative;
        } elseif ($full !== null) {
            $parts = parse_url($full);

            if (!is_array($parts) || !isset($parts['host'])) {
                throw new CredentialsProviderError("{$full} is not a valid container metadata service URL", false);
            }

            if (!in_array($parts['host'], ['localhost', '127.0.0.1'], true)) {
                throw new CredentialsProviderError("{$parts['host']} is not a valid container metadata service hostname", false);
            }

            if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                throw new CredentialsProviderError(($parts['scheme'] ?? '') . ': is not a valid container metadata service protocol', false);
            }

            $url = $full;
        } else {
            throw new CredentialsProviderError('The container metadata credential provider cannot be used unless the AWS_CONTAINER_CREDENTIALS_RELATIVE_URI or AWS_CONTAINER_CREDENTIALS_FULL_URI environment variable is set', false);
        }

        $token = SharedConfig::env('AWS_CONTAINER_AUTHORIZATION_TOKEN');

        return self::imdsCredentials($this->metadataRequest(new Request('GET', $url, $token !== null ? ['Authorization' => $token] : [])));
    }

    /** `fromInstanceMetadata()`: IMDSv2's token first, the v1 fallback when the token is refused or times out. */
    private function fromInstanceMetadata(): Credentials
    {
        $endpoint = self::instanceMetadataEndpoint();
        $headers = [];

        try {
            $headers['x-aws-ec2-metadata-token'] = $this->metadataRequest(
                new Request('PUT', $endpoint . '/latest/api/token', ['x-aws-ec2-metadata-token-ttl-seconds' => '21600']),
            );
        } catch (CredentialsProviderError $error) {
            if ($error->statusCode === 400) {
                throw new CredentialsProviderError('EC2 Metadata token request returned error', true, $error);
            }

            $this->assertV1FallbackAllowed();
        }

        $role = trim($this->metadataRequest(new Request('GET', $endpoint . self::IMDS_PATH, $headers)));

        return self::imdsCredentials($this->metadataRequest(new Request('GET', $endpoint . self::IMDS_PATH . $role, $headers)));
    }

    /** The `AWS_EC2_METADATA_V1_DISABLED` / `ec2_metadata_v1_disabled` refusal of the v1 fallback. */
    private function assertV1FallbackAllowed(): void
    {
        $fromEnv = SharedConfig::env('AWS_EC2_METADATA_V1_DISABLED');
        $fromProfile = SharedConfig::profiles()[SharedConfig::profileName($this->profile)]['ec2_metadata_v1_disabled'] ?? null;
        $causes = [];

        if ($fromEnv !== null) {
            if ($fromEnv !== 'false') {
                $causes[] = 'process environment variable (AWS_EC2_METADATA_V1_DISABLED)';
            }
        } elseif ($fromProfile !== null && $fromProfile !== '' && $fromProfile !== 'false') {
            $causes[] = 'config file profile (ec2_metadata_v1_disabled)';
        }

        if ($causes !== []) {
            throw new RuntimeException('AWS EC2 Metadata v1 fallback has been blocked by AWS SDK configuration in the following: [' . implode(', ', $causes) . '].');
        }
    }

    /** `getInstanceMetadataEndpoint()`: the configured endpoint, the IPv6 one, or `169.254.169.254`. */
    private static function instanceMetadataEndpoint(): string
    {
        $endpoint = SharedConfig::env('AWS_EC2_METADATA_SERVICE_ENDPOINT');

        if ($endpoint !== null) {
            return rtrim($endpoint, '/');
        }

        return SharedConfig::env('AWS_EC2_METADATA_SERVICE_ENDPOINT_MODE') === 'IPv6' ? 'http://[fd00:ec2::254]' : 'http://169.254.169.254';
    }

    /** `isImdsCredentials()` and `fromImdsCredentials()`. */
    private static function imdsCredentials(string $body): Credentials
    {
        $data = json_decode($body, true);

        if (!is_array($data) || !is_string($data['AccessKeyId'] ?? null) || !is_string($data['SecretAccessKey'] ?? null)
            || !is_string($data['Token'] ?? null) || !is_string($data['Expiration'] ?? null)) {
            throw new CredentialsProviderError('Invalid response received from instance metadata service.');
        }

        return new Credentials(
            $data['AccessKeyId'],
            $data['SecretAccessKey'],
            $data['Token'],
            self::parseDate($data['Expiration']),
            is_string($data['AccountId'] ?? null) ? $data['AccountId'] : null,
        );
    }

    /** `@smithy/credential-provider-imds`' `httpRequest()`: the body of a 2xx, its two errors otherwise. */
    private function metadataRequest(Request $request): string
    {
        try {
            [$status, , $body] = $this->send($request, self::METADATA_TIMEOUT);
        } catch (Throwable $error) {
            throw new CredentialsProviderError('Unable to connect to instance metadata service', true, $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new CredentialsProviderError('Error response received from instance metadata service', true, null, $status);
        }

        return $body;
    }

    /**
     * STS `AssumeRole`, signed with the source credentials — `getDefaultRoleAssumer()`.
     *
     * @param array<string, string> $params
     */
    private function assumeRole(Credentials $source, array $params, ?string $region): Credentials
    {
        $credentials = $this->sts('AssumeRole', $params, $source, $region);

        return $credentials->withFeature('CREDENTIALS_STS_ASSUME_ROLE', 'i');
    }

    /**
     * STS `AssumeRoleWithWebIdentity`, unsigned — `getDefaultRoleAssumerWithWebIdentity()`.
     *
     * @param array<string, string> $params
     */
    private function assumeRoleWithWebIdentity(array $params, ?string $region): Credentials
    {
        $credentials = $this->sts('AssumeRoleWithWebIdentity', $params, null, $region);

        return ($credentials->accountId !== null ? $credentials->withFeature('RESOLVED_ACCOUNT_ID', 'T') : $credentials)
            ->withFeature('CREDENTIALS_STS_ASSUME_ROLE_WEB_ID', 'k');
    }

    /**
     * One STS call in the awsQuery protocol: a form body, an XML answer. The region is the caller's,
     * else STS's default (`AWS_REGION`, the profile's `region`, `us-east-1`).
     *
     * @param array<string, string> $params
     */
    private function sts(string $action, array $params, ?Credentials $sign, ?string $region): Credentials
    {
        $region ??= SharedConfig::env('AWS_REGION')
            ?? (SharedConfig::profiles()[SharedConfig::profileName($this->profile)]['region'] ?? null)
            ?? 'us-east-1';
        $endpoint = rtrim(Endpoints::configured('STS') ?? "https://sts.{$region}." . Endpoints::partition($region)['dnsSuffix'], '/');
        $parts = parse_url($endpoint);
        $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = ($parts['path'] ?? '') !== '' ? $parts['path'] . '/' : '/';
        $body = http_build_query(['Action' => $action, 'Version' => '2011-06-15', ...$params], '', '&', PHP_QUERY_RFC3986);
        $url = ($parts['scheme'] ?? 'https') . "://{$host}{$path}";

        [, , $xml] = $this->call(
            'sts',
            BedrockRuntimeClient::credentialFeatures($sign),
            $url,
            function (array $sdk) use ($url, $path, $body, $sign, $region): Request {
                $headers = ['content-type' => 'application/x-www-form-urlencoded', 'content-length' => (string) strlen($body), ...$sdk];

                if ($sign !== null) {
                    $headers = SignatureV4::sign('POST', $path, [], $headers, $body, $sign, $region, 'sts', $this->nowMs());
                }

                return new Request('POST', $url, $headers, $body);
            },
            // The awsQuery error: `<ErrorResponse><Error><Code/><Message/></Error><RequestId/>`, thrown
            // as STS's own service exception — not Bedrock's, so it reaches the turn unprefixed.
            static fn (int $status, array $headers, string $xml): ServiceError => new ServiceError(
                self::xmlValue($xml, 'Message') ?? 'UnknownError',
                self::xmlValue($xml, 'Code') ?? 'Unknown',
                $status,
                RestJson::requestId($headers),
                false,
                $headers,
            ),
        );

        $accessKeyId = self::xmlValue($xml, 'AccessKeyId');
        $secretAccessKey = self::xmlValue($xml, 'SecretAccessKey');

        if ($accessKeyId === null || $secretAccessKey === null) {
            throw new RuntimeException('Invalid response from STS.' . lcfirst($action) . " call with role {$params['RoleArn']}");
        }

        $arn = self::xmlValue($xml, 'Arn');
        $arnParts = $arn !== null ? explode(':', $arn) : [];
        $expiration = self::xmlValue($xml, 'Expiration');

        return new Credentials(
            $accessKeyId,
            $secretAccessKey,
            self::xmlValue($xml, 'SessionToken'),
            $expiration !== null ? self::parseDate($expiration) : null,
            count($arnParts) > 4 && $arnParts[4] !== '' ? $arnParts[4] : null,
        );
    }

    /** The text of the first `<name>` element, entities decoded. */
    private static function xmlValue(string $xml, string $name): ?string
    {
        if (preg_match('#<' . $name . '>(.*?)</' . $name . '>#s', $xml, $matches) !== 1) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * One call of an STS, SSO or SSO-OIDC client, as `@aws-sdk/nested-clients` makes it: the SDK's
     * headers on every attempt — `x-amz-user-agent`, `user-agent` (with `m/E` and the features of the
     * credentials that sign it), `host`, `amz-sdk-invocation-id` and `amz-sdk-request` — and the
     * standard retry strategy over the attempts, as the Bedrock client's.
     *
     * @param array<string, string> $features
     * @param Closure(array<string, string>): Request $build the request, given the SDK's headers
     * @param Closure(int, array<string, string>, string): ServiceError $error the service's error, from
     *        a status, headers and body that are not a success
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function call(string $api, array $features, string $url, Closure $build, Closure $error): array
    {
        $parts = parse_url($url);
        $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $userAgent = BedrockRuntimeClient::userAgent(['RETRY_MODE_STANDARD' => 'E', ...$features], $api, self::NESTED_CLIENTS_VERSION);
        $invocationId = BedrockRuntimeClient::uuid();

        for ($attempt = 1, $retryCount = 0; ; $attempt++) {
            $request = $build([
                'x-amz-user-agent' => 'aws-sdk-js/' . self::NESTED_CLIENTS_VERSION,
                'user-agent' => $userAgent,
                'host' => $host,
                'amz-sdk-invocation-id' => $invocationId,
                'amz-sdk-request' => "attempt={$attempt}; max={$this->maxAttempts}",
            ]);

            try {
                try {
                    [$status, $headers, $body] = $this->send($request, null);
                } catch (AbortError $abort) {
                    throw $abort;
                } catch (Throwable $transport) {
                    throw new TransportError(BedrockRuntimeClient::transportMessage($transport, $request), $transport);
                }

                if ($status < 200 || $status >= 300) {
                    throw $error($status, $headers, $body);
                }

                return [$status, $headers, $body];
            } catch (Throwable $failure) {
                if ($this->signal?->aborted() ?? false) {
                    throw new RequestAbortedError('Request aborted', previous: $failure);
                }

                $type = BedrockRuntimeClient::retryErrorType($failure);

                if (($type !== 'THROTTLING' && $type !== 'TRANSIENT') || $attempt >= $this->maxAttempts) {
                    throw $failure;
                }

                BedrockRuntimeClient::backOff(BedrockRuntimeClient::standardRetryDelay($type, $retryCount, $failure, $this->nowMs()), $this->signal, $this->sleep);
                $retryCount++;
            }
        }
    }

    /**
     * One request, the body read whole.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function send(Request $request, ?float $timeout): array
    {
        $http = $timeout !== null ? new HttpClient($timeout) : $this->http;
        $response = $http->send($request, $this->signal);

        return [$response->status, $response->headers, $response->body->all()];
    }

    /** An RFC 3339 / ISO 8601 date, as milliseconds — `new Date(string).getTime()`. */
    private static function parseDate(string $value): int
    {
        $parsed = date_create_immutable($value);

        if ($parsed === false) {
            throw new RuntimeException("Invalid date: {$value}");
        }

        return (int) $parsed->format('Uv');
    }

    private function nowMs(): int
    {
        return $this->now !== null ? ($this->now)() : (int) floor(microtime(true) * 1000);
    }
}
