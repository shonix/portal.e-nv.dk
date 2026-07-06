<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) ($_SESSION['user_id'] ?? 0);

function required(array $body, array $fields): void
{
    foreach ($fields as $field) {
        if (trim((string) ($body[$field] ?? '')) === '') respond(['error' => "Missing required field: $field."], 422);
    }
}

function nullable(array $body, string $field): ?string
{
    $value = trim((string) ($body[$field] ?? ''));
    return $value === '' ? null : $value;
}

function idList(array $body, string $multiField, string $singleField = ''): array
{
    $values = $body[$multiField] ?? [];
    if (!is_array($values)) {
        $values = [$values];
    }
    if (!$values && $singleField !== '' && trim((string) ($body[$singleField] ?? '')) !== '') {
        $values = [$body[$singleField]];
    }
    $ids = [];
    foreach ($values as $value) {
        $id = (int) $value;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
}

function syncMeetingGroups(PDO $pdo, int $meetingId, array $groupIds): void
{
    $pdo->prepare('DELETE FROM meeting_groups WHERE meeting_id = :meeting_id')->execute(['meeting_id' => $meetingId]);
    $insert = $pdo->prepare(
        'INSERT INTO meeting_groups (meeting_id, group_id) VALUES (:meeting_id, :group_id) ON CONFLICT DO NOTHING'
    );
    foreach ($groupIds as $groupId) {
        $insert->execute(['meeting_id' => $meetingId, 'group_id' => (int) $groupId]);
    }
}

function manualPartnerNames(?string $text): array
{
    $text = trim((string) $text);
    if ($text === '') return [];
    $parts = preg_split('/[\r\n,;]+/', $text) ?: [];
    $names = [];
    foreach ($parts as $part) {
        $name = trim(preg_replace('/\s+/', ' ', $part) ?: '');
        if ($name !== '') $names[$name] = $name;
    }
    return array_values($names);
}

function accountInvitationUrl(string $token): string
{
    global $config;
    return portalBaseUrl($config)
        . '/registrer.html?token=' . urlencode($token);
}

function profilePictureDirectory(array $config): string
{
    return (string) ($config['profile_picture_dir'] ?? (dirname(__DIR__, 2) . '/portal-private/profile-pictures'));
}

function profilePictureUrl(array $partner): ?string
{
    if (empty($partner['profilePictureStoredName']) || empty($partner['id'])) return null;
    return 'api/index.php?action=profile-picture&partnerId=' . urlencode((string) $partner['id'])
        . '&v=' . urlencode((string) $partner['profilePictureStoredName']);
}

function attachProfilePictureUrl(?array &$partner): void
{
    if (!$partner) return;
    $partner['profileImageUrl'] = profilePictureUrl($partner);
    unset($partner['profilePictureStoredName']);
}

function attachProfilePictureUrls(array &$partners): void
{
    foreach ($partners as &$partner) {
        attachProfilePictureUrl($partner);
    }
    unset($partner);
}

if ($method === 'POST' && $action === 'login') {
    $body = requestBody();
    $statement = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = :email');
    $statement->execute(['email' => strtolower(trim((string) ($body['email'] ?? '')))]);
    $user = $statement->fetch();
    if (!$user || !password_verify((string) ($body['password'] ?? ''), $user['password_hash'])) {
        respond(['error' => 'Invalid email or password.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    respond(['ok' => true, 'role' => $user['role']]);
}

if ($method === 'POST' && $action === 'register') {
    $body = requestBody();
    required($body, ['token', 'password']);
    if (strlen((string) $body['password']) < 10) {
        respond(['error' => 'Password must contain at least 10 characters.'], 422);
    }
    $invite = $pdo->prepare(
        'SELECT id, email FROM invitations
         WHERE token_hash = :token_hash AND used_at IS NULL
         -- Re-enable this later if user invitations should expire again:
         -- AND expires_at > NOW()
         FOR UPDATE'
    );
    $pdo->beginTransaction();
    $invite->execute(['token_hash' => hash('sha256', (string) $body['token'])]);
    $invitation = $invite->fetch();
    if (!$invitation) {
        $pdo->rollBack();
        respond(['error' => 'Invitation is invalid or has already been used.'], 422);
    }
    $statement = $pdo->prepare(
        "INSERT INTO users (email, password_hash, role) VALUES (:email, :password_hash, 'member')
         RETURNING id, role"
    );
    try {
        $statement->execute([
            'email' => strtolower(trim((string) $invitation['email'])),
            'password_hash' => password_hash((string) $body['password'], PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $error) {
        $pdo->rollBack();
        if ($error->getCode() === '23505') respond(['error' => 'An account with this email already exists.'], 409);
        throw $error;
    }
    $user = $statement->fetch();
    $pdo->prepare('UPDATE invitations SET used_at = NOW() WHERE id = :id')->execute(['id' => (int) $invitation['id']]);
    $pdo->commit();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    respond(['ok' => true, 'role' => $user['role']], 201);
}

if ($method === 'GET' && $action === 'admin-invitations') {
    requireAdmin();
    $invitations = $pdo->query(
        'SELECT id::text, email, admin_token AS "adminToken",
                expires_at::text AS "expiresAt", used_at::text AS "usedAt",
                created_at::text AS "createdAt"
         FROM invitations ORDER BY created_at DESC'
    )->fetchAll();
    foreach ($invitations as &$invitation) {
        $invitation['url'] = $invitation['adminToken']
            ? accountInvitationUrl((string) $invitation['adminToken'])
            : null;
        unset($invitation['adminToken']);
    }
    unset($invitation);
    respond(['invitations' => $invitations]);
}

if ($method === 'POST' && $action === 'admin-invitations') {
    requireAdmin();
    $body = requestBody();
    required($body, ['email']);
    $email = strtolower(trim((string) $body['email']));
    $token = bin2hex(random_bytes(32));
    $statement = $pdo->prepare(
        "INSERT INTO invitations (email, token_hash, admin_token, expires_at)
         VALUES (:email, :token_hash, :admin_token, NOW() + INTERVAL '7 days')
         RETURNING id::text, email, expires_at::text AS \"expiresAt\""
    );
    $statement->execute([
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'admin_token' => $token,
    ]);
    $invitation = $statement->fetch();
    $invitation['url'] = accountInvitationUrl($token);
    $from = (string) ($config['mail_from'] ?? 'noreply@e-nv.dk');
    $subject = 'Invitation til Ejendomsnetværkets partnerportal';
    $message = "Hej\n\nDu er blevet inviteret til Ejendomsnetværkets partnerportal.\n\nOpret din konto her:\n" .
        $invitation['url'] .
        "\n\nLinket udløber efter 7 dage.\n\nVenlig hilsen\nEjendomsnetværket";
    $headers = [
        'From: Ejendomsnetværket <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $invitation['emailSent'] = mail($email, $subject, $message, implode("\r\n", $headers));
    respond(['invitation' => $invitation], 201);
}

if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    respond(['ok' => true]);
}

if ($method === 'GET' && $action === 'session') {
    $email = null;
    if (isset($_SESSION['user_id'])) {
        $statement = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $email = $statement->fetchColumn() ?: null;
    }
    respond(['loggedIn' => isset($_SESSION['user_id']), 'id' => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null, 'role' => $_SESSION['role'] ?? null, 'email' => $email]);
}

if ($method === 'POST' && $action === 'profile-picture') {
    requireLogin();
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['error' => 'Vælg et profilbillede.'], 422);
    }
    $maxSize = (int) ($config['profile_picture_max_bytes'] ?? 2 * 1024 * 1024);
    if ((int) $file['size'] > $maxSize) respond(['error' => 'Profilbilledet er for stort.'], 422);
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
    ];
    if (!isset($allowed[$extension])) respond(['error' => 'Brug JPG, PNG eller WebP.'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, $allowed[$extension], true) || !getimagesize((string) $file['tmp_name'])) {
        respond(['error' => 'Filen ligner ikke et gyldigt billede.'], 422);
    }
    $partnerStatement = $pdo->prepare(
        'SELECT id::text, profile_picture_stored_name AS "profilePictureStoredName"
         FROM partners WHERE user_id = :user_id'
    );
    $partnerStatement->execute(['user_id' => $userId]);
    $partner = $partnerStatement->fetch();
    if (!$partner) respond(['error' => 'Gem profilen, før du uploader et profilbillede.'], 422);
    $directory = profilePictureDirectory($config);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        respond(['error' => 'Uploadmappen kunne ikke oprettes.'], 500);
    }
    $directory = realpath($directory) ?: '';
    if ($directory === '') respond(['error' => 'Uploadmappen kunne ikke valideres.'], 500);
    $storedName = bin2hex(random_bytes(20)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        respond(['error' => 'Profilbilledet kunne ikke gemmes.'], 500);
    }
    $oldName = (string) ($partner['profilePictureStoredName'] ?? '');
    try {
        $pdo->prepare(
            'UPDATE partners
             SET profile_picture_stored_name = :stored_name,
                 profile_picture_mime_type = :mime_type,
                 profile_picture_size = :file_size
             WHERE id = :id'
        )->execute([
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'file_size' => (int) $file['size'],
            'id' => (int) $partner['id'],
        ]);
    } catch (Throwable $exception) {
        @unlink($destination);
        throw $exception;
    }
    if ($oldName !== '') {
        $oldPath = $directory . DIRECTORY_SEPARATOR . basename($oldName);
        if (is_file($oldPath)) @unlink($oldPath);
    }
    $partner['profilePictureStoredName'] = $storedName;
    attachProfilePictureUrl($partner);
    respond(['partner' => $partner]);
}

if ($method === 'GET' && $action === 'profile-picture') {
    requireLogin();
    $partnerId = (int) ($_GET['partnerId'] ?? 0);
    if ($partnerId <= 0) respond(['error' => 'Partner is required.'], 422);
    $statement = $pdo->prepare(
        'SELECT p.id, p.profile_picture_stored_name, p.profile_picture_mime_type, p.profile_picture_size
         FROM partners p
         JOIN users profile_owner ON profile_owner.id = p.user_id
           OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
         WHERE p.id = :partner_id
           AND p.profile_picture_stored_name IS NOT NULL
           AND (
              :is_admin = 1
              OR profile_owner.id = :owner_user_id
              OR EXISTS (
                  SELECT 1
                  FROM group_members viewer_groups
                  JOIN group_members partner_groups ON partner_groups.group_id = viewer_groups.group_id
                  WHERE viewer_groups.user_id = :viewer_user_id
                    AND partner_groups.user_id = profile_owner.id
              )
           )'
    );
    $statement->execute([
        'partner_id' => $partnerId,
        'is_admin' => ($_SESSION['role'] ?? null) === 'admin' ? 1 : 0,
        'owner_user_id' => $userId,
        'viewer_user_id' => $userId,
    ]);
    $picture = $statement->fetch();
    if (!$picture) respond(['error' => 'Profilbilledet findes ikke.'], 404);
    $path = profilePictureDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $picture['profile_picture_stored_name']);
    if (!is_file($path)) respond(['error' => 'Profilbilledet mangler på serveren.'], 404);
    header_remove('Content-Type');
    header('Content-Type: ' . $picture['profile_picture_mime_type']);
    header('Content-Length: ' . (string) $picture['profile_picture_size']);
    header('Cache-Control: private, max-age=86400');
    session_write_close();
    readfile($path);
    exit;
}

if ($method === 'GET' && $action === 'groups') {
    respond(['groups' => $pdo->query(
        'SELECT g.id::text, g.name, g.address, COUNT(gm.user_id)::int AS "memberCount"
         FROM groups g LEFT JOIN group_members gm ON gm.group_id = g.id
         GROUP BY g.id ORDER BY g.name'
    )->fetchAll()]);
}

if ($method === 'GET' && $action === 'my-groups') {
    requireLogin();
    if (($_SESSION['role'] ?? null) === 'admin') {
        respond(['groups' => $pdo->query('SELECT id::text, name, address FROM groups ORDER BY name')->fetchAll()]);
    }
    $statement = $pdo->prepare(
        'SELECT g.id::text, g.name, g.address
         FROM groups g JOIN group_members gm ON gm.group_id = g.id
         WHERE gm.user_id = :user_id ORDER BY g.name'
    );
    $statement->execute(['user_id' => $userId]);
    respond(['groups' => $statement->fetchAll()]);
}

if ($method === 'GET' && $action === 'meetings') {
    $meetings = $pdo->query(
        'SELECT m.id::text, m.slug, m.title, m.meeting_date::text AS "date",
                to_char(m.meeting_time, \'HH24:MI\') AS "time", m.address,
                CASE
                    WHEN m.status = \'cancelled\' THEN \'cancelled\'
                    WHEN (m.meeting_date + m.meeting_time) < (CURRENT_TIMESTAMP AT TIME ZONE \'Europe/Copenhagen\') THEN \'held\'
                    ELSE m.status
                END AS status,
                m.partners_text AS "partners", m.program_text AS "program",
                m.location_text AS "location", m.files_text AS "files",
                m.guests_text AS "guests", m.invite_text AS "invite",
                MIN(g.id)::text AS "groupId",
                COALESCE(json_agg(DISTINCT g.id::text) FILTER (WHERE g.id IS NOT NULL), \'[]\'::json) AS "groupIds",
                COALESCE(string_agg(DISTINCT g.name, \', \' ORDER BY g.name), \'\') AS "groupName",
                COALESCE(string_agg(DISTINCT g.name, \', \' ORDER BY g.name), \'\') AS "groupNames",
                m.rsvp_approval_mode AS "approvalMode"
         FROM meetings m
         LEFT JOIN meeting_groups mg ON mg.meeting_id = m.id
         LEFT JOIN groups g ON g.id = mg.group_id
         GROUP BY m.id
         ORDER BY m.meeting_date DESC, m.meeting_time DESC'
    )->fetchAll();
    foreach ($meetings as &$meeting) {
        $meeting['groupIds'] = json_decode((string) $meeting['groupIds'], true) ?: [];
    }
    unset($meeting);
    respond(['meetings' => $meetings]);
}

if ($method === 'GET' && $action === 'labels') {
    requireLogin();
    respond(['labels' => $pdo->query('SELECT id::text, name FROM partner_labels ORDER BY name')->fetchAll()]);
}

if ($method === 'GET' && $action === 'admin-labels') {
    requireAdmin();
    $search = trim((string) ($_GET['search'] ?? ''));
    $usage = (string) ($_GET['usage'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(5, (int) ($_GET['pageSize'] ?? 10)));
    $offset = ($page - 1) * $pageSize;
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = 'LOWER(l.name) LIKE LOWER(:search)';
        $params['search'] = '%' . $search . '%';
    }
    if ($usage === 'used') {
        $where[] = 'EXISTS (SELECT 1 FROM partner_profile_labels ppl WHERE ppl.label_id = l.id)';
    } elseif ($usage === 'unused') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM partner_profile_labels ppl WHERE ppl.label_id = l.id)';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM partner_labels l' . $whereSql);
    foreach ($params as $key => $value) {
        $count->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $labels = $pdo->prepare(
        'SELECT l.id::text, l.name, COUNT(DISTINCT ppl.partner_id)::int AS "profileCount"
         FROM partner_labels l
         LEFT JOIN partner_profile_labels ppl ON ppl.label_id = l.id'
         . $whereSql .
        ' GROUP BY l.id
          ORDER BY LOWER(l.name), l.id
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $labels->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $labels->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $labels->bindValue(':offset', $offset, PDO::PARAM_INT);
    $labels->execute();
    respond([
        'labels' => $labels->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}

if ($method === 'GET' && $action === 'partners') {
    requireLogin();
    if (($_SESSION['role'] ?? null) === 'admin') {
        $partners = $pdo->query(
            'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin", p.industry, p.company,
                     p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                     p.profile_picture_stored_name AS "profilePictureStoredName",
                     COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels
              FROM partners p
              LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
              LEFT JOIN partner_labels l ON l.id = ppl.label_id
              GROUP BY p.id ORDER BY p.name'
        )->fetchAll();
        attachProfilePictureUrls($partners);
        respond(['partners' => $partners]);
    }
    $statement = $pdo->prepare(
        'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin",
                 p.industry, p.company, p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                 p.profile_picture_stored_name AS "profilePictureStoredName",
                 COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels
         FROM partners p
         JOIN users profile_owner ON profile_owner.id = p.user_id
           OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
         LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
         LEFT JOIN partner_labels l ON l.id = ppl.label_id
         WHERE profile_owner.id = :owner_user_id
            OR EXISTS (
                SELECT 1
                FROM group_members viewer_groups
                JOIN group_members partner_groups ON partner_groups.group_id = viewer_groups.group_id
                WHERE viewer_groups.user_id = :viewer_user_id
                  AND partner_groups.user_id = profile_owner.id
            )
         GROUP BY p.id
         ORDER BY p.name'
    );
    $statement->execute(['owner_user_id' => $userId, 'viewer_user_id' => $userId]);
    $partners = $statement->fetchAll();
    attachProfilePictureUrls($partners);
    respond(['partners' => $partners]);
}

if ($method === 'GET' && $action === 'group-partners') {
    requireLogin();
    $groupId = (int) ($_GET['groupId'] ?? 0);
    if ($groupId <= 0) respond(['error' => 'Group is required.'], 422);
    if (($_SESSION['role'] ?? null) !== 'admin') {
        $access = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
        $access->execute(['group_id' => $groupId, 'user_id' => $userId]);
        if (!$access->fetchColumn()) respond(['error' => 'You do not have access to this group.'], 403);
    }
    $group = $pdo->prepare(
        'SELECT g.id::text, g.name, g.address, COUNT(gm.user_id)::int AS "memberCount"
         FROM groups g LEFT JOIN group_members gm ON gm.group_id = g.id
         WHERE g.id = :group_id GROUP BY g.id'
    );
    $group->execute(['group_id' => $groupId]);
    $partners = $pdo->prepare(
        'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin",
                 p.industry, p.company, p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                 p.profile_picture_stored_name AS "profilePictureStoredName",
                 COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels
         FROM partners p
         JOIN users profile_owner ON profile_owner.id = p.user_id
           OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
         JOIN group_members gm ON gm.user_id = profile_owner.id
         LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
         LEFT JOIN partner_labels l ON l.id = ppl.label_id
         WHERE gm.group_id = :group_id GROUP BY p.id ORDER BY p.name'
    );
    $partners->execute(['group_id' => $groupId]);
    $partnerRows = $partners->fetchAll();
    attachProfilePictureUrls($partnerRows);
    respond(['group' => $group->fetch(), 'partners' => $partnerRows]);
}

if ($method === 'GET' && $action === 'meeting-partners') {
    requireLogin();
    $meetingKey = trim((string) ($_GET['id'] ?? ''));
    if ($meetingKey === '') respond(['error' => 'Meeting is required.'], 422);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(25, max(5, (int) ($_GET['pageSize'] ?? 8)));
    $offset = ($page - 1) * $pageSize;
    $search = trim((string) ($_GET['search'] ?? ''));
    $labelId = (int) ($_GET['labelId'] ?? 0);
    $meeting = $pdo->prepare(
        'SELECT id, id::text AS "idText", slug, title, partners_text AS "manualPartners",
                (SELECT COUNT(*) FROM meeting_groups mg WHERE mg.meeting_id = meetings.id)::int AS "groupCount"
         FROM meetings
         WHERE (:numeric_id > 0 AND id = :numeric_id) OR slug = :slug'
    );
    $numericId = ctype_digit($meetingKey) ? (int) $meetingKey : 0;
    $meeting->execute(['numeric_id' => $numericId, 'slug' => $meetingKey]);
    $meeting = $meeting->fetch();
    if (!$meeting) respond(['error' => 'Mødet findes ikke.'], 404);
    if (($_SESSION['role'] ?? null) !== 'admin' && (int) $meeting['groupCount'] > 0) {
        $access = $pdo->prepare(
            'SELECT 1
             FROM meeting_groups mg
             JOIN group_members gm ON gm.group_id = mg.group_id
             WHERE mg.meeting_id = :meeting_id AND gm.user_id = :user_id'
        );
        $access->execute(['meeting_id' => (int) $meeting['id'], 'user_id' => $userId]);
        if (!$access->fetchColumn()) respond(['error' => 'Du har ikke adgang til dette mødes partnere.'], 403);
    }
    if ((int) $meeting['groupCount'] > 0) {
        $where = [];
        $params = ['meeting_id' => (int) $meeting['id']];
        if ($search !== '') {
            $where[] = '(LOWER(p.name) LIKE LOWER(:search)
                OR LOWER(p.company) LIKE LOWER(:search)
                OR LOWER(p.email) LIKE LOWER(:search)
                OR EXISTS (
                    SELECT 1 FROM partner_profile_labels spl
                    JOIN partner_labels sl ON sl.id = spl.label_id
                    WHERE spl.partner_id = p.id AND LOWER(sl.name) LIKE LOWER(:search)
                ))';
            $params['search'] = '%' . $search . '%';
        }
        if ($labelId > 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM partner_profile_labels fpl
                WHERE fpl.partner_id = p.id AND fpl.label_id = :label_id
            )';
            $params['label_id'] = $labelId;
        }
        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
        $baseSql =
            ' FROM partners p
              JOIN users profile_owner ON profile_owner.id = p.user_id
                OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
              JOIN group_members gm ON gm.user_id = profile_owner.id
              JOIN meeting_groups mg ON mg.group_id = gm.group_id
              WHERE mg.meeting_id = :meeting_id' . $whereSql;
        $listSql =
            ' FROM partners p
              JOIN users profile_owner ON profile_owner.id = p.user_id
                OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
              JOIN group_members gm ON gm.user_id = profile_owner.id
              JOIN meeting_groups mg ON mg.group_id = gm.group_id
              LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
              LEFT JOIN partner_labels l ON l.id = ppl.label_id
              WHERE mg.meeting_id = :meeting_id' . $whereSql;
        $count = $pdo->prepare('SELECT COUNT(DISTINCT p.id)::int' . $baseSql);
        foreach ($params as $key => $value) {
            $count->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();
        $partners = $pdo->prepare(
            'SELECT p.id::text, p.slug, p.name, p.company, p.email,
                    p.linkedin_url AS "linkedin", p.company_url AS "companyUrl",
                    p.profile_picture_stored_name AS "profilePictureStoredName",
                    COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels'
            . $listSql .
            ' GROUP BY p.id
              ORDER BY p.name
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $partners->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $partners->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $partners->bindValue(':offset', $offset, PDO::PARAM_INT);
        $partners->execute();
        $labels = $pdo->prepare(
            'SELECT DISTINCT l.id::text, l.name
             FROM partner_labels l
             JOIN partner_profile_labels ppl ON ppl.label_id = l.id
             JOIN partners p ON p.id = ppl.partner_id
             JOIN users profile_owner ON profile_owner.id = p.user_id
               OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
             JOIN group_members gm ON gm.user_id = profile_owner.id
             JOIN meeting_groups mg ON mg.group_id = gm.group_id
             WHERE mg.meeting_id = :meeting_id
             ORDER BY l.name'
        );
        $labels->execute(['meeting_id' => (int) $meeting['id']]);
        $partnerRows = $partners->fetchAll();
        attachProfilePictureUrls($partnerRows);
        respond([
            'source' => 'groups',
            'partners' => $partnerRows,
            'labels' => $labels->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }
    $manual = array_map(fn($name) => ['name' => $name, 'unmatched' => true], manualPartnerNames($meeting['manualPartners'] ?? null));
    if ($search !== '') {
        $manual = array_values(array_filter($manual, fn($row) => stripos($row['name'], $search) !== false));
    }
    $total = count($manual);
    respond([
        'source' => 'manual',
        'partners' => array_slice($manual, $offset, $pageSize),
        'labels' => [],
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}

if ($method === 'GET' && $action === 'partner-detail') {
    requireLogin();
    $partnerId = (int) ($_GET['id'] ?? 0);
    $slug = trim((string) ($_GET['slug'] ?? ''));
    if ($partnerId <= 0 && $slug === '') respond(['error' => 'Partner is required.'], 422);
    $groupId = (int) ($_GET['groupId'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin",
                p.industry, p.company, p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                p.profile_picture_stored_name AS "profilePictureStoredName",
                COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels
         FROM partners p
         JOIN users profile_owner ON profile_owner.id = p.user_id
           OR (p.user_id IS NULL AND LOWER(TRIM(profile_owner.email)) = LOWER(TRIM(p.email)))
         LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
         LEFT JOIN partner_labels l ON l.id = ppl.label_id
         WHERE ((:partner_id > 0 AND p.id = :partner_id) OR (:partner_id = 0 AND p.slug = :slug))
           AND (
              :is_admin = 1
              OR profile_owner.id = :owner_user_id
              OR EXISTS (
                  SELECT 1
                  FROM group_members viewer_groups
                  JOIN group_members partner_groups ON partner_groups.group_id = viewer_groups.group_id
                  WHERE viewer_groups.user_id = :viewer_user_id
                    AND partner_groups.user_id = profile_owner.id
              )
              OR (
                  :has_group_context = 1
                  AND EXISTS (
                      SELECT 1
                      FROM group_members viewer_group
                      JOIN group_members partner_group ON partner_group.group_id = viewer_group.group_id
                      WHERE viewer_group.group_id = :checked_group_id
                        AND viewer_group.user_id = :group_viewer_user_id
                        AND partner_group.user_id = profile_owner.id
                  )
              )
           )
         GROUP BY p.id'
    );
    $statement->execute([
        'partner_id' => $partnerId,
        'slug' => $slug,
        'is_admin' => ($_SESSION['role'] ?? null) === 'admin' ? 1 : 0,
        'owner_user_id' => $userId,
        'viewer_user_id' => $userId,
        'has_group_context' => $groupId > 0 ? 1 : 0,
        'checked_group_id' => $groupId,
        'group_viewer_user_id' => $userId,
    ]);
    $partner = $statement->fetch() ?: null;
    attachProfilePictureUrl($partner);
    respond(['partner' => $partner]);
}

if ($method === 'GET' && $action === 'my-profile') {
    requireLogin();
    $statement = $pdo->prepare(
        'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin", p.industry, p.company,
                p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                p.profile_picture_stored_name AS "profilePictureStoredName",
                COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels,
                COALESCE(json_agg(DISTINCT l.id::text) FILTER (WHERE l.id IS NOT NULL), \'[]\'::json) AS "labelIds"
         FROM partners p
         LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
         LEFT JOIN partner_labels l ON l.id = ppl.label_id
         WHERE p.user_id = :user_id GROUP BY p.id'
    );
    $statement->execute(['user_id' => $userId]);
    $partner = $statement->fetch() ?: null;
    if ($partner) {
        $partner['labelIds'] = json_decode((string) $partner['labelIds'], true) ?: [];
        attachProfilePictureUrl($partner);
    }
    respond(['partner' => $partner]);
}

if ($method === 'POST' && $action === 'my-profile') {
    requireLogin();
    $body = requestBody();
    required($body, ['name']);
    $emailStatement = $pdo->prepare('SELECT email FROM users WHERE id = :id');
    $emailStatement->execute(['id' => $userId]);
    $loginEmail = $emailStatement->fetchColumn();
    if (!$loginEmail) {
        respond(['error' => 'Brugerens e-mail kunne ikke findes.'], 400);
    }
    $linkExisting = $pdo->prepare(
        'UPDATE partners AS partner
         SET user_id = :user_id
         WHERE partner.user_id IS NULL
           AND LOWER(TRIM(partner.email)) = LOWER(TRIM(:email))
           AND NOT EXISTS (
               SELECT 1 FROM partners AS existing WHERE existing.user_id = :existing_user_id
           )'
    );
    $linkExisting->execute(['user_id' => $userId, 'email' => $loginEmail, 'existing_user_id' => $userId]);
    $statement = $pdo->prepare(
        'INSERT INTO partners (user_id, slug, name, linkedin_url, industry, company, company_url, email, phone, biography)
         VALUES (:user_id, :slug, :name, :linkedin, :industry, :company, :company_url, :email, :phone, :biography)
         ON CONFLICT (user_id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name, linkedin_url = EXCLUDED.linkedin_url,
           industry = EXCLUDED.industry, company = EXCLUDED.company, company_url = EXCLUDED.company_url,
           email = EXCLUDED.email, phone = EXCLUDED.phone, biography = EXCLUDED.biography
         RETURNING id::text, slug'
    );
    $statement->execute([
        'user_id' => $userId, 'slug' => slugify((string) $body['name']) . '-' . $userId,
        'name' => trim((string) $body['name']), 'linkedin' => nullable($body, 'linkedin'),
        'industry' => nullable($body, 'industry'), 'company' => nullable($body, 'company'),
        'company_url' => nullable($body, 'companyUrl'), 'email' => $loginEmail,
        'phone' => nullable($body, 'phone'), 'biography' => nullable($body, 'biography'),
    ]);
    $partner = $statement->fetch();
    $pdo->prepare('DELETE FROM partner_profile_labels WHERE partner_id = :partner_id')->execute(['partner_id' => (int) $partner['id']]);
    $insertLabel = $pdo->prepare('INSERT INTO partner_profile_labels (partner_id, label_id) VALUES (:partner_id, :label_id) ON CONFLICT DO NOTHING');
    foreach (($body['labelIds'] ?? []) as $labelId) {
        $insertLabel->execute(['partner_id' => (int) $partner['id'], 'label_id' => (int) $labelId]);
    }
    respond(['partner' => $partner]);
}

if ($method === 'GET' && $action === 'admin-profile') {
    requireAdmin();
    $targetUserId = (int) ($_GET['userId'] ?? 0);
    if ($targetUserId <= 0) respond(['error' => 'Bruger er påkrævet.'], 422);
    $userStatement = $pdo->prepare('SELECT id::text, email FROM users WHERE id = :id');
    $userStatement->execute(['id' => $targetUserId]);
    $targetUser = $userStatement->fetch();
    if (!$targetUser) respond(['error' => 'Brugeren findes ikke.'], 404);
    $statement = $pdo->prepare(
        'SELECT p.id::text, p.slug, p.name, p.linkedin_url AS "linkedin", p.industry, p.company,
                p.company_url AS "companyUrl", p.email, p.phone, p.biography,
                p.profile_picture_stored_name AS "profilePictureStoredName",
                COALESCE(string_agg(DISTINCT l.name, \', \' ORDER BY l.name), \'\') AS labels,
                COALESCE(json_agg(DISTINCT l.id::text) FILTER (WHERE l.id IS NOT NULL), \'[]\'::json) AS "labelIds"
         FROM partners p
         LEFT JOIN partner_profile_labels ppl ON ppl.partner_id = p.id
         LEFT JOIN partner_labels l ON l.id = ppl.label_id
         WHERE p.user_id = :user_id GROUP BY p.id'
    );
    $statement->execute(['user_id' => $targetUserId]);
    $partner = $statement->fetch() ?: null;
    if ($partner) {
        $partner['labelIds'] = json_decode((string) $partner['labelIds'], true) ?: [];
        attachProfilePictureUrl($partner);
    }
    respond(['user' => $targetUser, 'partner' => $partner]);
}

if ($method === 'POST' && $action === 'admin-profile') {
    requireAdmin();
    $body = requestBody();
    required($body, ['userId', 'name']);
    $targetUserId = (int) $body['userId'];
    $emailStatement = $pdo->prepare('SELECT email FROM users WHERE id = :id');
    $emailStatement->execute(['id' => $targetUserId]);
    $loginEmail = $emailStatement->fetchColumn();
    if (!$loginEmail) respond(['error' => 'Brugeren findes ikke.'], 404);
    $linkExisting = $pdo->prepare(
        'UPDATE partners AS partner
         SET user_id = :user_id
         WHERE partner.user_id IS NULL
           AND LOWER(TRIM(partner.email)) = LOWER(TRIM(:email))
           AND NOT EXISTS (
               SELECT 1 FROM partners AS existing WHERE existing.user_id = :existing_user_id
           )'
    );
    $linkExisting->execute(['user_id' => $targetUserId, 'email' => $loginEmail, 'existing_user_id' => $targetUserId]);
    $statement = $pdo->prepare(
        'INSERT INTO partners (user_id, slug, name, linkedin_url, industry, company, company_url, email, phone, biography)
         VALUES (:user_id, :slug, :name, :linkedin, :industry, :company, :company_url, :email, :phone, :biography)
         ON CONFLICT (user_id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name, linkedin_url = EXCLUDED.linkedin_url,
           industry = EXCLUDED.industry, company = EXCLUDED.company, company_url = EXCLUDED.company_url,
           email = EXCLUDED.email, phone = EXCLUDED.phone, biography = EXCLUDED.biography
         RETURNING id::text, slug, profile_picture_stored_name AS "profilePictureStoredName"'
    );
    $statement->execute([
        'user_id' => $targetUserId,
        'slug' => slugify((string) $body['name']) . '-' . $targetUserId,
        'name' => trim((string) $body['name']),
        'linkedin' => nullable($body, 'linkedin'),
        'industry' => nullable($body, 'industry'),
        'company' => nullable($body, 'company'),
        'company_url' => nullable($body, 'companyUrl'),
        'email' => $loginEmail,
        'phone' => nullable($body, 'phone'),
        'biography' => nullable($body, 'biography'),
    ]);
    $partner = $statement->fetch();
    $pdo->prepare('DELETE FROM partner_profile_labels WHERE partner_id = :partner_id')->execute(['partner_id' => (int) $partner['id']]);
    $insertLabel = $pdo->prepare('INSERT INTO partner_profile_labels (partner_id, label_id) VALUES (:partner_id, :label_id) ON CONFLICT DO NOTHING');
    foreach (($body['labelIds'] ?? []) as $labelId) {
        $insertLabel->execute(['partner_id' => (int) $partner['id'], 'label_id' => (int) $labelId]);
    }
    attachProfilePictureUrl($partner);
    respond(['partner' => $partner]);
}

if ($method === 'GET' && $action === 'admin-users') {
    requireAdmin();
    $search = trim((string) ($_GET['search'] ?? ''));
    $role = (string) ($_GET['role'] ?? '');
    $groupId = (int) ($_GET['groupId'] ?? 0);
    $requestedUserId = (int) ($_GET['userId'] ?? 0);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(500, max(5, (int) ($_GET['pageSize'] ?? 10)));
    $offset = ($page - 1) * $pageSize;
    $where = [];
    $params = ['limit' => $pageSize, 'offset' => $offset];
    if ($search !== '') {
        $where[] = '(LOWER(u.email) LIKE LOWER(:search) OR LOWER(p.name) LIKE LOWER(:search) OR LOWER(p.company) LIKE LOWER(:search))';
        $params['search'] = '%' . $search . '%';
    }
    if ($role === 'admin' || $role === 'member') {
        $where[] = 'u.role = :role';
        $params['role'] = $role;
    }
    if ($groupId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM group_members gm_filter WHERE gm_filter.user_id = u.id AND gm_filter.group_id = :filter_group_id)';
        $params['filter_group_id'] = $groupId;
    }
    if ($requestedUserId > 0) {
        $where[] = 'u.id = :requested_user_id';
        $params['requested_user_id'] = $requestedUserId;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare(
        "SELECT COUNT(DISTINCT u.id)::int
         FROM users u
         LEFT JOIN partners p ON p.user_id = u.id
           OR (p.user_id IS NULL AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
         $whereSql"
    );
    foreach ($params as $key => $value) {
        if ($key !== 'limit' && $key !== 'offset') $count->bindValue(':' . $key, $value);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();
    $users = $pdo->prepare(
        'SELECT u.id::text, u.email, u.role,
                COALESCE(string_agg(DISTINCT g.name, \', \' ORDER BY g.name), \'\') AS groups,
                COALESCE(json_agg(DISTINCT gm.group_id::text) FILTER (WHERE gm.group_id IS NOT NULL), \'[]\'::json) AS "groupIds",
                p.id::text AS "profileId", p.slug AS "profileSlug", p.name AS "profileName"
         FROM users u
         LEFT JOIN group_members gm ON gm.user_id = u.id
         LEFT JOIN groups g ON g.id = gm.group_id
         LEFT JOIN partners p ON p.user_id = u.id
           OR (p.user_id IS NULL AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
         ' . $whereSql . '
         GROUP BY u.id, p.id
         ORDER BY u.email
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $users->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $users->execute();
    $users = $users->fetchAll();
    foreach ($users as &$user) {
        $user['groupIds'] = json_decode((string) $user['groupIds'], true) ?: [];
    }
    unset($user);
    respond(['users' => $users, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
}

if ($method === 'POST' && $action === 'admin-users') {
    requireAdmin();
    $body = requestBody();
    required($body, ['email', 'password']);
    $statement = $pdo->prepare(
        'INSERT INTO users (email, password_hash, role) VALUES (:email, :password_hash, :role)
         RETURNING id::text, email, role'
    );
    $statement->execute([
        'email' => strtolower(trim((string) $body['email'])),
        'password_hash' => password_hash((string) $body['password'], PASSWORD_DEFAULT),
        'role' => ($body['role'] ?? 'member') === 'admin' ? 'admin' : 'member',
    ]);
    $user = $statement->fetch();
    foreach (($body['groupIds'] ?? []) as $groupId) {
        $link = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id) ON CONFLICT DO NOTHING');
        $link->execute(['group_id' => (int) $groupId, 'user_id' => (int) $user['id']]);
    }
    respond(['user' => $user], 201);
}

if ($method === 'POST' && $action === 'admin-update-user') {
    requireAdmin();
    $body = requestBody();
    required($body, ['userId', 'email']);
    $targetUserId = (int) $body['userId'];
    $role = ($body['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    $password = trim((string) ($body['password'] ?? ''));
    if ($targetUserId === $userId && $role !== 'admin') {
        respond(['error' => 'Du kan ikke fjerne din egen administratorrolle.'], 422);
    }
    if ($password !== '' && strlen($password) < 10) {
        respond(['error' => 'Adgangskoden skal være mindst 10 tegn.'], 422);
    }
    $pdo->beginTransaction();
    try {
        if ($password !== '') {
            $statement = $pdo->prepare('UPDATE users SET email = :email, role = :role, password_hash = :password_hash WHERE id = :id RETURNING id::text, email, role');
            $statement->execute([
                'id' => $targetUserId,
                'email' => strtolower(trim((string) $body['email'])),
                'role' => $role,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } else {
            $statement = $pdo->prepare('UPDATE users SET email = :email, role = :role WHERE id = :id RETURNING id::text, email, role');
            $statement->execute([
                'id' => $targetUserId,
                'email' => strtolower(trim((string) $body['email'])),
                'role' => $role,
            ]);
        }
        $userRow = $statement->fetch();
        if (!$userRow) {
            $pdo->rollBack();
            respond(['error' => 'Brugeren findes ikke.'], 404);
        }
        $delete = $pdo->prepare('DELETE FROM group_members WHERE user_id = :user_id');
        $delete->execute(['user_id' => $targetUserId]);
        $insert = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id) ON CONFLICT DO NOTHING');
        foreach (($body['groupIds'] ?? []) as $groupId) {
            $insert->execute(['group_id' => (int) $groupId, 'user_id' => $targetUserId]);
        }
        $pdo->commit();
    } catch (PDOException $error) {
        $pdo->rollBack();
        if ($error->getCode() === '23505') respond(['error' => 'E-mailen er allerede i brug.'], 409);
        throw $error;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    respond(['user' => $userRow]);
}

if ($method === 'POST' && $action === 'admin-user-groups') {
    requireAdmin();
    $body = requestBody();
    required($body, ['userId']);
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM group_members WHERE user_id = :user_id');
        $delete->execute(['user_id' => (int) $body['userId']]);
        $insert = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id) ON CONFLICT DO NOTHING');
        foreach (($body['groupIds'] ?? []) as $groupId) {
            $insert->execute(['group_id' => (int) $groupId, 'user_id' => (int) $body['userId']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => 'Membership update failed.'], 500);
    }
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-delete-user') {
    requireAdmin();
    $body = requestBody();
    required($body, ['userId']);
    $targetUserId = (int) $body['userId'];
    if ($targetUserId === $userId) {
        respond(['error' => 'Du kan ikke slette din egen bruger.'], 422);
    }
    $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $statement->execute(['id' => $targetUserId]);
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-groups') {
    requireAdmin();
    $body = requestBody();
    required($body, ['name']);
    $statement = $pdo->prepare('INSERT INTO groups (name, address) VALUES (:name, :address) RETURNING id::text, name, address');
    $statement->execute(['name' => trim((string) $body['name']), 'address' => nullable($body, 'address')]);
    respond(['group' => $statement->fetch()], 201);
}

if ($method === 'POST' && $action === 'admin-update-group') {
    requireAdmin();
    $body = requestBody();
    required($body, ['groupId', 'name']);
    $statement = $pdo->prepare('UPDATE groups SET name = :name, address = :address WHERE id = :id RETURNING id::text, name, address');
    $statement->execute([
        'id' => (int) $body['groupId'],
        'name' => trim((string) $body['name']),
        'address' => nullable($body, 'address'),
    ]);
    $group = $statement->fetch();
    if (!$group) respond(['error' => 'Gruppen findes ikke.'], 404);
    respond(['group' => $group]);
}

if ($method === 'POST' && $action === 'admin-delete-group') {
    requireAdmin();
    $body = requestBody();
    required($body, ['groupId']);
    $statement = $pdo->prepare('DELETE FROM groups WHERE id = :id');
    $statement->execute(['id' => (int) $body['groupId']]);
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-labels') {
    requireAdmin();
    $body = requestBody();
    required($body, ['name']);
    $statement = $pdo->prepare('INSERT INTO partner_labels (name) VALUES (:name) RETURNING id::text, name');
    $statement->execute(['name' => trim((string) $body['name'])]);
    respond(['label' => $statement->fetch()], 201);
}

if ($method === 'POST' && $action === 'admin-delete-label') {
    requireAdmin();
    $body = requestBody();
    required($body, ['labelId']);
    $statement = $pdo->prepare('DELETE FROM partner_labels WHERE id = :label_id');
    $statement->execute(['label_id' => (int) $body['labelId']]);
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-meetings') {
    requireAdmin();
    $body = requestBody();
    required($body, ['title', 'date', 'time', 'address']);
    $groupIds = idList($body, 'groupIds', 'groupId');
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'INSERT INTO meetings (slug, title, meeting_date, meeting_time, address, status, group_id, partners_text, program_text, location_text, files_text, guests_text, invite_text, rsvp_approval_mode)
         VALUES (:slug, :title, :meeting_date, :meeting_time, :address, :status, :group_id, :partners, :program, :location, :files, :guests, :invite, :approval_mode)
         RETURNING id::text, slug'
    );
    $statement->execute([
        'slug' => slugify((string) $body['title']) . '-' . time(), 'title' => trim((string) $body['title']),
        'meeting_date' => $body['date'], 'meeting_time' => $body['time'], 'address' => trim((string) $body['address']),
        'status' => $body['status'] ?? 'upcoming', 'group_id' => $groupIds[0] ?? null,
        'partners' => nullable($body, 'partners'), 'program' => nullable($body, 'program'),
        'location' => nullable($body, 'location'), 'files' => nullable($body, 'files'),
        'guests' => nullable($body, 'guests'), 'invite' => nullable($body, 'invite'),
        'approval_mode' => ($body['approvalMode'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic',
    ]);
    $meeting = $statement->fetch();
    syncMeetingGroups($pdo, (int) $meeting['id'], $groupIds);
    $pdo->commit();
    respond(['meeting' => $meeting], 201);
}

if ($method === 'POST' && $action === 'admin-update-meeting') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId', 'title', 'date', 'time', 'address']);
    $groupIds = idList($body, 'groupIds', 'groupId');
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'UPDATE meetings SET title = :title, meeting_date = :meeting_date, meeting_time = :meeting_time,
             address = :address, status = :status, group_id = :group_id, partners_text = :partners,
             program_text = :program, location_text = :location, files_text = :files,
             guests_text = :guests, invite_text = :invite,
             rsvp_approval_mode = COALESCE(:approval_mode, rsvp_approval_mode)
         WHERE id = :id
         RETURNING id::text, slug'
    );
    $statement->execute([
        'id' => (int) $body['meetingId'],
        'title' => trim((string) $body['title']),
        'meeting_date' => $body['date'],
        'meeting_time' => $body['time'],
        'address' => trim((string) $body['address']),
        'status' => $body['status'] ?? 'upcoming',
        'group_id' => $groupIds[0] ?? null,
        'partners' => nullable($body, 'partners'),
        'program' => nullable($body, 'program'),
        'location' => nullable($body, 'location'),
        'files' => nullable($body, 'files'),
        'guests' => nullable($body, 'guests'),
        'invite' => nullable($body, 'invite'),
        'approval_mode' => array_key_exists('approvalMode', $body)
            ? ($body['approvalMode'] === 'manual' ? 'manual' : 'automatic')
            : null,
    ]);
    $meeting = $statement->fetch();
    if (!$meeting) {
        $pdo->rollBack();
        respond(['error' => 'Mødet findes ikke.'], 404);
    }
    syncMeetingGroups($pdo, (int) $meeting['id'], $groupIds);
    $pdo->commit();
    respond(['meeting' => $meeting]);
}

if ($method === 'POST' && $action === 'admin-delete-meeting') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId']);
    $attachmentNames = [];
    try {
        $attachments = $pdo->prepare('SELECT stored_name FROM meeting_attachments WHERE meeting_id = :id');
        $attachments->execute(['id' => (int) $body['meetingId']]);
        $attachmentNames = $attachments->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $exception) {
        // The attachment table may not exist until migration 008 has been applied.
    }
    $statement = $pdo->prepare('DELETE FROM meetings WHERE id = :id');
    $statement->execute(['id' => (int) $body['meetingId']]);
    $attachmentDirectory = (string) ($config['meeting_attachment_dir']
        ?? (dirname(__DIR__, 2) . '/portal-private/meeting-attachments'));
    foreach ($attachmentNames as $storedName) {
        $path = $attachmentDirectory . DIRECTORY_SEPARATOR . basename((string) $storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    respond(['ok' => true]);
}

require __DIR__ . '/features.php';

respond(['error' => 'Unknown endpoint.'], 404);
