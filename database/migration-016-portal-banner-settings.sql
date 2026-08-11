CREATE TABLE IF NOT EXISTS portal_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO portal_settings (setting_key, setting_value)
VALUES
  ('banner_enabled', 'true'),
  ('banner_audience', 'no_group'),
  ('banner_title', 'Velkommen til Ejendomsnetværkets Partnerportal'),
  ('banner_message', 'Når du har oprettet din brugerprofil, kan der gå op til 24 timer, før en administrator tilføjer dig til din gruppe.')
ON CONFLICT (setting_key) DO NOTHING;
