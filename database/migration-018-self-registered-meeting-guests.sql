ALTER TABLE meeting_guests
  ADD COLUMN IF NOT EXISTS registered_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS meeting_guests_registered_user_uidx
  ON meeting_guests (meeting_id, registered_user_id)
  WHERE registered_user_id IS NOT NULL;
