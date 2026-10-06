<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminPage = file_get_contents($root . '/admin-bruger.html');
$api = file_get_contents($root . '/api/index.php');
$failures = [];

if ($adminPage === false || $api === false) {
    fwrite(STDERR, "Kunne ikke læse filerne, der skal testes.\n");
    exit(1);
}

if (preg_match('/<input[^>]+name=["\']password["\']/i', $adminPage)) {
    $failures[] = 'Brugerformularen må ikke indeholde et adgangskodefelt.';
}

if (!str_contains($adminPage, 'var d={userId:userId,email:form.elements.email.value,role:form.elements.role.value,groupIds:selectedUserGroupIds.slice()}')) {
    $failures[] = 'Brugerformularen skal sende en eksplicit liste over tilladte felter.';
}

$updateStart = strpos($api, "\$action === 'admin-update-user'");
$resetStart = strpos($api, "\$action === 'admin-password-reset-link'", $updateStart ?: 0);
$updateRoute = $updateStart !== false && $resetStart !== false
    ? substr($api, $updateStart, $resetStart - $updateStart)
    : '';

if ($updateRoute === '') {
    $failures[] = 'Kunne ikke finde admin-update-user-ruten.';
} else {
    if (str_contains($updateRoute, 'SET email = :email, role = :role, password_hash')) {
        $failures[] = 'admin-update-user må ikke opdatere password_hash.';
    }
    if (!str_contains($updateRoute, 'Adgangskoder kan kun ændres via et personligt nulstillingslink.')) {
        $failures[] = 'admin-update-user skal afvise forsøg på at sende en adgangskode.';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FEJL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: En almindelig brugeropdatering kan ikke ændre adgangskoden.\n");
