<?php
declare(strict_types=1);

function portalMailSettings(array $config): array
{
    $configured = $config['mail'] ?? [];
    if (!is_array($configured)) {
        $configured = [];
    }

    return array_merge([
        'provider' => 'php_mail',
        'api_key' => '',
        'tenant_id' => '',
        'client_id' => '',
        'client_secret' => '',
        'sender_address' => (string) ($config['mail_from'] ?? 'noreply@e-nv.dk'),
        'sender_name' => 'Ejendomsnetværket',
        'reply_to' => (string) ($config['mail_from'] ?? 'noreply@e-nv.dk'),
    ], $configured);
}

function portalMailResult(bool $sent, ?string $error = null): array
{
    return ['sent' => $sent, 'error' => $error];
}

function portalMailFailure(string $publicMessage, string $logMessage): array
{
    error_log('[portal mail] ' . $logMessage);
    return portalMailResult(false, $publicMessage);
}

function portalGraphAccessToken(array $settings): array
{
    static $cachedTokens = [];

    foreach (['tenant_id', 'client_id', 'client_secret'] as $field) {
        if (trim((string) ($settings[$field] ?? '')) === '') {
            return portalMailFailure('E-mailtjenesten er ikke konfigureret.', "Missing Microsoft Graph setting: $field");
        }
    }
    if (!function_exists('curl_init')) {
        return portalMailFailure('E-mailtjenesten er ikke tilgængelig.', 'PHP cURL extension is unavailable.');
    }

    $cacheKey = hash('sha256', implode('|', [
        (string) $settings['tenant_id'],
        (string) $settings['client_id'],
        (string) $settings['client_secret'],
    ]));
    $cached = $cachedTokens[$cacheKey] ?? null;
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
        return ['sent' => true, 'token' => $cached['token'], 'error' => null];
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode((string) $settings['tenant_id']) . '/oauth2/v2.0/token';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => (string) $settings['client_id'],
            'client_secret' => (string) $settings['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($response === false || $curlError !== '') {
        return portalMailFailure('Microsoft 365 kunne ikke kontaktes.', 'Token request failed: ' . $curlError);
    }
    $payload = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300 || !is_array($payload) || empty($payload['access_token'])) {
        return portalMailFailure('Microsoft 365 afviste forbindelsen.', "Token request returned HTTP $status.");
    }

    $expiresIn = max(300, (int) ($payload['expires_in'] ?? 3600));
    $cachedTokens[$cacheKey] = [
        'token' => (string) $payload['access_token'],
        'expires_at' => time() + $expiresIn,
    ];
    return ['sent' => true, 'token' => (string) $payload['access_token'], 'error' => null];
}

function sendPortalResendMail(array $settings, string $to, string $subject, string $body): array
{
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    $sender = trim((string) ($settings['sender_address'] ?? ''));
    $replyTo = trim((string) ($settings['reply_to'] ?? ''));
    if ($apiKey === '') {
        return portalMailFailure('E-mailtjenesten er ikke konfigureret.', 'Missing Resend API key.');
    }
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return portalMailFailure('E-mailtjenesten er ikke konfigureret.', 'Sender or reply-to address is invalid.');
    }
    if (!function_exists('curl_init')) {
        return portalMailFailure('E-mailtjenesten er ikke tilgængelig.', 'PHP cURL extension is unavailable.');
    }

    $senderName = trim((string) ($settings['sender_name'] ?? 'Ejendomsnetværket'));
    $payload = json_encode([
        'from' => $senderName . ' <' . $sender . '>',
        'to' => [$to],
        'reply_to' => $replyTo,
        'subject' => $subject,
        'text' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return portalMailFailure('E-mailen kunne ikke oprettes.', 'Unable to encode Resend message as JSON.');
    }

    $handle = curl_init('https://api.resend.com/emails');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($response === false || $curlError !== '') {
        return portalMailFailure('Resend kunne ikke kontaktes.', 'Resend request failed: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        return portalMailFailure('Resend afviste e-mailen.', "Resend request returned HTTP $status.");
    }
    return portalMailResult(true);
}

function sendPortalGraphMail(array $settings, string $to, string $subject, string $body): array
{
    $sender = trim((string) ($settings['sender_address'] ?? ''));
    $replyTo = trim((string) ($settings['reply_to'] ?? ''));
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return portalMailFailure('E-mailtjenesten er ikke konfigureret.', 'Sender or reply-to address is invalid.');
    }

    $tokenResult = portalGraphAccessToken($settings);
    if (!$tokenResult['sent']) {
        return $tokenResult;
    }

    $senderName = trim((string) ($settings['sender_name'] ?? 'Ejendomsnetværket'));
    $payload = json_encode([
        'message' => [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $body],
            'from' => ['emailAddress' => ['address' => $sender, 'name' => $senderName]],
            'toRecipients' => [[
                'emailAddress' => ['address' => $to],
            ]],
            'replyTo' => [[
                'emailAddress' => ['address' => $replyTo],
            ]],
        ],
        'saveToSentItems' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return portalMailFailure('E-mailen kunne ikke oprettes.', 'Unable to encode Graph message as JSON.');
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $tokenResult['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($response === false || $curlError !== '') {
        return portalMailFailure('Microsoft 365 kunne ikke kontaktes.', 'Send request failed: ' . $curlError);
    }
    if ($status !== 202) {
        return portalMailFailure('Microsoft 365 afviste e-mailen.', "Send request returned HTTP $status.");
    }
    return portalMailResult(true);
}

function sendPortalMail(array $config, string $to, string $subject, string $body): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return portalMailFailure('Modtagerens e-mailadresse er ugyldig.', 'Invalid recipient address.');
    }

    $settings = portalMailSettings($config);
    $provider = strtolower(trim((string) ($settings['provider'] ?? 'php_mail')));
    if ($provider === 'resend') {
        return sendPortalResendMail($settings, $to, $subject, $body);
    }
    if ($provider === 'microsoft_graph') {
        return sendPortalGraphMail($settings, $to, $subject, $body);
    }
    if ($provider !== 'php_mail') {
        return portalMailFailure('E-mailtjenesten er ikke konfigureret.', "Unsupported mail provider: $provider");
    }

    $sender = trim((string) ($settings['sender_address'] ?? 'noreply@e-nv.dk'));
    $replyTo = trim((string) ($settings['reply_to'] ?? $sender));
    $senderName = trim((string) ($settings['sender_name'] ?? 'Ejendomsnetværket'));
    $headers = implode("\r\n", [
        'From: ' . $senderName . ' <' . $sender . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);
    $sent = mail($to, $subject, $body, $headers);
    return $sent
        ? portalMailResult(true)
        : portalMailFailure('E-mailen kunne ikke sendes af serveren.', 'PHP mail() returned false.');
}
