<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StreamOptions;

/**
 * Where an Azure OpenAI request goes, and under which deployment — upstream's
 * `api/azure-openai-config.ts`.
 *
 * "Azure models ship without a baseUrl: one resource per user, resolved per request." So the
 * catalogue's Azure rows carry `''`, and the endpoint is worked out at request time from, first to
 * last: the options' `azureBaseUrl`, `AZURE_OPENAI_BASE_URL`, the options' `azureResourceName` or
 * `AZURE_OPENAI_RESOURCE_NAME` (`https://<name>.openai.azure.com/openai/v1`), and the model's own
 * `baseUrl` (a `models.json` row's). Nothing at all is an error that names all five.
 *
 * The deployment — what goes out as the request's `model` — is the options' `azureDeploymentName`,
 * else the model's entry in `AZURE_OPENAI_DEPLOYMENT_NAME_MAP` (`gpt-5.4=prod-gpt54,o3=reasoning`),
 * else the model's id. The API version is the options' `azureApiVersion`, else
 * `AZURE_OPENAI_API_VERSION`, else `v1`.
 *
 * Upstream's `AzureEndpointOptions` are four fields any options object may carry; here they are
 * `AzureOpenAiResponsesOptions`' own, and an options object of another class (the completions arm's
 * `OpenAiOptions`) reads the environment and the model alone — which is also all upstream's
 * `streamSimple()` arms read, since `buildBaseOptions()` does not carry the four.
 */
final class AzureOpenAiConfig
{
    /** Upstream's `DEFAULT_AZURE_API_VERSION`. */
    public const string DEFAULT_AZURE_API_VERSION = 'v1';

    /**
     * Upstream's `parseDeploymentNameMap()`: `model=deployment` pairs separated by commas, each side
     * trimmed; an entry without both sides is skipped. `"a=b=c".split("=", 2)` is `["a", "b"]` —
     * the rest is dropped, not kept on the deployment.
     *
     * @return array<string, string>
     */
    private static function parseDeploymentNameMap(?string $value): array
    {
        $map = [];

        if ($value === null || $value === '') {
            return $map;
        }

        foreach (explode(',', $value) as $entry) {
            $trimmed = trim($entry);

            if ($trimmed === '') {
                continue;
            }

            $parts = explode('=', $trimmed, 3);
            $modelId = $parts[0];
            $deploymentName = $parts[1] ?? '';

            if ($modelId === '' || $deploymentName === '') {
                continue;
            }

            $map[trim($modelId)] = trim($deploymentName);
        }

        return $map;
    }

    /** Upstream's `resolveDeploymentName(model, options)`. */
    public static function resolveDeploymentName(Model $model, ?StreamOptions $options = null): string
    {
        $azure = $options instanceof AzureOpenAiResponsesOptions ? $options : null;

        if ($azure?->azureDeploymentName !== null && $azure->azureDeploymentName !== '') {
            return $azure->azureDeploymentName;
        }

        $mapped = self::parseDeploymentNameMap(
            StreamOptions::providerEnvValue('AZURE_OPENAI_DEPLOYMENT_NAME_MAP', $options?->env),
        )[$model->id] ?? '';

        return $mapped !== '' ? $mapped : $model->id;
    }

    /**
     * Upstream's `normalizeAzureBaseUrl()`: an Azure host (`*.openai.azure.com`,
     * `*.cognitiveservices.azure.com`, `*.ai.azure.com`) given as the bare resource, `/openai` or
     * the whole `/openai/v1/responses` gets `/openai/v1` and loses its query — "so the AzureOpenAI
     * SDK can append /deployments/<model>/... and ?api-version=v1 correctly". Anything else is kept
     * as written, less its trailing slashes. A value `new URL()` refuses is an error.
     */
    private static function normalizeAzureBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $url = self::parseUrl($trimmed)
            ?? throw new ProviderError("Invalid Azure OpenAI base URL: {$baseUrl}");

        $host = $url['host'];
        $isAzureHost = str_ends_with($host, '.openai.azure.com')
            || str_ends_with($host, '.cognitiveservices.azure.com')
            || str_ends_with($host, '.ai.azure.com');
        $normalizedPath = rtrim($url['path'], '/');

        if ($isAzureHost && in_array($normalizedPath, ['', '/', '/openai', '/openai/v1/responses'], true)) {
            $url['path'] = '/openai/v1';
            $url['query'] = null;
        }

        return rtrim(self::serializeUrl($url), '/');
    }

    /** Upstream's `buildDefaultBaseUrl()`. */
    private static function buildDefaultBaseUrl(string $resourceName): string
    {
        return "https://{$resourceName}.openai.azure.com/openai/v1";
    }

    /** Upstream's `resolveAzureBaseUrl(model, options)`. */
    public static function resolveAzureBaseUrl(Model $model, ?StreamOptions $options = null): string
    {
        $azure = $options instanceof AzureOpenAiResponsesOptions ? $options : null;
        // `options?.azureBaseUrl?.trim() || getProviderEnvValue("AZURE_OPENAI_BASE_URL")?.trim() || undefined`.
        $baseUrl = trim($azure?->azureBaseUrl ?? '');

        if ($baseUrl === '') {
            $baseUrl = trim(StreamOptions::providerEnvValue('AZURE_OPENAI_BASE_URL', $options?->env) ?? '');
        }

        $resourceName = $azure?->azureResourceName !== null && $azure->azureResourceName !== ''
            ? $azure->azureResourceName
            : StreamOptions::providerEnvValue('AZURE_OPENAI_RESOURCE_NAME', $options?->env);

        $resolvedBaseUrl = $baseUrl !== '' ? $baseUrl : null;

        if ($resolvedBaseUrl === null && $resourceName !== null) {
            $resolvedBaseUrl = self::buildDefaultBaseUrl($resourceName);
        }

        if ($resolvedBaseUrl === null && $model->baseUrl !== '') {
            $resolvedBaseUrl = $model->baseUrl;
        }

        if ($resolvedBaseUrl === null) {
            throw new ProviderError(
                'Azure OpenAI base URL is required. Set AZURE_OPENAI_BASE_URL or AZURE_OPENAI_RESOURCE_NAME, or pass azureBaseUrl, azureResourceName, or model.baseUrl.',
            );
        }

        return self::normalizeAzureBaseUrl($resolvedBaseUrl);
    }

    /**
     * Upstream's `resolveAzureConfig(model, options)`.
     *
     * @return array{baseUrl: string, apiVersion: string}
     */
    public static function resolveAzureConfig(Model $model, ?StreamOptions $options = null): array
    {
        $azure = $options instanceof AzureOpenAiResponsesOptions ? $options : null;
        $apiVersion = $azure?->azureApiVersion;

        if ($apiVersion === null || $apiVersion === '') {
            $apiVersion = StreamOptions::providerEnvValue('AZURE_OPENAI_API_VERSION', $options?->env) ?? self::DEFAULT_AZURE_API_VERSION;
        }

        return [
            'baseUrl' => self::resolveAzureBaseUrl($model, $options),
            'apiVersion' => $apiVersion,
        ];
    }

    /**
     * The WHATWG `new URL()` this needs: a scheme and, for the special schemes, a host — null for
     * what the constructor would refuse. Scheme and host are lowercased, as the URL parser does.
     *
     * @return array{scheme: string, user: string|null, pass: string|null, host: string, port: int|null, path: string, query: string|null, fragment: string|null}|null
     */
    private static function parseUrl(string $value): ?array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $value) !== 1) {
            return null;
        }

        $parts = parse_url($value);

        if (!is_array($parts) || !isset($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        if (in_array($scheme, ['http', 'https', 'ws', 'wss', 'ftp'], true) && $host === '') {
            return null;
        }

        return [
            'scheme' => $scheme,
            'user' => $parts['user'] ?? null,
            'pass' => $parts['pass'] ?? null,
            'host' => $host,
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? null,
            'fragment' => $parts['fragment'] ?? null,
        ];
    }

    /**
     * `url.toString()`: a special URL with no path gets `/`, and a scheme's default port is dropped.
     *
     * @param array{scheme: string, user: string|null, pass: string|null, host: string, port: int|null, path: string, query: string|null, fragment: string|null} $url
     */
    private static function serializeUrl(array $url): string
    {
        $defaultPorts = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];
        $special = isset($defaultPorts[$url['scheme']]);
        $userinfo = $url['user'] !== null ? $url['user'] . ($url['pass'] !== null ? ':' . $url['pass'] : '') . '@' : '';
        $port = $url['port'] !== null && ($defaultPorts[$url['scheme']] ?? null) !== $url['port'] ? ':' . $url['port'] : '';
        $path = $url['path'] === '' && $special ? '/' : $url['path'];
        $authority = $url['host'] !== '' || $special ? '//' . $userinfo . $url['host'] . $port : '';

        return $url['scheme'] . ':' . $authority . $path
            . ($url['query'] !== null && $url['query'] !== '' ? '?' . $url['query'] : '')
            . ($url['fragment'] !== null && $url['fragment'] !== '' ? '#' . $url['fragment'] : '');
    }
}
