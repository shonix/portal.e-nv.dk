<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'api/mail.php' => ['portalMailPreferencesFooter', 'opdatere dine præferencer eller afmelde e-mails om nye opslag', '/min-profil.html'],
    'api/features.php' => ['sendPortalMail(', 'renderMeetingInvitationTemplate('],
    'api/index.php' => [
        "\$action === 'delete-my-account'",
        'password_verify(',
        'DELETE FROM meeting_guests',
        'DELETE FROM invitations',
        'DELETE FROM meeting_guest_invitations',
        'DELETE FROM users WHERE id = :id',
        'profilePictureStoredName',
        'session_destroy()',
    ],
    'portal-data.js' => ['deleteMyAccount:function'],
    'min-profil.html' => ['id="delete-account-form"', 'autocomplete="current-password"', 'Slet bruger permanent'],
    'login.html' => ['accountDeleted', 'Din bruger og dine personlige profiloplysninger er slettet.'],
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

$features = file_get_contents($root . '/api/features.php') ?: '';
$routeStart = strpos($features, "\$action === 'admin-send-meeting-invitations'");
$routeEnd = strpos($features, "\$action === 'admin-rotate-meeting-invitation'", $routeStart ?: 0);
$route = $routeStart !== false && $routeEnd !== false ? substr($features, $routeStart, $routeEnd - $routeStart) : '';
if ($route === '' || str_contains($route, 'mail(')) {
    $failures[] = 'Mødeinvitationer skal bruge den fælles mailfunktion med præferencefodtekst.';
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FEJL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "OK: Mailpræferencer og sikker selvbetjent brugersletning er forbundet hele vejen.\n");
