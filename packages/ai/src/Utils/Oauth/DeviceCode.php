<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

/**
 * What a device flow hands back before anybody has approved anything.
 *
 * Two of these fields are for the person and two are for the program: `verificationUri` and
 * `userCode` go on screen, `deviceCode` is what the polling asks about, and `interval` is how
 * often the server is willing to be asked. `expiresIn` is when to stop.
 */
final readonly class DeviceCode
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public int $interval,
        public int $expiresIn,
    ) {
    }
}
