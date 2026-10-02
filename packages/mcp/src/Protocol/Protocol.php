<?php

declare(strict_types=1);

namespace Pig\Mcp\Protocol;

/** The protocol versions — upstream's `protocol/types.ts` constants. */
final class Protocol
{
    public const string LATEST_VERSION = '2025-11-25';

    /**
     * Versions the client accepts from a server. A server that does not support the requested
     * version answers with its own latest one, so older versions stay accepted for servers built
     * on older SDKs.
     */
    public const array SUPPORTED_VERSIONS = [self::LATEST_VERSION, '2025-06-18', '2025-03-26', '2024-11-05'];
}
