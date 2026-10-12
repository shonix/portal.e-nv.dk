<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'database/migration-021-meeting-covers.sql' => ['cover_image_stored_name TEXT UNIQUE'],
    'api/index.php' => ['admin-meeting-picture', 'admin-delete-meeting-picture', "action === 'meeting-picture'", 'attachMeetingCoverUrl'],
    'portal-data.js' => ['uploadMeetingPicture:function', 'deleteMeetingPicture:function'],
    'admin-moede-indstillinger.html' => ['id="meeting-cover-input"', 'id="upload-meeting-cover"'],
    'index.html' => ['class="meeting-date"', 'meeting.coverImageUrl', 'data-month=', 'meeting-month-filters', 'all-months-filter', 'month-prev', 'month-next', 'monthWindowSize', 'role="tablist"', 'group-pagination', 'meeting-pagination', 'pageSize = 8'],
    'moede.html' => ['meeting.coverImageUrl'],
];

$forbidden = [
    'index.html' => ['data-filter="all"', 'selectedMeetingStatus === "all"'],
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

foreach ($forbidden as $file => $needles) {
    $contents = file_get_contents($root . '/' . $file);
    if ($contents === false) continue;
    foreach ($needles as $needle) {
        if (str_contains($contents, $needle)) $failures[] = "{$file} må ikke indeholde {$needle}.";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FEJL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "OK: Mødekort, månedsfiltre og mødebilleder er forbundet hele vejen.\n");
