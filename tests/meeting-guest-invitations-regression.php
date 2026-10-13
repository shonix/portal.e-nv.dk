<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'database/migration-022-meeting-guest-invitations.sql' => [
        'CREATE TABLE IF NOT EXISTS meeting_guest_invitations',
        'token_hash TEXT NOT NULL UNIQUE',
        'guest_created_by_invitation BOOLEAN',
        'LOWER(email)',
    ],
    'database/schema.sql' => ['CREATE TABLE IF NOT EXISTS meeting_guest_invitations'],
    'api/features.php' => [
        "\$action === 'guest-meeting-invitation'",
        "\$action === 'guest-meeting-rsvp'",
        'guestRecipients',
        'mailSubject',
        'mailMessage',
        'renderMeetingInvitationTemplate',
        "'recipientType' => 'guest'",
        "hash('sha256', \$guestToken)",
        'INSERT INTO meeting_guests',
    ],
    'api/index.php' => [
        "\$action === 'public-meeting-guest'",
        'LOWER(TRIM(email)) = LOWER(TRIM(:email))',
        "respond(['registered' => true], 201)",
    ],
    'portal-data.js' => ['guestMeetingInvitation:function', 'saveGuestMeetingRsvp:function', 'registerPublicMeetingGuest:function'],
    'admin-moedeinvitationer.html' => [
        'data-tab="guests"',
        'id="guest-drafts"',
        'guestRecipients:guestDrafts.slice()',
        'id="mail-modal"',
        'id="edit-email"',
        'mailSubject:mailSubjectTemplate',
        'Invitationer og svar',
    ],
    'gaesteinvitation.html' => ['Jeg deltager', 'Jeg deltager ikke', 'saveGuestMeetingRsvp'],
    'moede.html' => [
        'id="public-guest-dialog"',
        'id="public-guest-form"',
        'registerPublicMeetingGuest',
        'id="copy-meeting-link"',
        'navigator.clipboard.writeText',
    ],
    'deployment/portal-files.txt' => ['gaesteinvitation.html'],
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

fwrite(STDOUT, "OK: Offentlig gæstetilmelding, eksterne gæsteinvitationer, mødelinks og adminstatus er forbundet hele vejen.\n");
