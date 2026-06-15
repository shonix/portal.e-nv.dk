ALTER TABLE invitations
    ADD COLUMN IF NOT EXISTS admin_token TEXT;

