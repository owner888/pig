<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\ProviderError;

/**
 * Upstream's `utils/error-body.ts`: how a refused request becomes the turn's error message.
 *
 * Upstream reads the HTTP status and the body off whatever error object the provider's SDK threw
 * (`normalizeProviderError()`) and composes the two (`formatProviderError()`). pig has no SDK, so
 * the error each SDK would have thrown for a non-2xx answer is built here from the status and the
 * raw body, the way the version pi pins builds it:
 *
 * - **`openai` 7.19.0** (both OpenAI providers): `safeJSON(errText)`, `error = errJSON?.error`, and
 *   `APIError.makeMessage(status, error, errJSON ? undefined : errText)` as the message. Composed,
 *   a JSON body with an `error` object reads `<status>: {"message":…,"type":…}` (completions) or
 *   `OpenAI API error (<status>): {"message":…}` (Responses, which passes a prefix) — the object as
 *   JSON, because the SDK's message (`400 <message>`) does not contain it; anything else is the
 *   SDK's message, `<status> <text>` or `<status> status code (no body)`, behind the prefix.
 * - **`@anthropic-ai/sdk` 0.129.0** (`anthropicApiError()`): the same `makeMessage()`, but handed
 *   the *whole* parsed body, so Anthropic's `{"type":"error","error":{…}}` — which has no top-level
 *   `message` — reads `<status> {"type":"error","error":{…},"request_id":…}`. Upstream's catch prints
 *   `error.message` as it is.
 * - **`@google/genai` 2.21.0** (`genaiApiError()`): `throwErrorIfNotOK()` — the body parsed when the
 *   response says `application/json` (V8's `SyntaxError` text when it is not), otherwise
 *   `{error: {message: <text>, code: <status>, status: <statusText>}}`, and `JSON.stringify` of that
 *   as the message, which `formatProviderError()` leaves alone (no `body` field to add).
 *
 * Bodies are read as `response.text()` reads them: not-UTF-8 bytes as U+FFFD, a leading BOM gone.
 *
 * Lengths are counted in code points where JavaScript counts UTF-16 units, so a body of
 * characters outside the BMP is cut a little later than upstream cuts it.
 */
final class ErrorBody
{
    /** Upstream's `MAX_PROVIDER_ERROR_BODY_CHARS`. */
    public const int MAX_PROVIDER_ERROR_BODY_CHARS = 4000;

    /**
     * `normalizeProviderError(APIError.generate(status, safeJSON(errText), errMessage, headers))`.
     *
     * @return array{status: int|null, body: string|null, message: string, messageCarriesBody: bool, error: mixed}
     *         `error` is the SDK's `APIError.error` (`errJSON.error`, objects kept as objects), for a
     *         caller that reads more of it — completions appends `error.metadata.raw`
     */
    public static function openAiApiError(int $status, string $errText): array
    {
        $errText = self::responseText($errText);
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
     * Upstream's `normalizeProviderError(error)` for an error pig built itself: the status and the
     * `body` string a `ProviderHttpError` carries (trimmed, cut at 4,000, none when empty), the
     * message, and whether the message already contains the body. Anything else is its message
     * alone, with no status — the shape a network failure or a parse error has upstream.
     *
     * @return array{status: int|null, body: string|null, message: string, messageCarriesBody: bool}
     */
    public static function normalizeProviderError(\Throwable $error): array
    {
        $status = $error instanceof ProviderHttpError ? $error->status : null;
        $body = $error instanceof ProviderHttpError && $error->body !== null ? JsJson::trim($error->body) : null;
        $body = $body === null || $body === '' ? null : self::truncateErrorText($body, self::MAX_PROVIDER_ERROR_BODY_CHARS);
        $message = $error->getMessage();

        return [
            'status' => $status,
            'body' => $body,
            'message' => $message,
            'messageCarriesBody' => $body === null || str_contains($message, $body),
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
        return JsJson::stringify($value);
    }

    /**
     * One event as upstream's code receives it through the `openai` SDK's `Stream`, which stands
     * between the SSE and both OpenAI providers and decides three things on its own (openai-node
     * 7.19.0, `core/streaming.ts`):
     *
     * - data that is not JSON throws `Error reading response: malformed server-sent event JSON.` —
     *   the SDK's own fixed text, not V8's;
     * - an `event: error` throws an `APIError` made of `data.error ?? data`;
     * - any other event whose data has a truthy `error` throws an `APIError` made of that.
     *
     * An `APIError` with no status is `makeMessage()`'s text alone — the error's `message`, or its
     * JSON when the message is not a string, or the whole error's JSON when it has none — and pi's
     * `formatProviderError()` leaves a status-less error's message as it is. So a nested
     * `{type: "error", error: {code, message}}`, which the live API sends, reads as its message.
     *
     * The data `[DONE]` is the caller's to stop at before this (`if (sse.data === '[DONE]') break`).
     *
     * @param \Closure(string, mixed): string|null $compose what the provider's catch makes of the
     *        `APIError`'s message and its `error` — completions appends OpenRouter's `metadata.raw`
     * @return array<string, mixed>
     * @throws ProviderError
     */
    public static function openAiStreamEvent(string $sseEvent, string $raw, ?\Closure $compose = null): array
    {
        try {
            $object = JsJson::parse($raw, false);
        } catch (\JsonException) {
            throw new ProviderError('Error reading response: malformed server-sent event JSON.');
        }

        $error = null;

        if ($sseEvent === 'error') {
            // `data?.error ?? data`: nullish, not truthy — an `error` of `false` or `""` is used.
            $error = $object instanceof \stdClass && ($object->error ?? null) !== null ? $object->error : $object;
        } elseif ($object instanceof \stdClass && self::truthy($object->error ?? null)) {
            $error = $object->error;
        } else {
            $data = JsJson::parse($raw);

            return is_array($data) ? $data : [];
        }

        $message = self::makeMessage(0, $error, null);

        throw new ProviderError($compose !== null ? $compose($message, $error) : $message);
    }

    /**
     * `@anthropic-ai/sdk`'s `APIError` for a non-2xx answer, as its message: `makeStatusError(status,
     * errJSON, errMessage)` — the whole parsed body as the error, not its `error` field.
     */
    public static function anthropicApiError(int $status, string $errText): string
    {
        $errText = self::responseText($errText);
        $errJson = self::safeJson($errText);

        return self::makeMessage($status, $errJson, self::truthy($errJson) ? null : $errText);
    }

    /**
     * `@google/genai`'s `throwErrorIfNotOK()` message, which is what upstream's Google catch prints.
     *
     * @throws \JsonException V8's text, when the response says JSON and is not — `response.json()`
     *         throws a `SyntaxError` there, and that is the error upstream reports
     */
    public static function genaiApiError(int $status, string $statusText, ?string $contentType, string $body): string
    {
        $body = self::responseText($body);

        if ($contentType !== null && str_contains($contentType, 'application/json')) {
            return JsJson::stringify(JsJson::parse($body, false));
        }

        return JsJson::stringify((object) [
            'error' => (object) ['message' => $body, 'code' => $status, 'status' => $statusText],
        ]);
    }

    /**
     * The SDKs' `APIError.makeMessage(status, error, message)` — openai-node's and the Anthropic
     * SDK's are the same function. A status of 0 is JavaScript's falsy "no status".
     */
    public static function makeMessage(int $status, mixed $error, ?string $message): string
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

    /** The SDKs' `safeJSON()`: the parsed value, or undefined (null here) when it is not JSON. */
    private static function safeJson(string $text): mixed
    {
        try {
            return JsJson::parse($text, false);
        } catch (\JsonException) {
            // Not decoding is the answer here rather than a failure.
            return null;
        }
    }

    /** `response.text()`: UTF-8 with U+FFFD for what is not, and a leading byte-order mark dropped. */
    private static function responseText(string $body): string
    {
        $text = JsJson::decodeUtf8($body);

        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }

    /** JavaScript truthiness for a decoded JSON value: an object or array is truthy even when empty. */
    public static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }
}
