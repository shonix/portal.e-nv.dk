BEGIN;

INSERT INTO users (email, password_hash, role)
VALUES
  ('anna@local.test', '$2y$12$kCDkTV6QNhlcFQbmy9FtEuR3.HzaADQaAxd5.9R7J4TyEqrfHsSd6', 'member'),
  ('bo@local.test', '$2y$12$kCDkTV6QNhlcFQbmy9FtEuR3.HzaADQaAxd5.9R7J4TyEqrfHsSd6', 'member'),
  ('clara@local.test', '$2y$12$kCDkTV6QNhlcFQbmy9FtEuR3.HzaADQaAxd5.9R7J4TyEqrfHsSd6', 'member')
ON CONFLICT (email) DO NOTHING;

INSERT INTO partners (user_id, slug, name, company, email)
SELECT u.id, data.slug, data.name, data.company, data.email
FROM (VALUES
  ('anna@local.test', 'anna-lokal', 'Anna Andersen', 'Nordbo Ejendomme', 'anna@local.test'),
  ('bo@local.test', 'bo-lokal', 'Bo Berg', 'Byrum Rådgivning', 'bo@local.test'),
  ('clara@local.test', 'clara-lokal', 'Clara Christensen', 'City Erhverv', 'clara@local.test')
) AS data(user_email, slug, name, company, email)
JOIN users u ON u.email = data.user_email
ON CONFLICT (slug) DO UPDATE SET
  user_id = EXCLUDED.user_id,
  name = EXCLUDED.name,
  company = EXCLUDED.company,
  email = EXCLUDED.email;

INSERT INTO groups (name, address)
VALUES ('Lokal testgruppe', 'Lokalt mødelokale')
ON CONFLICT (name) DO UPDATE SET address = EXCLUDED.address;

INSERT INTO group_members (group_id, user_id)
SELECT g.id, u.id
FROM groups g
JOIN users u ON u.email IN ('anna@local.test', 'bo@local.test', 'clara@local.test')
WHERE g.name = 'Lokal testgruppe'
ON CONFLICT DO NOTHING;

INSERT INTO meetings (
  slug, title, meeting_date, meeting_time, address, status,
  program_text, location_text, guests_text, invite_text
)
VALUES (
  'lokalt-testmoede', 'Lokalt testmøde', CURRENT_DATE + 7, '09:00',
  'Testvej 1, 9000 Aalborg', 'upcoming',
  'Velkomst og netværk', 'Lokalt mødelokale', 'Registrerede gæster', 'Kun lokal test'
)
ON CONFLICT (slug) DO UPDATE SET
  meeting_date = EXCLUDED.meeting_date,
  meeting_time = EXCLUDED.meeting_time;

INSERT INTO meeting_groups (meeting_id, group_id)
SELECT m.id, g.id
FROM meetings m, groups g
WHERE m.slug = 'lokalt-testmoede' AND g.name = 'Lokal testgruppe'
ON CONFLICT DO NOTHING;

INSERT INTO meeting_invitation_recipients (meeting_id, user_id, email_status)
SELECT m.id, u.id, 'sent'
FROM meetings m
JOIN users u ON u.email IN ('anna@local.test', 'bo@local.test', 'clara@local.test')
WHERE m.slug = 'lokalt-testmoede'
ON CONFLICT (meeting_id, user_id) DO NOTHING;

INSERT INTO meeting_rsvps (meeting_id, user_id, response, approval_status)
SELECT m.id, u.id, data.response, data.approval_status
FROM (VALUES
  ('anna@local.test', 'attending', 'confirmed'),
  ('bo@local.test', 'attending', 'confirmed'),
  ('clara@local.test', 'attending', 'pending')
) AS data(email, response, approval_status)
JOIN users u ON u.email = data.email
JOIN meetings m ON m.slug = 'lokalt-testmoede'
ON CONFLICT (meeting_id, user_id) DO UPDATE SET
  response = EXCLUDED.response,
  approval_status = EXCLUDED.approval_status;

INSERT INTO meeting_guests (meeting_id, added_by, name, company, email)
SELECT m.id, u.id, data.name, data.company, data.email
FROM (VALUES
  ('Gitte Gæst', 'Gæstevirksomheden', 'gitte@local.test'),
  ('Mads Møller', 'Møller ApS', 'mads@local.test')
) AS data(name, company, email)
JOIN meetings m ON m.slug = 'lokalt-testmoede'
JOIN users u ON u.email = 'anna@local.test'
WHERE NOT EXISTS (
  SELECT 1 FROM meeting_guests existing
  WHERE existing.meeting_id = m.id AND existing.email = data.email
);

COMMIT;
