<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api/index.php');
$loginPage = file_get_contents($root . '/login.html');
$failures = [];

if ($api === false || $loginPage === false) {
    fwrite(STDERR, "Kunne ikke læse loginfilerne.\n");
    exit(1);
}

$loginStart = strpos($api, "\$action === 'login'");
$registerStart = strpos($api, "\$action === 'register'", $loginStart ?: 0);
$loginRoute = $loginStart !== false && $registerStart !== false
    ? substr($api, $loginStart, $registerStart - $loginStart)
    : '';

if ($loginRoute === '') {
    $failures[] = 'Kunne ikke finde login-ruten.';
} else {
    if (!str_contains($loginRoute, '$passwordMatches = password_verify($submittedPassword, $passwordHash);')) {
        $failures[] = 'Login-ruten skal verificere både kendte og ukendte brugere på samme måde.';
    }
    if (str_contains($loginRoute, 'usleep(')) {
        $failures[] = 'Login-ruten må ikke blokere en PHP-worker for at forsinke fejlbeskeden.';
    }
}

if (!str_contains($loginPage, 'var remainingDelay = 750 - (Date.now() - startedAt);')) {
    $failures[] = 'Loginformularen skal vente mindst 750 ms, før den viser en fejl.';
}
if (!str_contains($loginPage, 'button.disabled = true;') || !str_contains($loginPage, 'button.disabled = false;')) {
    $failures[] = 'Loginformularen skal forhindre gentagne samtidige loginforsøg.';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FEJL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: Login bruger ensartet kontrol og en ikke-blokerende fejlbesked efter mindst 750 ms.\n");
