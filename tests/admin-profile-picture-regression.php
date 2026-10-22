<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'api/index.php' => [
        "'admin-profile-picture'",
        "\$action === 'admin-delete-profile-picture'",
        'requireAdmin();',
        'profilePictureDirectory($config)',
        'getimagesize((string) $file[\'tmp_name\'])',
    ],
    'portal-data.js' => [
        'uploadAdminProfilePicture:function',
        'deleteAdminProfilePicture:function',
    ],
    'admin-profil.html' => [
        'id="profile-picture-input"',
        'id="profile-preview"',
        'id="remove-profile-picture"',
        'PortalData.uploadAdminProfilePicture(',
        'PortalData.deleteAdminProfilePicture(',
    ],
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

fwrite(STDOUT, "OK: Administratorer kan administrere andre profilers profilbilleder.\n");
