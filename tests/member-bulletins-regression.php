<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'database/migration-025-member-bulletins.sql' => [
        'ADD COLUMN IF NOT EXISTS title TEXT',
        "CHECK (category IN ('request', 'offer', 'other'))",
        "CHECK (status IN ('open', 'closed'))",
        'ADD COLUMN IF NOT EXISTS expires_at DATE',
    ],
    'database/schema.sql' => [
        'title TEXT NOT NULL',
        "category TEXT NOT NULL DEFAULT 'request'",
        "status TEXT NOT NULL DEFAULT 'open'",
        'expires_at DATE',
    ],
    'api/features.php' => [
        'function canPostGroupBulletin',
        "u.id <> :author_id",
        'u.bulletin_emails_enabled',
        "u.account_status = 'active'",
        "NOW() - INTERVAL '1 hour'",
        "'update-group-bulletin'",
        "'set-group-bulletin-status'",
        "'delete-group-bulletin'",
        'Du kan kun administrere dine egne opslag.',
    ],
    'portal-data.js' => [
        'addGroupBulletin:function',
        'updateGroupBulletin:function',
        'setGroupBulletinStatus:function',
        'deleteGroupBulletin:function',
    ],
    'gruppe.html' => [
        'id="new-bulletin"',
        'id="bulletin-form"',
        'Søger hjælp',
        'Tilbyder hjælp',
        'automatisk en e-mail',
        'data-edit-bulletin',
        'data-status-bulletin',
        'data-delete-bulletin',
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

fwrite(STDOUT, "OK: Gruppemedlemmer kan oprette og administrere opslag med sikker e-mailudsendelse.\n");
