<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Accounts;

/**
 * Somebody who logs in to `pig web`. With accounts (a database), each has an id, a name, whether
 * they are an admin, and where their tools run. With the one password in a file there is one,
 * unnamed, the admin, on this machine.
 */
final readonly class User
{
    /** Tools on this machine, as the server's OS user. */
    public const string HOST = 'host';

    /** Tools in a container of the account's own (docs/dev/design/storage.md, phase 4 step 3). */
    public const string DOCKER = 'docker';

    public const array RUNTIMES = [self::HOST, self::DOCKER];

    public function __construct(
        public string $id,
        public string $name,
        public bool $admin,
        public string $runtime = self::HOST,
    ) {
    }

    /** What the page is told about an account. @return array{name: string, admin: bool, runtime: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'admin' => $this->admin, 'runtime' => $this->runtime];
    }
}
