<?php

declare(strict_types=1);

namespace Accounts;

final class Invite
{
    /**
     * The addresses from a comma-separated list that an invitation can go to.
     *
     * @return list<string>
     */
    public static function recipients(string $list): array
    {
        $valid = [];
        foreach (explode(',', $list) as $email) {
            $email = trim($email);
            if (preg_match('/^[^\s@]+@[^\s@]+$/', $email)) {
                $valid[] = $email;
            }
        }

        return $valid;
    }
}
