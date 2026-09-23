<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use RuntimeException;

/** A sign-in that did not finish, or a token that could not be renewed. */
final class OauthError extends RuntimeException
{
}
