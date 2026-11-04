<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api/index.php') ?: '';
$overview = file_get_contents($root . '/index.html') ?: '';
$failures = [];

foreach (['AS "groupNames"', 'JOIN group_members gm', "action === 'partners'", 'requireLogin();'] as $needle) {
    if (!str_contains($api, $needle)) $failures[] = "Partner-API mangler {$needle}.";
}

foreach ([
    'data-mobile-section="groups">Partnere</button>',
    'id="partner-search"',
    'Find en partner med et søgeord',
    'function renderPartnerSearch()',
    'partner.company, partner.industry, partner.labels, partner.groupNames, partner.biography',
    'class="partner-results"',
    'partner-profil.html?id=',
] as $needle) {
    if (!str_contains($overview, $needle)) $failures[] = "Forsiden mangler {$needle}.";
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FEJL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "OK: Global partnersøgning og Partnere-fanen er forbundet.\n");
