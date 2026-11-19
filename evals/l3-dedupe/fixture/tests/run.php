<?php

declare(strict_types=1);

use Accounts\Invite;
use Accounts\Profile;
use Accounts\Signup;

require __DIR__ . '/../src/autoload.php';

$failed = 0;
$check = static function (string $what, mixed $actual, mixed $expected) use (&$failed): void {
    if ($actual !== $expected) {
        $failed++;
        echo "{$what}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n";
    }
};

$good = ['dev@example.com', 'a.b+tag@mail.example.org', '  padded@example.com  '];
$bad = ['dev@localhost', 'no-at-sign.example.com', 'two@@example.com', 'space in@example.com', '@example.com', 'dev@example.'];

foreach ($good as $email) {
    $check("signup accepts '{$email}'", Signup::errors($email, 'long enough'), []);
}
foreach ($bad as $email) {
    $check("signup rejects '{$email}'", Signup::errors($email, 'long enough'), ['email']);
}
$check('signup checks the password too', Signup::errors('dev@example.com', 'short'), ['password']);

$check(
    'invite keeps exactly the valid addresses',
    Invite::recipients(implode(',', [...$good, ...$bad])),
    array_map('trim', $good),
);

foreach ($good as $email) {
    $profile = new Profile('old@example.com');
    try {
        $profile->changeEmail($email);
        $check("profile stores '{$email}' trimmed", $profile->email(), trim($email));
    } catch (\InvalidArgumentException) {
        $check("profile accepts '{$email}'", 'threw', 'accepted');
    }
}
foreach ($bad as $email) {
    $profile = new Profile('old@example.com');
    try {
        $profile->changeEmail($email);
        $check("profile rejects '{$email}'", 'accepted', 'threw');
    } catch (\InvalidArgumentException) {
        $check("profile keeps the old address after '{$email}'", $profile->email(), 'old@example.com');
    }
}

echo $failed === 0 ? "ok\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
