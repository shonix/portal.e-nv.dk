ALTER TABLE meetings
    ADD COLUMN IF NOT EXISTS invitation_token TEXT UNIQUE,
    ADD COLUMN IF NOT EXISTS rsvp_approval_mode TEXT NOT NULL DEFAULT 'automatic'
        CHECK (rsvp_approval_mode IN ('automatic', 'manual'));

CREATE TABLE IF NOT EXISTS meeting_invitation_recipients (
    meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    invited_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    email_sent_at TIMESTAMPTZ,
    email_status TEXT NOT NULL DEFAULT 'pending'
        CHECK (email_status IN ('pending', 'sent', 'failed')),
    email_error TEXT,
    PRIMARY KEY (meeting_id, user_id)
);

CREATE TABLE IF NOT EXISTS meeting_rsvps (
    meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    response TEXT NOT NULL CHECK (response IN ('attending', 'not_attending')),
    approval_status TEXT NOT NULL
        CHECK (approval_status IN ('pending', 'confirmed', 'declined')),
    responded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    reviewed_at TIMESTAMPTZ,
    reviewed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    PRIMARY KEY (meeting_id, user_id)
);

CREATE TABLE IF NOT EXISTS meeting_attachments (
    id BIGSERIAL PRIMARY KEY,
    meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    file_size BIGINT NOT NULL,
    uploaded_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS meeting_invitation_recipients_user_idx
    ON meeting_invitation_recipients (user_id);
CREATE INDEX IF NOT EXISTS meeting_rsvps_user_idx
    ON meeting_rsvps (user_id);
CREATE INDEX IF NOT EXISTS meeting_attachments_meeting_idx
    ON meeting_attachments (meeting_id);

WITH ranked AS (
    SELECT id, LOWER(TRIM(name)) AS normalized_name,
           MIN(id) OVER (PARTITION BY LOWER(TRIM(name))) AS keep_id
    FROM partner_labels
),
duplicates AS (
    SELECT id, keep_id FROM ranked WHERE id <> keep_id
)
INSERT INTO partner_profile_labels (partner_id, label_id)
SELECT ppl.partner_id, duplicates.keep_id
FROM partner_profile_labels ppl
JOIN duplicates ON duplicates.id = ppl.label_id
ON CONFLICT DO NOTHING;

WITH ranked AS (
    SELECT id, MIN(id) OVER (PARTITION BY LOWER(TRIM(name))) AS keep_id
    FROM partner_labels
)
DELETE FROM partner_labels
WHERE id IN (SELECT id FROM ranked WHERE id <> keep_id);

CREATE UNIQUE INDEX IF NOT EXISTS partner_labels_name_lower_uidx
    ON partner_labels (LOWER(TRIM(name)));
