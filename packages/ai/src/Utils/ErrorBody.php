<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `utils/error-body.ts`: how a refused request becomes the turn's error message.
 *
 * Upstream reads the HTTP status and the body off whatever error object the provider's SDK threw
 * (`normalizeProviderError()`) and composes the two (`formatProviderError()`). pig has no SDK, so
 * the one shape it needs — the `openai` SDK's `APIError`, which both OpenAI providers raise for a
 * non-2xx answer — is built here from the status and the raw body, the way openai-node 7.19.0
 * (the version pi pins) builds it: `safeJSON(errText)`, `error = errJSON?.error`, and
 * `APIError.makeMessage(status, error, errJSON ? undefined : errText)` as the message.
 *
 * What comes out is therefore what upstream prints for the same response:
 *
 * - a JSON body with an `error` object: `<status>: {"message":…,"type":…}` (completions) or
 *   `OpenAI API error (<status>): {"message":…}` (Responses, which passes a prefix) — the object
 *   as JSON, because the SDK's message (`400 <message>`) does not contain it;
 * - anything else: the SDK's message, `<status> <text>` or `<status> status code (no body)`, behind
 *   the prefix when there is one.
 *
 * Lengths are counted in code points where JavaScript counts UTF-16 units, so a body of
 * characters outside the BMP is cut a little later than upstream cuts it.
 */
final class ErrorBody
{
    /** Upstream's `MAX_PROVIDER_ERROR_BODY_CHARS`. */
    public const int MAX_PROVIDER_ERROR_BODY_CHARS = 4000;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * `normalizeProviderError(APIError.generate(status, safeJSON(errText), errMessage, headers))`.
     *
     * @return array{status: int|null, body: string|null, message: string, messageCarriesBody: bool, error: mixed}
     *         `error` is the SDK's `APIError.error` (`errJSON.error`, objects kept as objects), for a
     *         caller that reads more of it — completions appends `error.metadata.raw`
     */
    public static function openAiApiError(int $status, string $errText): array
    {
        $errJson = self::safeJson($errText);
        // `errJSON ? undefined : errText`: a body that parsed to a falsy value is used as text.
        $errMessage = self::truthy($errJson) ? null : $errText;
        $error = $errJson instanceof \stdClass ? ($errJson->error ?? null) : null;
        $message = self::makeMessage($status, $error, $errMessage);

        // `pickBodyText()`: an APIError has no `body` string, so `error.error` — a plain, non-empty
        // object — is the body, as JSON.
        $body = $error instanceof \stdClass && get_object_vars($error) !== []
            ? self::truncateErrorText(trim(self::safeJsonStringify($error)), self::MAX_PROVIDER_ERROR_BODY_CHARS)
            : null;

        if ($body === '') {
            $body = null;
        }

        return [
            'status' => $status,
            'body' => $body,
            'message' => $message,
            'messageCarriesBody' => $body === null || str_contains($message, $body),
            'error' => $error,
        ];
    }

    /**
     * Upstream's `formatProviderError(norm, prefix?)`.
     *
     * @param array{status: int|null, body: string|null, message: string, messageCarriesBody: bool} $norm
     */
    public static function format(array $norm, ?string $prefix = null): string
    {
        if ($norm['messageCarriesBody'] || $norm['status'] === null || $norm['body'] === null) {
            return $prefix !== null && $norm['status'] !== null
                ? "{$prefix} ({$norm['status']}): {$norm['message']}"
                : $norm['message'];
        }

        return $prefix !== null
            ? "{$prefix} ({$norm['status']}): {$norm['body']}"
            : "{$norm['status']}: {$norm['body']}";
    }

    /** Upstream's `truncateErrorText()`. */
    public static function truncateErrorText(string $text, int $maxChars): string
    {
        $length = mb_strlen($text);

        if ($length <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '... [truncated ' . ($length - $maxChars) . ' chars]';
    }

    /** `JSON.stringify` of a value decoded with objects kept as objects, so `{}` stays `{}`. */
    public static function safeJsonStringify(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? '' : $json;
    }

    /**
     * openai-node's `APIError.makeMessage(status, error, message)`.
     */
    private static function makeMessage(int $status, mixed $error, ?string $message): string
    {
        $inner = $error instanceof \stdClass ? ($error->message ?? null) : null;

        if (self::truthy($inner)) {
            $msg = is_string($inner) ? $inner : self::safeJsonStringify($inner);
        } elseif (self::truthy($error)) {
            $msg = self::safeJsonStringify($error);
        } else {
            $msg = $message;
        }

        if ($status !== 0 && $msg !== null && $msg !== '') {
            return "{$status} {$msg}";
        }

        if ($status !== 0) {
            return "{$status} status code (no body)";
        }

        return $msg !== null && $msg !== '' ? $msg : '(no status code or body)';
    }

    /** openai-node's `safeJSON()`: the parsed value, or undefined (null here) when it is not JSON. */
    private static function safeJson(string $text): mixed
    {
        // Not decoding is the answer here rather than a failure, so the error is read and not caught.
        $value = json_decode($text, false);

        return json_last_error() === JSON_ERROR_NONE ? $value : null;
    }

    /** JavaScript truthiness for a decoded JSON value: an object or array is truthy even when empty. */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }
}
