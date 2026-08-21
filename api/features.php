<?php
declare(strict_types=1);

function portalUrl(string $path): string
{
    global $config;
    return portalBaseUrl($config) . '/' . ltrim($path, '/');
}

function attachmentDirectory(array $config): string
{
    return (string) ($config['meeting_attachment_dir'] ?? (dirname(__DIR__, 2) . '/portal-private/meeting-attachments'));
}

function partnerMaterialDirectory(array $config): string
{
    return (string) ($config['partner_material_dir'] ?? (dirname(__DIR__, 2) . '/portal-private/partner-materials'));
}

function officeArchiveMatches(string $path, string $extension): bool
{
    if (!class_exists(ZipArchive::class)) return false;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;
    $required = $extension === 'docx'
        ? ['[Content_Types].xml', 'word/document.xml']
        : ['[Content_Types].xml', 'xl/workbook.xml'];
    foreach ($required as $entry) {
        if ($zip->locateName($entry) === false) {
            $zip->close();
            return false;
        }
    }
    $zip->close();
    return true;
}

function canAccessMeeting(PDO $pdo, int $meetingId, int $userId): bool
{
    if (($_SESSION['role'] ?? null) === 'admin') return true;
    $groupCount = $pdo->prepare('SELECT COUNT(*) FROM meeting_groups WHERE meeting_id = :meeting_id');
    $groupCount->execute(['meeting_id' => $meetingId]);
    if ((int) $groupCount->fetchColumn() === 0) return $userId > 0;
    $access = $pdo->prepare(
        'SELECT 1
         FROM meeting_groups mg
         JOIN group_members gm ON gm.group_id = mg.group_id
         WHERE mg.meeting_id = :meeting_id AND gm.user_id = :user_id'
    );
    $access->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);
    return (bool) $access->fetchColumn();
}

function canAccessGroup(PDO $pdo, int $groupId, int $userId): bool
{
    if (($_SESSION['role'] ?? null) === 'admin') return true;
    $access = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
    $access->execute(['group_id' => $groupId, 'user_id' => $userId]);
    return (bool) $access->fetchColumn();
}

function defaultPortalBannerSettings(): array
{
    return [
        'enabled' => true,
        'audience' => 'no_group',
        'title' => 'Velkommen til Ejendomsnetværkets Partnerportal',
        'message' => 'Når du har oprettet din brugerprofil, kan der gå op til 24 timer, før en administrator tilføjer dig til din gruppe.',
    ];
}

function portalBannerSettings(PDO $pdo): array
{
    $settings = defaultPortalBannerSettings();
    $statement = $pdo->query(
        "SELECT setting_key, setting_value
         FROM portal_settings
         WHERE setting_key IN ('banner_enabled', 'banner_audience', 'banner_title', 'banner_message')"
    );
    $values = [];
    foreach ($statement->fetchAll() as $row) {
        $values[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    $settings['enabled'] = ($values['banner_enabled'] ?? 'true') === 'true';
    $audience = $values['banner_audience'] ?? 'no_group';
    $settings['audience'] = in_array($audience, ['all', 'no_group'], true) ? $audience : 'no_group';
    $settings['title'] = $values['banner_title'] ?? $settings['title'];
    $settings['message'] = $values['banner_message'] ?? $settings['message'];
    return $settings;
}

if ($method === 'GET' && $action === 'portal-banner') {
    requireLogin();
    respond(['banner' => portalBannerSettings($pdo)]);
}

if ($method === 'GET' && $action === 'admin-banner-settings') {
    requireAdmin();
    respond(['banner' => portalBannerSettings($pdo)]);
}

if ($method === 'POST' && $action === 'admin-banner-settings') {
    requireAdmin();
    $body = requestBody();
    $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    $audience = (string) ($body['audience'] ?? '');
    $title = trim((string) ($body['title'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    if (!in_array($audience, ['all', 'no_group'], true)) {
        respond(['error' => 'Vælg hvem banneret skal vises for.'], 422);
    }
    if ($title === '' || strlen($title) > 180) {
        respond(['error' => 'Overskriften skal være mellem 1 og 180 tegn.'], 422);
    }
    if ($message === '' || strlen($message) > 1200) {
        respond(['error' => 'Beskeden skal være mellem 1 og 1200 tegn.'], 422);
    }
    $values = [
        'banner_enabled' => $enabled ? 'true' : 'false',
        'banner_audience' => $audience,
        'banner_title' => $title,
        'banner_message' => $message,
    ];
    $statement = $pdo->prepare(
        'INSERT INTO portal_settings (setting_key, setting_value, updated_at, updated_by)
         VALUES (:setting_key, :setting_value, NOW(), :updated_by)
         ON CONFLICT (setting_key) DO UPDATE
         SET setting_value = EXCLUDED.setting_value,
             updated_at = NOW(),
             updated_by = EXCLUDED.updated_by'
    );
    $pdo->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            $statement->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by' => (int) $_SESSION['user_id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    respond(['banner' => portalBannerSettings($pdo)]);
}

if ($method === 'GET' && $action === 'admin-group-detail') {
    requireAdmin();
    $groupId = (int) ($_GET['groupId'] ?? 0);
    $group = $pdo->prepare(
        'SELECT g.id::text, g.name, g.address, COUNT(gm.user_id)::int AS "memberCount"
         FROM groups g LEFT JOIN group_members gm ON gm.group_id = g.id
         WHERE g.id = :group_id GROUP BY g.id'
    );
    $group->execute(['group_id' => $groupId]);
    $members = $pdo->prepare(
        'SELECT u.id::text, u.email, u.role, p.id::text AS "profileId",
                p.name AS "profileName", p.company
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN partners p ON p.user_id = u.id
           OR (p.user_id IS NULL AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
         WHERE gm.group_id = :group_id
         ORDER BY COALESCE(p.name, u.email)'
    );
    $members->execute(['group_id' => $groupId]);
    respond(['group' => $group->fetch() ?: null, 'members' => $members->fetchAll()]);
}

if ($method === 'GET' && $action === 'group-bulletins') {
    requireLogin();
    $groupId = (int) ($_GET['groupId'] ?? 0);
    if ($groupId <= 0) respond(['error' => 'Group is required.'], 422);
    if (!canAccessGroup($pdo, $groupId, $userId)) respond(['error' => 'Du har ikke adgang til denne gruppe.'], 403);
    $statement = $pdo->prepare(
        'SELECT gb.id::text, gb.message, gb.created_at AS "createdAt",
                u.email AS "createdByEmail",
                (
                    SELECT p.name
                    FROM partners p
                    WHERE p.user_id = u.id
                       OR (p.user_id IS NULL AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
                    ORDER BY p.user_id NULLS LAST, p.id
                    LIMIT 1
                ) AS "createdByName"
         FROM group_bulletins gb
         LEFT JOIN users u ON u.id = gb.created_by
         WHERE gb.group_id = :group_id
         ORDER BY gb.created_at DESC, gb.id DESC'
    );
    $statement->execute(['group_id' => $groupId]);
    respond(['bulletins' => $statement->fetchAll()]);
}

if ($method === 'POST' && $action === 'admin-group-bulletins') {
    requireAdmin();
    $body = requestBody();
    required($body, ['groupId', 'message']);
    $message = trim((string) $body['message']);
    if (strlen($message) > 4000) respond(['error' => 'Beskeden er for lang.'], 422);
    $statement = $pdo->prepare(
        'INSERT INTO group_bulletins (group_id, created_by, message)
         VALUES (:group_id, :created_by, :message)
         RETURNING id::text, message, created_at AS "createdAt"'
    );
    $statement->execute([
        'group_id' => (int) $body['groupId'],
        'created_by' => $userId,
        'message' => $message,
    ]);
    respond(['bulletin' => $statement->fetch()], 201);
}

if ($method === 'POST' && $action === 'admin-delete-group-bulletin') {
    requireAdmin();
    $body = requestBody();
    required($body, ['bulletinId']);
    $statement = $pdo->prepare('DELETE FROM group_bulletins WHERE id = :id');
    $statement->execute(['id' => (int) $body['bulletinId']]);
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-group-member') {
    requireAdmin();
    $body = requestBody();
    required($body, ['groupId', 'userId', 'operation']);
    if ($body['operation'] === 'add') {
        $statement = $pdo->prepare(
            'INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)
             ON CONFLICT DO NOTHING'
        );
    } elseif ($body['operation'] === 'remove') {
        $statement = $pdo->prepare(
            'DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id'
        );
    } else {
        respond(['error' => 'Ugyldig gruppehandling.'], 422);
    }
    $statement->execute(['group_id' => (int) $body['groupId'], 'user_id' => (int) $body['userId']]);
    respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'admin-import-labels') {
    requireAdmin();
    $body = requestBody();
    $values = is_array($body['names'] ?? null) ? $body['names'] : [];
    $existingRows = $pdo->query('SELECT LOWER(TRIM(name)) FROM partner_labels')->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_fill_keys($existingRows, true);
    $seen = [];
    $result = ['created' => 0, 'skipped' => 0, 'duplicates' => 0, 'invalid' => 0];
    $insert = $pdo->prepare(
        'INSERT INTO partner_labels (name) VALUES (:name)
         ON CONFLICT DO NOTHING RETURNING id'
    );
    foreach ($values as $value) {
        $name = trim((string) $value);
        if ($name === '' || strlen($name) > 500) {
            $result['invalid']++;
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            $result['duplicates']++;
            continue;
        }
        $seen[$key] = true;
        if (isset($existing[$key])) {
            $result['skipped']++;
            continue;
        }
        $insert->execute(['name' => $name]);
        if ($insert->fetchColumn()) {
            $result['created']++;
            $existing[$key] = true;
        } else {
            $result['skipped']++;
        }
    }
    respond(['result' => $result]);
}

if ($method === 'GET' && $action === 'admin-meeting-invitations') {
    requireAdmin();
    $meetingId = (int) ($_GET['meetingId'] ?? 0);
    $meeting = $pdo->prepare(
        'SELECT id::text, title, invitation_token AS token,
                rsvp_approval_mode AS "approvalMode"
         FROM meetings WHERE id = :id'
    );
    $meeting->execute(['id' => $meetingId]);
    $row = $meeting->fetch();
    if (!$row) respond(['error' => 'Mødet findes ikke.'], 404);
    $recipients = $pdo->prepare(
        'SELECT u.id::text AS "userId", u.email, p.name, p.company,
                mir.invited_at::text AS "invitedAt", mir.email_sent_at::text AS "emailSentAt",
                mir.email_status AS "emailStatus", mir.email_error AS "emailError",
                mr.response, mr.approval_status AS "approvalStatus",
                mr.responded_at::text AS "respondedAt"
         FROM meeting_invitation_recipients mir
         JOIN users u ON u.id = mir.user_id
         LEFT JOIN partners p ON p.user_id = u.id
           OR (p.user_id IS NULL AND LOWER(TRIM(p.email)) = LOWER(TRIM(u.email)))
         LEFT JOIN meeting_rsvps mr ON mr.meeting_id = mir.meeting_id AND mr.user_id = mir.user_id
         WHERE mir.meeting_id = :meeting_id
         ORDER BY COALESCE(p.name, u.email)'
    );
    $recipients->execute(['meeting_id' => $meetingId]);
    $row['url'] = $row['token'] ? portalUrl('moede-invitation.html?token=' . urlencode($row['token'])) : null;
    respond(['meeting' => $row, 'recipients' => $recipients->fetchAll()]);
}

if ($method === 'POST' && $action === 'admin-meeting-settings') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId', 'approvalMode']);
    $mode = $body['approvalMode'] === 'manual' ? 'manual' : 'automatic';
    $statement = $pdo->prepare(
        'UPDATE meetings SET rsvp_approval_mode = :mode WHERE id = :id'
    );
    $statement->execute(['mode' => $mode, 'id' => (int) $body['meetingId']]);
    if ($mode === 'automatic') {
        $pdo->prepare(
            "UPDATE meeting_rsvps SET approval_status = 'confirmed', reviewed_at = NOW(), reviewed_by = :reviewed_by
             WHERE meeting_id = :meeting_id AND response = 'attending' AND approval_status = 'pending'"
        )->execute(['reviewed_by' => $userId, 'meeting_id' => (int) $body['meetingId']]);
    }
    respond(['approvalMode' => $mode]);
}

if ($method === 'POST' && $action === 'admin-send-meeting-invitations') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId']);
    $meetingId = (int) $body['meetingId'];
    $meetingQuery = $pdo->prepare(
        'SELECT id, title, meeting_date::text AS meeting_date,
                to_char(meeting_time, \'HH24:MI\') AS meeting_time, address, invitation_token
         FROM meetings WHERE id = :id'
    );
    $meetingQuery->execute(['id' => $meetingId]);
    $meeting = $meetingQuery->fetch();
    if (!$meeting) respond(['error' => 'Mødet findes ikke.'], 404);
    $token = (string) ($meeting['invitation_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE meetings SET invitation_token = :token WHERE id = :id')
            ->execute(['token' => $token, 'id' => $meetingId]);
    }
    $userIds = array_values(array_unique(array_map('intval', $body['userIds'] ?? [])));
    $groupIds = array_values(array_unique(array_map('intval', $body['groupIds'] ?? [])));
    if ($groupIds) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $groupUsers = $pdo->prepare("SELECT DISTINCT user_id FROM group_members WHERE group_id IN ($placeholders)");
        $groupUsers->execute($groupIds);
        $userIds = array_values(array_unique(array_merge($userIds, array_map('intval', $groupUsers->fetchAll(PDO::FETCH_COLUMN)))));
    }
    if (!$userIds) respond(['error' => 'Vælg mindst én modtager.'], 422);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $users = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders) ORDER BY email");
    $users->execute($userIds);
    $recipientRows = $users->fetchAll();
    $link = portalUrl('moede-invitation.html?token=' . urlencode($token));
    $from = (string) ($config['mail_from'] ?? 'noreply@e-nv.dk');
    $headers = implode("\r\n", [
        'From: Ejendomsnetværket <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);
    $upsert = $pdo->prepare(
        "INSERT INTO meeting_invitation_recipients
            (meeting_id, user_id, invited_at, email_sent_at, email_status, email_error)
         VALUES (:meeting_id, :user_id, NOW(), :sent_at, :status, :error)
         ON CONFLICT (meeting_id, user_id) DO UPDATE SET
            invited_at = NOW(), email_sent_at = EXCLUDED.email_sent_at,
            email_status = EXCLUDED.email_status, email_error = EXCLUDED.email_error"
    );
    $results = [];
    foreach ($recipientRows as $recipient) {
        $message = "Hej\n\nDu er inviteret til " . $meeting['title'] . ".\n\n" .
            "Dato: " . $meeting['meeting_date'] . " kl. " . $meeting['meeting_time'] . "\n" .
            "Adresse: " . $meeting['address'] . "\n\nSvar på invitationen her:\n" . $link .
            "\n\nVenlig hilsen\nEjendomsnetværket";
        $sent = mail((string) $recipient['email'], 'Invitation: ' . $meeting['title'], $message, $headers);
        $error = $sent ? null : 'E-mailen kunne ikke sendes af serveren.';
        $upsert->execute([
            'meeting_id' => $meetingId,
            'user_id' => (int) $recipient['id'],
            'sent_at' => $sent ? date('c') : null,
            'status' => $sent ? 'sent' : 'failed',
            'error' => $error,
        ]);
        $results[] = ['userId' => (string) $recipient['id'], 'email' => $recipient['email'], 'sent' => $sent, 'error' => $error];
    }
    respond(['url' => $link, 'results' => $results]);
}

if ($method === 'POST' && $action === 'admin-rotate-meeting-invitation') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId']);
    $token = bin2hex(random_bytes(24));
    $statement = $pdo->prepare('UPDATE meetings SET invitation_token = :token WHERE id = :id');
    $statement->execute(['token' => $token, 'id' => (int) $body['meetingId']]);
    respond(['token' => $token, 'url' => portalUrl('moede-invitation.html?token=' . urlencode($token))]);
}

if ($method === 'GET' && $action === 'meeting-invitation') {
    requireLogin();
    $token = trim((string) ($_GET['token'] ?? ''));
    $statement = $pdo->prepare(
        'SELECT m.id::text, m.title, m.meeting_date::text AS "date",
                to_char(m.meeting_time, \'HH24:MI\') AS "time", m.address, m.status,
                m.program_text AS "program", m.location_text AS "location",
                m.rsvp_approval_mode AS "approvalMode",
                mr.response, mr.approval_status AS "approvalStatus"
         FROM meetings m
         LEFT JOIN meeting_rsvps mr ON mr.meeting_id = m.id AND mr.user_id = :user_id
         WHERE m.invitation_token = :token
           AND (:is_admin = 1 OR EXISTS (
               SELECT 1 FROM meeting_invitation_recipients mir
               WHERE mir.meeting_id = m.id AND mir.user_id = :recipient_user_id
           ))'
    );
    $statement->execute([
        'user_id' => $userId,
        'token' => $token,
        'is_admin' => ($_SESSION['role'] ?? null) === 'admin' ? 1 : 0,
        'recipient_user_id' => $userId,
    ]);
    $meeting = $statement->fetch();
    if (!$meeting) respond(['error' => 'Invitationen findes ikke, eller du er ikke inviteret.'], 404);
    respond(['meeting' => $meeting]);
}

if ($method === 'POST' && $action === 'meeting-rsvp') {
    requireLogin();
    $body = requestBody();
    required($body, ['token', 'response']);
    if (!in_array($body['response'], ['attending', 'not_attending'], true)) {
        respond(['error' => 'Ugyldigt svar.'], 422);
    }
    $meetingQuery = $pdo->prepare(
        'SELECT m.id, m.status, m.rsvp_approval_mode
         FROM meetings m
         JOIN meeting_invitation_recipients mir ON mir.meeting_id = m.id
         WHERE m.invitation_token = :token AND mir.user_id = :user_id'
    );
    $meetingQuery->execute(['token' => (string) $body['token'], 'user_id' => $userId]);
    $meeting = $meetingQuery->fetch();
    if (!$meeting) respond(['error' => 'Invitationen findes ikke, eller du er ikke inviteret.'], 404);
    if ($meeting['status'] === 'cancelled') respond(['error' => 'Mødet er aflyst.'], 422);
    $approval = $body['response'] === 'not_attending'
        ? 'declined'
        : ($meeting['rsvp_approval_mode'] === 'manual' ? 'pending' : 'confirmed');
    $statement = $pdo->prepare(
        'INSERT INTO meeting_rsvps (meeting_id, user_id, response, approval_status, responded_at)
         VALUES (:meeting_id, :user_id, :response, :approval_status, NOW())
         ON CONFLICT (meeting_id, user_id) DO UPDATE SET
            response = EXCLUDED.response, approval_status = EXCLUDED.approval_status,
            responded_at = NOW(), reviewed_at = NULL, reviewed_by = NULL'
    );
    $statement->execute([
        'meeting_id' => (int) $meeting['id'],
        'user_id' => $userId,
        'response' => $body['response'],
        'approval_status' => $approval,
    ]);
    respond(['response' => $body['response'], 'approvalStatus' => $approval]);
}

if ($method === 'POST' && $action === 'admin-review-rsvp') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId', 'userId', 'approvalStatus']);
    if (!in_array($body['approvalStatus'], ['confirmed', 'declined'], true)) {
        respond(['error' => 'Ugyldig godkendelsesstatus.'], 422);
    }
    $statement = $pdo->prepare(
        'UPDATE meeting_rsvps SET approval_status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by
         WHERE meeting_id = :meeting_id AND user_id = :user_id AND response = \'attending\''
    );
    $statement->execute([
        'status' => $body['approvalStatus'],
        'reviewed_by' => $userId,
        'meeting_id' => (int) $body['meetingId'],
        'user_id' => (int) $body['userId'],
    ]);
    respond(['ok' => true]);
}

if ($method === 'GET' && $action === 'admin-meeting-attendance') {
    requireAdmin();
    $meetingId = (int) ($_GET['meetingId'] ?? 0);
    if ($meetingId <= 0) respond(['error' => 'Møde er påkrævet.'], 422);

    $meeting = $pdo->prepare('SELECT id::text, title FROM meetings WHERE id = :id');
    $meeting->execute(['id' => $meetingId]);
    $meetingRow = $meeting->fetch();
    if (!$meetingRow) respond(['error' => 'Mødet findes ikke.'], 404);

    $attendees = $pdo->prepare(
        "SELECT 'user' AS \"attendeeType\", u.id::text AS \"attendeeId\",
                COALESCE(NULLIF(TRIM(p.name), ''), u.email) AS name,
                COALESCE(p.company, '') AS company, u.email,
                mr.approval_status AS \"approvalStatus\",
                CASE WHEN mr.attended_at IS NOT NULL THEN 1 ELSE 0 END AS attended,
                mr.attended_at::text AS \"attendedAt\"
         FROM meeting_rsvps mr
         JOIN users u ON u.id = mr.user_id
         LEFT JOIN LATERAL (
             SELECT candidate.name, candidate.company
             FROM partners candidate
             WHERE candidate.user_id = u.id
                OR (candidate.user_id IS NULL AND LOWER(TRIM(candidate.email)) = LOWER(TRIM(u.email)))
             ORDER BY candidate.user_id NULLS LAST, candidate.id
             LIMIT 1
         ) p ON TRUE
         WHERE mr.meeting_id = :user_meeting_id
           AND (mr.response = 'attending' OR mr.attended_at IS NOT NULL)
           AND NOT EXISTS (
               SELECT 1 FROM meeting_guests self_guest
               WHERE self_guest.meeting_id = mr.meeting_id
                 AND self_guest.registered_user_id = mr.user_id
           )
         UNION ALL
         SELECT 'guest' AS \"attendeeType\", mg.id::text AS \"attendeeId\",
                mg.name, mg.company, mg.email, NULL AS \"approvalStatus\",
                CASE WHEN mg.attended_at IS NOT NULL THEN 1 ELSE 0 END AS attended,
                mg.attended_at::text AS \"attendedAt\"
         FROM meeting_guests mg
         WHERE mg.meeting_id = :guest_meeting_id
         ORDER BY name, email"
    );
    $attendees->execute([
        'user_meeting_id' => $meetingId,
        'guest_meeting_id' => $meetingId,
    ]);
    $rows = $attendees->fetchAll();
    foreach ($rows as &$row) $row['attended'] = (bool) $row['attended'];
    unset($row);
    $attendedCount = count(array_filter($rows, fn(array $row): bool => (bool) $row['attended']));
    respond([
        'meeting' => $meetingRow,
        'attendees' => $rows,
        'summary' => ['attended' => $attendedCount, 'total' => count($rows)],
    ]);
}

if ($method === 'POST' && $action === 'admin-meeting-attendance') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId', 'attendeeType', 'attendeeId']);
    if (!array_key_exists('attended', $body) || !is_bool($body['attended'])) {
        respond(['error' => 'Fremmødestatus skal være sand eller falsk.'], 422);
    }

    $meetingId = (int) $body['meetingId'];
    $attendeeId = (int) $body['attendeeId'];
    $attendeeType = (string) $body['attendeeType'];
    $attended = $body['attended'] ? 1 : 0;
    if ($meetingId <= 0 || $attendeeId <= 0 || !in_array($attendeeType, ['user', 'guest'], true)) {
        respond(['error' => 'Ugyldig deltager.'], 422);
    }

    if ($attendeeType === 'user') {
        $update = $pdo->prepare(
            'UPDATE meeting_rsvps
             SET attended_at = CASE WHEN :attended = 1 THEN NOW() ELSE NULL END,
                 attendance_marked_by = CASE WHEN :marked = 1 THEN CAST(:admin_id AS BIGINT) ELSE NULL::BIGINT END
             WHERE meeting_id = :meeting_id AND user_id = :attendee_id
               AND (:may_uncheck = 0 OR response = \'attending\')
             RETURNING attended_at::text AS "attendedAt"'
        );
    } else {
        $update = $pdo->prepare(
            'UPDATE meeting_guests
             SET attended_at = CASE WHEN :attended = 1 THEN NOW() ELSE NULL END,
                 attendance_marked_by = CASE WHEN :marked = 1 THEN CAST(:admin_id AS BIGINT) ELSE NULL::BIGINT END
             WHERE meeting_id = :meeting_id AND id = :attendee_id
             RETURNING attended_at::text AS "attendedAt"'
        );
    }
    $parameters = [
        'attended' => $attended,
        'marked' => $attended,
        'admin_id' => $userId,
        'meeting_id' => $meetingId,
        'attendee_id' => $attendeeId,
    ];
    if ($attendeeType === 'user') $parameters['may_uncheck'] = $attended;
    $update->execute($parameters);
    $result = $update->fetch();
    if (!$result) respond(['error' => 'Deltageren findes ikke på dette møde.'], 404);
    respond([
        'attendeeType' => $attendeeType,
        'attendeeId' => (string) $attendeeId,
        'attended' => (bool) $body['attended'],
        'attendedAt' => $result['attendedAt'],
    ]);
}

if ($method === 'POST' && $action === 'admin-import-meeting-guests') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId']);

    $meetingId = (int) $body['meetingId'];
    $guests = $body['guests'] ?? null;
    if ($meetingId <= 0 || !is_array($guests) || count($guests) === 0) {
        respond(['error' => 'Vælg et møde og mindst én gæst.'], 422);
    }
    if (count($guests) > 500) {
        respond(['error' => 'Der kan højst importeres 500 gæster ad gangen.'], 422);
    }
    $meeting = $pdo->prepare('SELECT id FROM meetings WHERE id = :meeting_id');
    $meeting->execute(['meeting_id' => $meetingId]);
    if (!$meeting->fetch()) respond(['error' => 'Mødet findes ikke.'], 404);

    $validated = [];
    foreach ($guests as $index => $guest) {
        if (!is_array($guest)) {
            respond(['error' => 'Række ' . ($index + 2) . ' har et ugyldigt format.'], 422);
        }
        $name = trim((string) ($guest['name'] ?? ''));
        $company = trim((string) ($guest['company'] ?? ''));
        $email = strtolower(trim((string) ($guest['email'] ?? '')));
        if ($name === '' || $company === '' || $email === '') {
            respond(['error' => 'Række ' . ($index + 2) . ' mangler navn, firmanavn eller e-mail.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Række ' . ($index + 2) . ' har en ugyldig e-mail.'], 422);
        }
        $validated[] = ['name' => $name, 'company' => $company, 'email' => $email];
    }

    $insert = $pdo->prepare(
        'INSERT INTO meeting_guests (meeting_id, added_by, name, company, email)
         VALUES (:meeting_id, :added_by, :name, :company, :email)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($validated as $guest) {
            $insert->execute([
                'meeting_id' => $meetingId,
                'added_by' => $userId,
                'name' => $guest['name'],
                'company' => $guest['company'],
                'email' => $guest['email'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    respond(['imported' => count($validated)], 201);
}

if ($method === 'POST' && $action === 'admin-remove-meeting-guest') {
    requireAdmin();
    $body = requestBody();
    required($body, ['meetingId', 'guestId']);

    $meetingId = (int) $body['meetingId'];
    $guestId = (int) $body['guestId'];
    if ($meetingId <= 0 || $guestId <= 0) {
        respond(['error' => 'Ugyldig gæst.'], 422);
    }

    $delete = $pdo->prepare(
        'DELETE FROM meeting_guests
         WHERE meeting_id = :meeting_id AND id = :guest_id
         RETURNING id::text'
    );
    $delete->execute(['meeting_id' => $meetingId, 'guest_id' => $guestId]);
    if (!$delete->fetch()) respond(['error' => 'Gæsten findes ikke på dette møde.'], 404);

    respond([
        'ok' => true,
        'guestId' => (string) $guestId,
    ]);
}

if ($method === 'GET' && $action === 'admin-meeting-attachments') {
    requireAdmin();
    $meetingId = (int) ($_GET['meetingId'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT id::text, original_name AS "name", mime_type AS "mimeType",
                file_size::int AS "size", created_at::text AS "createdAt"
         FROM meeting_attachments WHERE meeting_id = :meeting_id ORDER BY created_at DESC'
    );
    $statement->execute(['meeting_id' => $meetingId]);
    respond(['attachments' => $statement->fetchAll()]);
}

if ($method === 'GET' && $action === 'meeting-attachments') {
    requireLogin();
    $meetingId = (int) ($_GET['meetingId'] ?? 0);
    if ($meetingId <= 0) respond(['error' => 'Møde er påkrævet.'], 422);
    if (!canAccessMeeting($pdo, $meetingId, $userId)) respond(['error' => 'Du har ikke adgang til mødets filer.'], 403);
    $statement = $pdo->prepare(
        'SELECT id::text, original_name AS "name", mime_type AS "mimeType",
                file_size::int AS "size", created_at::text AS "createdAt"
         FROM meeting_attachments WHERE meeting_id = :meeting_id ORDER BY created_at DESC'
    );
    $statement->execute(['meeting_id' => $meetingId]);
    respond(['attachments' => $statement->fetchAll()]);
}

if ($method === 'POST' && $action === 'admin-upload-attachment') {
    requireAdmin();
    $meetingId = (int) ($_POST['meetingId'] ?? 0);
    $file = $_FILES['file'] ?? null;
    if ($meetingId <= 0 || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['error' => 'Vælg et møde og en fil.'], 422);
    }
    $maxSize = (int) ($config['meeting_attachment_max_bytes'] ?? 10 * 1024 * 1024);
    if ((int) $file['size'] > $maxSize) respond(['error' => 'Filen er større end 10 MB.'], 422);
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    if (!isset($allowed[$extension])) respond(['error' => 'Filtypen er ikke tilladt.'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, $allowed[$extension], true)) respond(['error' => 'Filens indhold matcher ikke filtypen.'], 422);
    if (in_array($extension, ['docx', 'xlsx'], true) && !officeArchiveMatches((string) $file['tmp_name'], $extension)) {
        respond(['error' => 'Office-filen har ikke den forventede struktur.'], 422);
    }
    $meetingExists = $pdo->prepare('SELECT 1 FROM meetings WHERE id = :id');
    $meetingExists->execute(['id' => $meetingId]);
    if (!$meetingExists->fetchColumn()) respond(['error' => 'Mødet findes ikke.'], 404);
    $directory = attachmentDirectory($config);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        respond(['error' => 'Uploadmappen kunne ikke oprettes.'], 500);
    }
    $directory = realpath($directory) ?: '';
    if ($directory === '') respond(['error' => 'Uploadmappen kunne ikke valideres.'], 500);
    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        respond(['error' => 'Filen kunne ikke gemmes.'], 500);
    }
    $statement = $pdo->prepare(
        'INSERT INTO meeting_attachments
            (meeting_id, original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES (:meeting_id, :original_name, :stored_name, :mime_type, :file_size, :uploaded_by)
         RETURNING id::text'
    );
    try {
        $statement->execute([
            'meeting_id' => $meetingId,
            'original_name' => basename((string) $file['name']),
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'file_size' => (int) $file['size'],
            'uploaded_by' => $userId,
        ]);
    } catch (Throwable $exception) {
        @unlink($destination);
        throw $exception;
    }
    respond(['attachmentId' => $statement->fetchColumn()], 201);
}

if ($method === 'POST' && $action === 'admin-delete-attachment') {
    requireAdmin();
    $body = requestBody();
    required($body, ['attachmentId']);
    $statement = $pdo->prepare('DELETE FROM meeting_attachments WHERE id = :id RETURNING stored_name');
    $statement->execute(['id' => (int) $body['attachmentId']]);
    $storedName = $statement->fetchColumn();
    if ($storedName) {
        $path = attachmentDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $storedName);
        if (is_file($path)) @unlink($path);
    }
    respond(['ok' => true]);
}

if ($method === 'GET' && $action === 'admin-download-attachment') {
    requireAdmin();
    $attachmentId = (int) ($_GET['attachmentId'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT original_name, stored_name, mime_type, file_size FROM meeting_attachments WHERE id = :id'
    );
    $statement->execute(['id' => $attachmentId]);
    $attachment = $statement->fetch();
    if (!$attachment) respond(['error' => 'Filen findes ikke.'], 404);
    $path = attachmentDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $attachment['stored_name']);
    if (!is_file($path)) respond(['error' => 'Filen mangler på serveren.'], 404);
    header_remove('Content-Type');
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . (string) $attachment['file_size']);
    $disposition = ($_GET['preview'] ?? '') === '1' ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes((string) $attachment['original_name'], '"\\') . '"');
    session_write_close();
    readfile($path);
    exit;
}

if ($method === 'GET' && $action === 'meeting-download-attachment') {
    requireLogin();
    $attachmentId = (int) ($_GET['attachmentId'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT meeting_id, original_name, stored_name, mime_type, file_size
         FROM meeting_attachments WHERE id = :id'
    );
    $statement->execute(['id' => $attachmentId]);
    $attachment = $statement->fetch();
    if (!$attachment) respond(['error' => 'Filen findes ikke.'], 404);
    if (!canAccessMeeting($pdo, (int) $attachment['meeting_id'], $userId)) {
        respond(['error' => 'Du har ikke adgang til denne fil.'], 403);
    }
    $path = attachmentDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $attachment['stored_name']);
    if (!is_file($path)) respond(['error' => 'Filen mangler på serveren.'], 404);
    header_remove('Content-Type');
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . (string) $attachment['file_size']);
    $disposition = ($_GET['preview'] ?? '') === '1' ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes((string) $attachment['original_name'], '"\\') . '"');
    session_write_close();
    readfile($path);
    exit;
}

if ($method === 'GET' && $action === 'partner-materials') {
    requireLogin();
    $statement = $pdo->query(
        'SELECT id::text, title, description, category,
                original_name AS "originalName", mime_type AS "mimeType",
                file_size::int AS "size", created_at::text AS "createdAt"
         FROM partner_materials
         ORDER BY created_at DESC, id DESC'
    );
    respond(['materials' => $statement->fetchAll()]);
}

if ($method === 'POST' && $action === 'admin-upload-partner-material') {
    requireAdmin();
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $file = $_FILES['file'] ?? null;
    $categories = ['logo', 'email_signature', 'web', 'flyer', 'other'];

    if ($title === '' || strlen($title) > 160) {
        respond(['error' => 'Titlen skal være mellem 1 og 160 tegn.'], 422);
    }
    if (strlen($description) > 1000) {
        respond(['error' => 'Beskrivelsen må højst være 1000 tegn.'], 422);
    }
    if (!in_array($category, $categories, true)) {
        respond(['error' => 'Vælg en gyldig kategori.'], 422);
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['error' => 'Vælg en fil.'], 422);
    }

    $maxSize = (int) ($config['partner_material_max_bytes'] ?? 25 * 1024 * 1024);
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxSize) {
        respond(['error' => 'Filen er tom eller større end 25 MB.'], 422);
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'eps' => ['application/postscript', 'application/octet-stream'],
        'ai' => ['application/pdf', 'application/postscript', 'application/octet-stream'],
    ];
    if (!isset($allowed[$extension])) {
        respond(['error' => 'Filtypen er ikke tilladt. Brug PDF, JPG, PNG, WebP, SVG, ZIP, EPS eller AI.'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, $allowed[$extension], true)) {
        respond(['error' => 'Filens indhold matcher ikke filtypen.'], 422);
    }

    $directory = partnerMaterialDirectory($config);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        respond(['error' => 'Uploadmappen kunne ikke oprettes.'], 500);
    }
    $directory = realpath($directory) ?: '';
    if ($directory === '') respond(['error' => 'Uploadmappen kunne ikke valideres.'], 500);

    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        respond(['error' => 'Filen kunne ikke gemmes.'], 500);
    }

    $statement = $pdo->prepare(
        'INSERT INTO partner_materials
            (title, description, category, original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES
            (:title, :description, :category, :original_name, :stored_name, :mime_type, :file_size, :uploaded_by)
         RETURNING id::text'
    );
    try {
        $statement->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category' => $category,
            'original_name' => basename((string) $file['name']),
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'file_size' => (int) $file['size'],
            'uploaded_by' => $userId,
        ]);
    } catch (Throwable $exception) {
        @unlink($destination);
        throw $exception;
    }
    respond(['materialId' => $statement->fetchColumn()], 201);
}

if ($method === 'POST' && $action === 'admin-delete-partner-material') {
    requireAdmin();
    $body = requestBody();
    required($body, ['materialId']);
    $statement = $pdo->prepare('DELETE FROM partner_materials WHERE id = :id RETURNING stored_name');
    $statement->execute(['id' => (int) $body['materialId']]);
    $storedName = $statement->fetchColumn();
    if (!$storedName) respond(['error' => 'Materialet findes ikke.'], 404);

    $path = partnerMaterialDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $storedName);
    if (is_file($path)) @unlink($path);
    respond(['ok' => true]);
}

if ($method === 'GET' && $action === 'partner-material-download') {
    requireLogin();
    $materialId = (int) ($_GET['materialId'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT original_name, stored_name, mime_type, file_size
         FROM partner_materials WHERE id = :id'
    );
    $statement->execute(['id' => $materialId]);
    $material = $statement->fetch();
    if (!$material) respond(['error' => 'Materialet findes ikke.'], 404);

    $path = partnerMaterialDirectory($config) . DIRECTORY_SEPARATOR . basename((string) $material['stored_name']);
    if (!is_file($path)) respond(['error' => 'Filen mangler på serveren.'], 404);

    $downloadName = preg_replace('/[\r\n"]+/', '_', basename((string) $material['original_name'])) ?: 'materiale';
    $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $downloadName) ?: 'materiale';
    header_remove('Content-Type');
    header('Content-Type: ' . $material['mime_type']);
    header('Content-Length: ' . (string) $material['file_size']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . addcslashes($asciiName, '"\\')
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    session_write_close();
    readfile($path);
    exit;
}
