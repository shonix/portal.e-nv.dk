ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS profile_cover_stored_name TEXT UNIQUE,
  ADD COLUMN IF NOT EXISTS profile_cover_mime_type TEXT,
  ADD COLUMN IF NOT EXISTS profile_cover_size BIGINT;
