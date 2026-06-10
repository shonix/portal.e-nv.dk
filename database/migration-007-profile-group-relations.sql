ALTER TABLE partners
    ADD COLUMN IF NOT EXISTS user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE;

UPDATE partners AS partner
SET user_id = account.id
FROM users AS account
WHERE partner.user_id IS NULL
  AND LOWER(TRIM(partner.email)) = LOWER(TRIM(account.email))
  AND NOT EXISTS (
    SELECT 1
    FROM partners AS existing
    WHERE existing.user_id = account.id
  );

CREATE INDEX IF NOT EXISTS partners_user_id_idx ON partners (user_id);
CREATE INDEX IF NOT EXISTS group_members_user_id_idx ON group_members (user_id);
CREATE INDEX IF NOT EXISTS group_members_group_id_idx ON group_members (group_id);
