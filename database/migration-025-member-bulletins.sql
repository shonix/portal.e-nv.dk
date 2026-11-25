ALTER TABLE group_bulletins
  ADD COLUMN IF NOT EXISTS title TEXT,
  ADD COLUMN IF NOT EXISTS category TEXT NOT NULL DEFAULT 'request',
  ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'open',
  ADD COLUMN IF NOT EXISTS expires_at DATE,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ADD COLUMN IF NOT EXISTS closed_at TIMESTAMPTZ;

UPDATE group_bulletins
SET title = LEFT(REGEXP_REPLACE(message, E'[\r\n]+', ' ', 'g'), 140)
WHERE title IS NULL OR BTRIM(title) = '';

UPDATE group_bulletins
SET title = 'Opslag'
WHERE title IS NULL OR BTRIM(title) = '';

ALTER TABLE group_bulletins
  ALTER COLUMN title SET NOT NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'group_bulletins_category_check'
  ) THEN
    ALTER TABLE group_bulletins
      ADD CONSTRAINT group_bulletins_category_check
      CHECK (category IN ('request', 'offer', 'other'));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'group_bulletins_status_check'
  ) THEN
    ALTER TABLE group_bulletins
      ADD CONSTRAINT group_bulletins_status_check
      CHECK (status IN ('open', 'closed'));
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS group_bulletins_group_status_idx
  ON group_bulletins (group_id, status, created_at DESC);
