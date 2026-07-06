ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS profile_picture_stored_name TEXT UNIQUE,
  ADD COLUMN IF NOT EXISTS profile_picture_mime_type TEXT,
  ADD COLUMN IF NOT EXISTS profile_picture_size BIGINT;
