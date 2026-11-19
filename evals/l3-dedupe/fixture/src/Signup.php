<?php

declare(strict_types=1);

namespace Accounts;

final class Signup
{
    /** @return list<string> what is wrong with the form; empty when nothing is */
    public static function errors(string $email, string $password): array
    {
        $errors = [];
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($email))) {
            $errors[] = 'email';
        }
        if (strlen($password) < 8) {
            $errors[] = 'password';
        }

        return $errors;
    }
}
