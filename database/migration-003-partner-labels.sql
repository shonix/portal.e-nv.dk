CREATE TABLE IF NOT EXISTS partner_labels (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS partner_profile_labels (
  partner_id BIGINT NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  label_id BIGINT NOT NULL REFERENCES partner_labels(id) ON DELETE CASCADE,
  PRIMARY KEY (partner_id, label_id)
);
