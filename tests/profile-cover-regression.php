<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'database/migration-024-profile-covers.sql' => [
        'profile_cover_stored_name TEXT UNIQUE',
        'profile_cover_mime_type TEXT',
        'profile_cover_size BIGINT',
    ],
    'database/schema.sql' => ['profile_cover_stored_name TEXT UNIQUE'],
    'api/index.php' => [
        'function profileCoverDirectory',
        'function profileCoverUrl',
        "\$action === 'profile-cover'",
        "\$action === 'delete-profile-cover'",
        'profileCoverImageUrl',
        'profileCoverStoredName',
        'getimagesize((string) $file[\'tmp_name\'])',
    ],
    'portal-data.js' => ['uploadProfileCover:function', 'deleteProfileCover:function'],
    'min-profil.html' => [
        'id="profile-cover-input"',
        'id="cover-preview"',
        'id="remove-profile-cover"',
        'PortalData.uploadProfileCover(',
        'PortalData.deleteProfileCover(',
        'Se min profil som andre ser den',
        'target="_blank"',
    ],
    'partner-profil.html' => ['id="profile-cover"', 'partner.profileCoverImageUrl'],
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

fwrite(STDOUT, "OK: Brugere kan uploade, vise og fjerne profilcovers sikkert.\n");
