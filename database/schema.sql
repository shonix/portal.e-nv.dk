CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('member', 'admin')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS partners (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  linkedin_url TEXT,
  industry TEXT,
  company TEXT,
  company_url TEXT,
  email TEXT NOT NULL,
  phone TEXT,
  biography TEXT,
  profile_picture_stored_name TEXT UNIQUE,
  profile_picture_mime_type TEXT,
  profile_picture_size BIGINT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS meetings (
  id BIGSERIAL PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  meeting_date DATE NOT NULL,
  meeting_time TIME NOT NULL,
  address TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'held', 'cancelled')),
  partners_text TEXT,
  program_text TEXT,
  location_text TEXT,
  files_text TEXT,
  guests_text TEXT,
  invite_text TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS groups (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  address TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS group_members (
  group_id BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, user_id)
);

CREATE TABLE IF NOT EXISTS group_bulletins (
  id BIGSERIAL PRIMARY KEY,
  group_id BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS portal_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO portal_settings (setting_key, setting_value)
VALUES
  ('banner_enabled', 'true'),
  ('banner_audience', 'no_group'),
  ('banner_title', 'Velkommen til Ejendomsnetværkets Partnerportal'),
  ('banner_message', 'Når du har oprettet din brugerprofil, kan der gå op til 24 timer, før en administrator tilføjer dig til din gruppe.')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS meeting_groups (
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  group_id BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  PRIMARY KEY (meeting_id, group_id)
);

ALTER TABLE partners ADD COLUMN IF NOT EXISTS user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS profile_picture_stored_name TEXT UNIQUE,
  ADD COLUMN IF NOT EXISTS profile_picture_mime_type TEXT,
  ADD COLUMN IF NOT EXISTS profile_picture_size BIGINT;
ALTER TABLE meetings ADD COLUMN IF NOT EXISTS group_id BIGINT REFERENCES groups(id) ON DELETE SET NULL;
INSERT INTO meeting_groups (meeting_id, group_id)
SELECT id, group_id FROM meetings WHERE group_id IS NOT NULL
ON CONFLICT DO NOTHING;
CREATE INDEX IF NOT EXISTS partners_user_id_idx ON partners (user_id);
CREATE INDEX IF NOT EXISTS group_members_user_id_idx ON group_members (user_id);
CREATE INDEX IF NOT EXISTS group_members_group_id_idx ON group_members (group_id);
CREATE INDEX IF NOT EXISTS group_bulletins_group_idx ON group_bulletins (group_id, created_at DESC);
CREATE INDEX IF NOT EXISTS meeting_groups_group_id_idx ON meeting_groups (group_id);

CREATE TABLE IF NOT EXISTS partner_labels (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS partner_profile_labels (
  partner_id BIGINT NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  label_id BIGINT NOT NULL REFERENCES partner_labels(id) ON DELETE CASCADE,
  PRIMARY KEY (partner_id, label_id)
);

CREATE TABLE IF NOT EXISTS invitations (
  id BIGSERIAL PRIMARY KEY,
  email TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  admin_token TEXT,
  expires_at TIMESTAMPTZ NOT NULL,
  used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS invitations_email_idx ON invitations (email);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS password_reset_tokens_user_idx
  ON password_reset_tokens (user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS password_reset_tokens_expiry_idx
  ON password_reset_tokens (expires_at)
  WHERE used_at IS NULL;

ALTER TABLE meetings
  ADD COLUMN IF NOT EXISTS invitation_token TEXT UNIQUE,
  ADD COLUMN IF NOT EXISTS rsvp_approval_mode TEXT NOT NULL DEFAULT 'automatic'
    CHECK (rsvp_approval_mode IN ('automatic', 'manual'));

CREATE TABLE IF NOT EXISTS meeting_invitation_recipients (
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  invited_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  email_sent_at TIMESTAMPTZ,
  email_status TEXT NOT NULL DEFAULT 'pending' CHECK (email_status IN ('pending', 'sent', 'failed')),
  email_error TEXT,
  PRIMARY KEY (meeting_id, user_id)
);

CREATE TABLE IF NOT EXISTS meeting_rsvps (
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  response TEXT NOT NULL CHECK (response IN ('attending', 'not_attending')),
  approval_status TEXT NOT NULL CHECK (approval_status IN ('pending', 'confirmed', 'declined')),
  responded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  reviewed_at TIMESTAMPTZ,
  reviewed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  attended_at TIMESTAMPTZ,
  attendance_marked_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
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

CREATE TABLE IF NOT EXISTS meeting_guests (
  id BIGSERIAL PRIMARY KEY,
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  added_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  company TEXT NOT NULL,
  email TEXT NOT NULL,
  attended_at TIMESTAMPTZ,
  attendance_marked_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE meeting_rsvps
  ADD COLUMN IF NOT EXISTS attended_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS attendance_marked_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE meeting_guests
  ADD COLUMN IF NOT EXISTS attended_at TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS attendance_marked_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS meeting_invitation_recipients_user_idx ON meeting_invitation_recipients (user_id);
CREATE INDEX IF NOT EXISTS meeting_rsvps_user_idx ON meeting_rsvps (user_id);
CREATE INDEX IF NOT EXISTS meeting_attachments_meeting_idx ON meeting_attachments (meeting_id);
CREATE INDEX IF NOT EXISTS meeting_guests_meeting_idx ON meeting_guests (meeting_id);
CREATE INDEX IF NOT EXISTS meeting_guests_added_by_idx ON meeting_guests (added_by);
CREATE UNIQUE INDEX IF NOT EXISTS partner_labels_name_lower_uidx ON partner_labels (LOWER(TRIM(name)));
