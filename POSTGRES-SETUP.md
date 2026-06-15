# PostgreSQL backend setup

The frontend uses the PostgreSQL API. Upload the public files and API together,
then configure the private database credentials before opening the portal.

## Public `/portal` folder

Upload:

```text
api/
  bootstrap.php
  features.php
  index.php
```

## Private hosting root

Place `portal-config.php` next to the public `/portal` folder, not inside it.
Start from `api/config.example.php` and add the PostgreSQL credentials from the
Simply.com control panel.

Optional: add `mail_from` if invitation emails should use a specific sender
address:

```php
'mail_from' => 'noreply@e-nv.dk',
```

Expected layout:

```text
hosting-root/
  portal-config.php
  portal/
    api/
      bootstrap.php
      features.php
      index.php
```

## Database schema

Run `database/schema.sql` against the PostgreSQL database.

For an existing portal database, also run:

```text
database/migration-002-groups-and-profiles.sql
```

Then run:

```text
database/migration-003-partner-labels.sql
```

Then run:

```text
database/migration-004-invitations.sql
```

Then run:

```text
database/migration-005-optional-profile-fields.sql
```

Then run:

```text
database/migration-006-link-existing-profiles.sql
```

Then run:

```text
database/migration-007-profile-group-relations.sql
```

Then run:

```text
database/migration-008-admin-feedback.sql
```

Then run:

```text
database/migration-009-invitation-links.sql
```

For private meeting attachments, configure a directory outside the public
`/portal` folder:

```php
'meeting_attachment_dir' => __DIR__ . '/portal-private/meeting-attachments',
'meeting_attachment_max_bytes' => 10485760,
```

## Initial admin user

Place `database/create-admin.php` outside the public `/portal` folder and run it
over SSH:

```text
php create-admin.php admin@example.com "use-a-long-password"
```

Delete the uploaded `create-admin.php` file after the admin has been created.

## Endpoints

Upload both `api/index.php` and `api/features.php` together; the feature
endpoints are loaded by the main API router.

```text
GET  /api/index.php?action=partners
GET  /api/index.php?action=meetings
GET  /api/index.php?action=session
POST /api/index.php?action=login
POST /api/index.php?action=logout
POST /api/index.php?action=partners   admin only
POST /api/index.php?action=meetings   admin only
```
