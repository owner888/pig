<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

use Throwable;

/**
 * JSON-RPC 2.0 as MCP uses it — upstream's `protocol/jsonrpc.ts`.
 *
 * A message is a decoded JSON object (an associative array here) and nothing more: the shape
 * predicates say which of the three kinds it is, and `parse()` refuses anything that is none of
 * them. The error codes are the standard's five.
 */
final class JsonRpc
{
    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;

    /** A JSON object, which in PHP is an array that is not a list — `[]` counts as one. */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** A string, or a finite number. */
    public static function isId(mixed $value): bool
    {
        return is_string($value) || is_int($value) || (is_float($value) && is_finite($value));
    }

    public static function isRequest(mixed $message): bool
    {
        return self::isObject($message)
            && ($message['jsonrpc'] ?? null) === '2.0'
            && self::isId($message['id'] ?? null)
            && is_string($message['method'] ?? null);
    }

    public static function isNotification(mixed $message): bool
    {
        return self::isObject($message)
            && ($message['jsonrpc'] ?? null) === '2.0'
            && !array_key_exists('id', $message)
            && is_string($message['method'] ?? null);
    }

    public static function isResponse(mixed $message): bool
    {
        if (!self::isObject($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !self::isId($message['id'] ?? null)) {
            return false;
        }

        if (array_key_exists('result', $message)) {
            return !array_key_exists('error', $message);
        }

        if (!array_key_exists('error', $message) || !self::isObject($message['error'])) {
            return false;
        }

        return is_numeric($message['error']['code'] ?? null) && is_string($message['error']['message'] ?? null);
    }

    /**
     * @return array<string, mixed>
     * @throws McpError when the value is none of the three kinds
     */
    public static function parse(mixed $value): array
    {
        if (self::isRequest($value) || self::isNotification($value) || self::isResponse($value)) {
            return $value;
        }

        throw new McpError(self::INVALID_REQUEST, 'Invalid JSON-RPC message');
    }

    /** Upstream's `toError()`: whatever was thrown, as something with a message. */
    public static function toError(mixed $value): Throwable
    {
        return $value instanceof Throwable ? $value : new \RuntimeException((string) $value);
    }
}
