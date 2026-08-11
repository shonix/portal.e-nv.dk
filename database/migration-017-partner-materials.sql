CREATE TABLE IF NOT EXISTS partner_materials (
  id BIGSERIAL PRIMARY KEY,
  title TEXT NOT NULL,
  description TEXT,
  category TEXT NOT NULL DEFAULT 'other'
    CHECK (category IN ('logo', 'email_signature', 'web', 'flyer', 'other')),
  original_name TEXT NOT NULL,
  stored_name TEXT NOT NULL UNIQUE,
  mime_type TEXT NOT NULL,
  file_size BIGINT NOT NULL CHECK (file_size >= 0),
  uploaded_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS partner_materials_created_idx
  ON partner_materials (created_at DESC);
