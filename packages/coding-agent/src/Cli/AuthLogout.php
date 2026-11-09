<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\CodingAgent\Auth;

/**
 * `pig auth logout <provider>` — upstream's `runAuthLogout()` (`cli/auth-logout.ts`): remove what
 * `pig auth login` or `/login` stored, as `/logout` does. Environment variables and `models.json`
 * stay. Nothing stored is not an error, so it is safe to repeat.
 */
final readonly class AuthLogout
{
    public const string USAGE = 'pig auth logout <provider>';

    /**
     * @param Closure(string): void $write
     * @param Closure(string): void $error
     */
    public function __construct(
        private Auth $auth,
        private Closure $write,
        private Closure $error,
    ) {
    }

    /**
     * @param list<string> $args what follows `pig auth logout`
     * @return int the exit code
     */
    public function run(array $args): int
    {
        $ref = $args[0] ?? null;

        if ($ref === null || str_starts_with($ref, '-') || count($args) > 1) {
            ($this->error)('Usage: ' . self::USAGE);

            return 1;
        }

        $provider = AuthLogin::find($ref);
        // A provider an extension registers is not known here, so a stored credential of its id
        // matches by the id alone — as upstream's.
        $id = $provider['id'] ?? $ref;
        $stored = $this->auth->entry($id) !== null ? $id : null;

        if ($provider === null && $stored === null) {
            $ids = $this->auth->providers();
            sort($ids);
            ($this->error)('Unknown provider "' . AuthLogin::printable($ref) . '".');
            ($this->error)('Stored credentials: ' . ($ids === [] ? 'none' : implode(', ', $ids)));

            return 1;
        }

        $name = $provider['name'] ?? $ref;

        if ($stored === null) {
            ($this->write)("No stored credentials for {$name}. Environment variables and models.json config are unchanged.");

            return 0;
        }

        $kind = $this->auth->kind($stored);
        $this->auth->remove($stored);

        ($this->write)($kind === 'oauth'
            ? "Logged out of {$name}."
            : "Removed stored API key for {$name}. Environment variables and models.json config are unchanged.");

        return 0;
    }
}
