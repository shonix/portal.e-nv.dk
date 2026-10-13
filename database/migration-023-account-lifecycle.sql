ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_status TEXT NOT NULL DEFAULT 'active'
  CHECK (account_status IN ('unclaimed', 'invited', 'active'));

CREATE INDEX IF NOT EXISTS users_account_status_idx ON users (account_status);
