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

ALTER TABLE partners ADD COLUMN IF NOT EXISTS user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE meetings ADD COLUMN IF NOT EXISTS group_id BIGINT REFERENCES groups(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS partners_user_id_idx ON partners (user_id);
CREATE INDEX IF NOT EXISTS group_members_user_id_idx ON group_members (user_id);
CREATE INDEX IF NOT EXISTS group_members_group_id_idx ON group_members (group_id);

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
  expires_at TIMESTAMPTZ NOT NULL,
  used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS invitations_email_idx ON invitations (email);
