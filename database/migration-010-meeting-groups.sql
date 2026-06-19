CREATE TABLE IF NOT EXISTS meeting_groups (
  meeting_id BIGINT NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  group_id BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  PRIMARY KEY (meeting_id, group_id)
);

INSERT INTO meeting_groups (meeting_id, group_id)
SELECT id, group_id FROM meetings WHERE group_id IS NOT NULL
ON CONFLICT DO NOTHING;

CREATE INDEX IF NOT EXISTS meeting_groups_group_id_idx ON meeting_groups (group_id);
