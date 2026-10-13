<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'database/migration-023-account-lifecycle.sql' => [
        "account_status IN ('unclaimed', 'invited', 'active')",
        'users_account_status_idx',
    ],
    'database/schema.sql' => [
        "account_status TEXT NOT NULL DEFAULT 'active'",
    ],
    'api/index.php' => [
        "\$action === 'admin-unclaimed-profile'",
        "account_status = 'active'",
        "SET password_hash = :password_hash, account_status = 'active'",
        "UPDATE users SET account_status = 'invited'",
        "'accountStatus'",
    ],
    'api/features.php' => [
        "u.account_status = 'active'",
        'Vælg mindst én aktiv portalbruger eller ekstern gæst.',
    ],
    'portal-data.js' => ['addUnclaimedProfile:function'],
    'admin-profil-uden-login.html' => [
        'Opret profil uden login',
        'PortalData.addUnclaimedProfile',
        'labelIds',
        'groupIds',
    ],
    'admin-brugere.html' => [
        'accountStatus',
        'Ikke inviteret',
        'admin-profil-uden-login.html',
    ],
    'admin-bruger.html' => [
        'id="send-account-invitation"',
        'PortalData.addInvitation',
        'Invitation sendt',
    ],
    'deployment/portal-files.txt' => ['admin-profil-uden-login.html'],
];

$failures = [];
foreach ($checks as $file => $needles) {
    $contents = file_get_contents($root . '/' . $file);
    if ($contents === false) {
        $failures[] = "Kunne ikke læse {$file}.";
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) $failures[] = "{$file} mangler {$needle}.";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FEJL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "OK: Ikke-inviterede profiler kan oprettes, inviteres og overtages uden at miste profildata.\n");
