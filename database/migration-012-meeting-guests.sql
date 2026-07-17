CREATE TABLE IF NOT EXISTS meeting_guests (
  id BIGSERIAL PRIMARY KEY,
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  added_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  company TEXT NOT NULL,
  email TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS meeting_guests_meeting_idx ON meeting_guests (meeting_id);
CREATE INDEX IF NOT EXISTS meeting_guests_added_by_idx ON meeting_guests (added_by);
