<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expectations = [
    'privatlivspolitik.html' => ['Ejendomspartner Danmark ApS', 'CVR-nr. 38502476', 'hashværdi af adgangskoden', 'legitime interesse', 'Dine rettigheder', 'Datatilsynet'],
    'cookiepolitik.html' => ['env_portal_session', 'teknisk nødvendig', 'ikke et cookiebanner'],
    'samtykke.html' => ['E-mails og kommunikation', 'Servicemeddelelser', 'Valgfrie e-mails om opslag', 'Markedsføring'],
    'portal-header.js' => ['privatlivspolitik.html', 'cookiepolitik.html', 'samtykke.html'],
    'registrer.html' => ['privatlivspolitikken', 'E-mails og kommunikation'],
    'api/mail.php' => ['portalMailPrivacyFooter', '/privatlivspolitik.html'],
    'deployment/portal-files.txt' => ['privatlivspolitik.html', 'cookiepolitik.html', 'samtykke.html', 'legal.css'],
];

$forbidden = [
    'privatlivspolitik.html' => ['Dit valg under Min profil og, hvor kommunikationen kræver det, dit samtykke'],
    'samtykke.html' => ['Samtykke og e-mails', 'consent-copy'],
    'api/mail.php' => ['curl_close('],
];

foreach ($expectations as $file => $needles) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $file);
    if ($contents === false) {
        fwrite(STDERR, "Kunne ikke læse {$file}.\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (strpos($contents, $needle) === false) {
            fwrite(STDERR, "{$file} mangler: {$needle}\n");
            exit(1);
        }
    }
}

foreach ($forbidden as $file => $needles) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $file);
    if ($contents === false) {
        fwrite(STDERR, "Kunne ikke læse {$file}.\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (strpos($contents, $needle) !== false) {
            fwrite(STDERR, "{$file} indeholder forældet tekst: {$needle}\n");
            exit(1);
        }
    }
}

echo "OK: Juridiske sider, globale links og information om e-mailvalg er til stede.\n";
