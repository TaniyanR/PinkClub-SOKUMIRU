<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$defaultUser = [
    'username' => 'admin',
    'initial_setup_completed' => 0,
];

$cases = [
    [true, $defaultUser, 'admin', 'password'],
    [false, $defaultUser, 'admin', 'wrong-password'],
    [false, $defaultUser, 'other-user', 'password'],
    [false, ['username' => 'owner', 'initial_setup_completed' => 0], 'admin', 'password'],
    [false, ['username' => 'admin', 'initial_setup_completed' => 1], 'admin', 'password'],
];

$failures = 0;
foreach ($cases as $index => [$expected, $user, $username, $password]) {
    $actual = auth_matches_initial_credentials($user, $username, $password);
    if ($actual !== $expected) {
        fwrite(STDERR, 'case ' . ($index + 1) . " failed\n");
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, '5 cases passed' . PHP_EOL);
