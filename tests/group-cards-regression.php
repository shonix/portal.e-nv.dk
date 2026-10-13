<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'api' => $root . '/api/index.php',
    'migration' => $root . '/database/migration-020-group-covers.sql',
    'schema' => $root . '/database/schema.sql',
    'client' => $root . '/portal-data.js',
    'overview' => $root . '/index.html',
    'group' => $root . '/gruppe.html',
    'admin' => $root . '/admin-gruppe.html',
];
$contents = [];
$failures = [];

foreach ($files as $name => $path) {
    $contents[$name] = file_get_contents($path);
    if ($contents[$name] === false) $failures[] = "Kunne ikke læse {$name}.";
}

if (!str_contains((string) $contents['migration'], 'cover_image_stored_name TEXT UNIQUE')
    || !str_contains((string) $contents['migration'], 'description TEXT')) {
    $failures[] = 'Migration 020 skal oprette beskrivelse og metadata til gruppebilleder.';
}
if (!str_contains((string) $contents['schema'], 'cover_image_mime_type TEXT')) {
    $failures[] = 'Det komplette databaseskema mangler gruppebilledets metadata.';
}
foreach (['admin-group-picture', 'admin-delete-group-picture', "action === 'group-picture'"] as $endpoint) {
    if (!str_contains((string) $contents['api'], $endpoint)) {
        $failures[] = "API-ruten {$endpoint} mangler.";
    }
}
if (!str_contains((string) $contents['api'], 'getimagesize((string) $file[\'tmp_name\'])')
    || !str_contains((string) $contents['api'], "header('X-Content-Type-Options: nosniff')")) {
    $failures[] = 'Gruppebilleder skal indholdsvalideres og leveres med nosniff.';
}
if (!str_contains((string) $contents['api'], 'function representedGroupLabels')
    || !str_contains((string) $contents['api'], "'representedLabels' => representedGroupLabels")) {
    $failures[] = 'Gruppe-API\'et skal returnere brancher, der er repræsenteret i gruppen.';
}
if (!str_contains((string) $contents['client'], 'uploadGroupPicture:function')
    || !str_contains((string) $contents['client'], 'deleteGroupPicture:function')) {
    $failures[] = 'Frontend-klienten mangler upload og sletning af gruppebilleder.';
}
if (!str_contains((string) $contents['admin'], 'id="group-cover-input"')
    || !str_contains((string) $contents['admin'], 'name="description"')) {
    $failures[] = 'Gruppeadministrationen mangler billede eller beskrivelse.';
}
if (!str_contains((string) $contents['overview'], 'class="group-cover"')
    || !str_contains((string) $contents['overview'], 'group.coverImageUrl')
    || !str_contains((string) $contents['overview'], 'group.description')) {
    $failures[] = 'Gruppeoversigten skal vise kort med billede og beskrivelse.';
}
if (!str_contains((string) $contents['group'], 'id="group-hero"')
    || !str_contains((string) $contents['group'], 'data.group.coverImageUrl')
    || !str_contains((string) $contents['group'], 'id="represented-labels"')
    || !str_contains((string) $contents['group'], 'renderIndustryOverview(data.representedLabels,data.missingLabels)')) {
    $failures[] = 'Gruppesiden skal vise gruppebillede og brancheoverblik.';
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FEJL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "OK: Gruppekort, beskrivelser og sikre gruppebilleder er forbundet hele vejen.\n");
