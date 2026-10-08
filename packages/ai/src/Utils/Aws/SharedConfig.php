<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;

/**
 * `~/.aws/config` and `~/.aws/credentials` as the AWS SDK for JavaScript reads them —
 * `@smithy/core/config`'s `shared-ini-file-loader` and `node-config-provider`.
 *
 * - **Where**: `AWS_CONFIG_FILE` / `AWS_SHARED_CREDENTIALS_FILE`, else `<home>/.aws/config` and
 *   `<home>/.aws/credentials`, home being `HOME`, `USERPROFILE`, `HOMEDRIVE` + `HOMEPATH`, then the
 *   account's own (`getHomeDir()`); a leading `~/` is the home directory too. A file that cannot be
 *   read is an empty one (`swallowError`).
 * - **How**: `parseIni()` — `[section]` lines, `key = value`, comments from a `#` or `;` that starts
 *   the line or follows white space, and a key with an empty value opening a sub-section whose
 *   indented keys are stored as `parent.key`.
 * - **Which sections**: the credentials file's sections are profiles by name; the config file's are
 *   profiles only as `[profile name]` (and `[default]`), with `[sso-session name]` and
 *   `[services name]` kept under those prefixes (`getConfigData()`).
 * - **Merged**: `parseKnownFiles()` lays the credentials file's keys over the config file's, profile
 *   by profile.
 *
 * Everything here reads the **process** environment, as the SDK does: a provider-scoped `env` is
 * upstream's own business and reaches the SDK only through the options upstream passes it.
 */
final class SharedConfig
{
    /** smithy's `IniSectionType`. */
    private const array SECTION_TYPES = ['profile', 'sso-session', 'services'];

    /** smithy's `getProfileName()`: the asked-for profile, `AWS_PROFILE`, `default`. */
    public static function profileName(?string $profile): string
    {
        if ($profile !== null && $profile !== '') {
            return $profile;
        }

        $env = self::env('AWS_PROFILE');

        return $env ?? 'default';
    }

    /** smithy's `getHomeDir()`. */
    public static function homeDir(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $name) {
            $value = self::env($name);

            if ($value !== null) {
                return $value;
            }
        }

        $homePath = self::env('HOMEPATH');

        if ($homePath !== null) {
            return (self::env('HOMEDRIVE') ?? 'C:' . DIRECTORY_SEPARATOR) . $homePath;
        }

        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;

        if (is_array($user) && is_string($user['dir'] ?? null)) {
            return $user['dir'];
        }

        throw new RuntimeException('Cannot work out the home directory: HOME, USERPROFILE and HOMEPATH are all unset');
    }

    public static function configFilepath(): string
    {
        return self::env('AWS_CONFIG_FILE') ?? self::homeDir() . '/.aws/config';
    }

    public static function credentialsFilepath(): string
    {
        return self::env('AWS_SHARED_CREDENTIALS_FILE') ?? self::homeDir() . '/.aws/credentials';
    }

    /**
     * smithy's `loadSharedConfigFiles()`.
     *
     * @return array{configFile: array<string, array<string, string>>, credentialsFile: array<string, array<string, string>>}
     */
    public static function load(): array
    {
        $config = self::read(self::configFilepath());
        $credentials = self::read(self::credentialsFilepath());

        return [
            'configFile' => $config === null ? [] : self::configData(self::parseIni($config)),
            'credentialsFile' => $credentials === null ? [] : self::parseIni($credentials),
        ];
    }

    /**
     * smithy's `parseKnownFiles()`: every profile, the credentials file's keys over the config file's.
     *
     * @return array<string, array<string, string>>
     */
    public static function profiles(): array
    {
        $files = self::load();
        $merged = $files['configFile'];

        foreach ($files['credentialsFile'] as $name => $values) {
            $merged[$name] = isset($merged[$name]) ? [...$merged[$name], ...$values] : $values;
        }

        return $merged;
    }

    /**
     * smithy's `loadSsoSessionData()`: the config file's `[sso-session name]` sections, by name.
     *
     * @return array<string, array<string, string>>
     */
    public static function ssoSessions(): array
    {
        $sessions = [];

        foreach (self::load()['configFile'] as $key => $values) {
            if (str_starts_with($key, 'sso-session.')) {
                $sessions[substr($key, strlen('sso-session.'))] = $values;
            }
        }

        return $sessions;
    }

    /**
     * smithy's `loadConfig()` for Node: the environment, then the shared files' profile, then the
     * default — what the client's config resolution does for region, retry mode and the endpoint
     * variants.
     *
     * @param \Closure(): mixed $fromEnv null when the environment does not say
     * @param \Closure(array<string, string>): mixed $fromProfile null when the profile does not say
     * @param \Closure(): mixed $default
     * @param 'config'|'credentials' $preferredFile whose keys win when both files have the profile
     */
    public static function loadConfig(\Closure $fromEnv, \Closure $fromProfile, \Closure $default, ?string $profile, string $preferredFile = 'config'): mixed
    {
        $value = $fromEnv();

        if ($value !== null) {
            return $value;
        }

        $files = self::load();
        $name = self::profileName($profile);
        $fromCredentials = $files['credentialsFile'][$name] ?? [];
        $fromConfig = $files['configFile'][$name] ?? [];
        $merged = $preferredFile === 'config' ? [...$fromCredentials, ...$fromConfig] : [...$fromConfig, ...$fromCredentials];
        $value = $fromProfile($merged);

        return $value ?? $default();
    }

    /**
     * smithy's `booleanSelector()`: `"true"`, `"false"`, or a refusal naming what was there.
     *
     * @param array<string, string> $values
     */
    public static function booleanSelector(array $values, string $key, string $type): ?bool
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }

        return match ($values[$key]) {
            'true' => true,
            'false' => false,
            default => throw new RuntimeException("Cannot load {$type} \"{$key}\". Expected \"true\" or \"false\", got {$values[$key]}."),
        };
    }

    /** A process environment variable, empty counting as set — `process.env[name]` and its `||`s are the caller's. */
    public static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * smithy's `parseIni()`.
     *
     * @return array<string, array<string, string>>
     */
    public static function parseIni(string $data): array
    {
        $map = [];
        $section = null;
        $subSection = null;

        foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
            $trimmed = trim(preg_split('/(^|\s)[;#]/', $line)[0] ?? '');
            $isSection = $trimmed !== '' && $trimmed[0] === '[' && $trimmed[strlen($trimmed) - 1] === ']';

            if ($isSection) {
                $section = null;
                $subSection = null;
                $name = substr($trimmed, 1, -1);

                // smithy's `/^([\w-]+)\s(["'])?([\w-@+.%:/]+)\2$/`. The quote group is written to
                // always take part — JavaScript lets `\2` match nothing when `(["'])?` did not, PCRE
                // fails it instead, which would turn `[profile work]` into a section named that.
                if (preg_match('/^([\w-]+)\s(["\']?)([\w\-@+.%:\/]+)\2$/', $name, $matches) === 1) {
                    if (in_array($matches[1], self::SECTION_TYPES, true)) {
                        $section = $matches[1] . '.' . $matches[3];
                    }
                } else {
                    $section = $name;
                }

                if ($name === '__proto__' || $name === 'profile __proto__') {
                    throw new RuntimeException("Found invalid profile name \"{$name}\"");
                }

                continue;
            }

            if ($section === null) {
                continue;
            }

            $equals = strpos($trimmed, '=');

            if ($equals === false || $equals === 0) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $equals));
            $value = trim(substr($trimmed, $equals + 1));

            if ($value === '') {
                $subSection = $key;

                continue;
            }

            if ($subSection !== null && ltrim($line) === $line) {
                $subSection = null;
            }

            $map[$section][$subSection !== null ? "{$subSection}.{$key}" : $key] = $value;
        }

        return $map;
    }

    /**
     * smithy's `getConfigData()`.
     *
     * @param array<string, array<string, string>> $data
     * @return array<string, array<string, string>>
     */
    private static function configData(array $data): array
    {
        $out = isset($data['default']) ? ['default' => $data['default']] : [];

        foreach ($data as $key => $values) {
            $dot = strpos((string) $key, '.');

            if ($dot === false || !in_array(substr((string) $key, 0, $dot), self::SECTION_TYPES, true)) {
                continue;
            }

            $prefix = substr((string) $key, 0, $dot);
            $out[$prefix === 'profile' ? substr((string) $key, $dot + 1) : (string) $key] = $values;
        }

        return $out;
    }

    private static function read(string $path): ?string
    {
        if (str_starts_with($path, '~/')) {
            $path = self::homeDir() . '/' . substr($path, 2);
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
