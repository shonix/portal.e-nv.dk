CREATE TABLE IF NOT EXISTS meeting_guest_invitations (
  id BIGSERIAL PRIMARY KEY,
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  guest_id BIGINT REFERENCES meeting_guests(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  company TEXT,
  email TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TIMESTAMPTZ NOT NULL,
  invited_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  email_sent_at TIMESTAMPTZ,
  email_status TEXT NOT NULL DEFAULT 'pending' CHECK (email_status IN ('pending', 'sent', 'failed')),
  email_error TEXT,
  response TEXT CHECK (response IN ('attending', 'not_attending')),
  responded_at TIMESTAMPTZ,
  guest_created_by_invitation BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE UNIQUE INDEX IF NOT EXISTS meeting_guest_invitations_meeting_email_uidx
  ON meeting_guest_invitations (meeting_id, LOWER(email));

CREATE INDEX IF NOT EXISTS meeting_guest_invitations_meeting_idx
  ON meeting_guest_invitations (meeting_id, invited_at DESC);
