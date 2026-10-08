<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use Pig\Ai\Utils\JsJson;

/**
 * The awsRestJson1 protocol's error deserialization, as the AWS SDK for JavaScript does it for a
 * response that is not 2xx (`loadRestJsonErrorCode()` and the base exception it falls back on):
 *
 * - the code is the `x-amzn-errortype` header, else the body's `code`, else its `__type`, each cut by
 *   `sanitizeErrorCode()` — before a `,`, before a `:`, after a `#` — and `Unknown` when none says;
 * - the message is the body's `message`, else `Message`, else `UnknownError`;
 * - an empty body is `{}`; a body that is not JSON is the parse failure itself, with the SDK's
 *   "Deserialization error" hint after it, and not a service error at all.
 *
 * @internal
 */
final class RestJson
{
    public const string DESERIALIZATION_HINT = "\n  Deserialization error: to see the raw response, inspect the hidden field {error}.\$response on this object.";

    /**
     * @param array<string, string> $headers lowercased
     */
    public static function error(int $status, array $headers, string $body, bool $bedrock): ServiceError
    {
        $requestId = self::requestId($headers);
        $text = JsJson::decodeUtf8($body);

        if (trim($text) === '') {
            $data = new \stdClass();
        } else {
            try {
                $data = JsJson::parse($text, false);
            } catch (\JsonException $error) {
                // The SDK's deserializer middleware: the `SyntaxError` with its hint, a plain error.
                return new ServiceError($error->getMessage() . self::DESERIALIZATION_HINT, 'SyntaxError', $status, $requestId, false, $headers, $error);
            }
        }

        $code = $headers['x-amzn-errortype'] ?? null;

        if ($code === null && $data instanceof \stdClass) {
            $code = self::stringOf($data->code ?? null) ?? self::stringOf($data->__type ?? null);
        }

        $message = $data instanceof \stdClass
            ? (self::stringOf($data->message ?? null) ?? self::stringOf($data->Message ?? null) ?? 'UnknownError')
            : 'UnknownError';

        return new ServiceError($message, $code !== null ? self::sanitizeErrorCode($code) : 'Unknown', $status, $requestId, $bedrock, $headers);
    }

    /** `$metadata.requestId`: `x-amzn-requestid`, `x-amzn-request-id` or `x-amz-request-id`. */
    public static function requestId(array $headers): ?string
    {
        return $headers['x-amzn-requestid'] ?? $headers['x-amzn-request-id'] ?? $headers['x-amz-request-id'] ?? null;
    }

    /** smithy's `sanitizeErrorCode()`. */
    public static function sanitizeErrorCode(string $raw): string
    {
        $clean = $raw;

        if (str_contains($clean, ',')) {
            $clean = explode(',', $clean)[0];
        }

        if (str_contains($clean, ':')) {
            $clean = explode(':', $clean)[0];
        }

        if (str_contains($clean, '#')) {
            $clean = explode('#', $clean)[1];
        }

        return $clean;
    }

    private static function stringOf(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }
}
