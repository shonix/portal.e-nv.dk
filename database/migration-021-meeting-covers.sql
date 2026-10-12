ALTER TABLE meetings
  ADD COLUMN IF NOT EXISTS cover_image_stored_name TEXT UNIQUE,
  ADD COLUMN IF NOT EXISTS cover_image_mime_type TEXT,
  ADD COLUMN IF NOT EXISTS cover_image_size BIGINT;
