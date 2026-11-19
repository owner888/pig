<?php

declare(strict_types=1);

namespace Accounts;

final class Profile
{
    public function __construct(private string $email)
    {
    }

    public function email(): string
    {
        return $this->email;
    }

    /** @throws \InvalidArgumentException when $email is not an address */
    public function changeEmail(string $email): void
    {
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            throw new \InvalidArgumentException("not an email address: {$email}");
        }
        $this->email = $email;
    }
}
