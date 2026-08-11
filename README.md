# Ejendomsnetværkets Partnerportal

The partner portal is a small PHP and PostgreSQL application hosted at
`https://portal.e-nv.dk`. The frontend consists of static HTML, CSS and
JavaScript files, while `api/` contains the PHP API.

There is no Composer or npm build step. The browser-side XLSX library is
committed as `xlsx.full.min.js`.

## Requirements

- PHP 8.1 or newer
- PostgreSQL
- PHP extensions: PDO PostgreSQL, Fileinfo, GD image metadata support, Iconv
  and ZipArchive
- Apache with `mod_rewrite` and `mod_headers` for the production `.htaccess`
- HTTPS in production

ZipArchive is used to validate uploaded XLSX and DOCX files. PHP's `mail()`
function is currently used for invitation messages, so actual mail delivery
also depends on the hosting and domain mail configuration.

## Repository structure

```text
api/                         PHP API and configuration template
database/                    Full schema, incremental migrations and admin tool
deployment/                  Explicit public deployment manifest
scripts/                     Local and CI maintenance scripts
*.html                       Portal pages
portal-data.js               Shared API client
portal-header.js             Shared portal header
portal-header.css            Shared header styles
xlsx.full.min.js             Browser-side XLSX parser
.htaccess                    Clean URLs and cache headers
```

The repository contains application code only. Database credentials, uploaded
files and other runtime data must not be committed.

## External address service

Meeting administration uses Dataforsyningen's DAWA autocomplete service through
`address-autocomplete.js`. Saved meetings contain only the selected address text,
and manual entry remains available if the service cannot be reached.

Dataforsyningen has announced that DAWA will close on 1 October 2026. Replace the
provider implementation in `address-autocomplete.js` before that date; meeting
data, forms and Google Maps links do not need to change when the provider changes.

## Simply.com server layout

The expected production layout is:

```text
hosting-root/
  portal-config.php          Private configuration; never deploy from Git
  portal/                    Public portal application
    api/
    index.html
    ...
  portal-private/            Persistent, non-public uploaded files
    meeting-attachments/
    profile-pictures/
  public_html/               Main website; unrelated to portal deployment
```

Only the contents of the repository's public application are deployed to
`portal/`. A portal deployment must never replace or delete `portal-config.php`,
`portal-private/` or `public_html/`.

The PHP process must have write access to the two directories inside
`portal-private/`. The application creates them with restrictive permissions if
they do not already exist.

## Private configuration

The API loads its configuration from `portal-config.php`, located one level
above the public `portal/` directory. Start with `api/config.example.php`, copy
it to the hosting root and replace all placeholders.

```php
<?php
return [
    'dsn' => 'pgsql:host=POSTGRES_HOST;port=5432;dbname=DATABASE_NAME',
    'user' => 'DATABASE_USER',
    'password' => 'DATABASE_PASSWORD',
    'portal_base_url' => 'https://portal.e-nv.dk',
    'mail_from' => 'noreply@e-nv.dk',
    'meeting_attachment_dir' => __DIR__ . '/portal-private/meeting-attachments',
    'meeting_attachment_max_bytes' => 10485760,
    'profile_picture_dir' => __DIR__ . '/portal-private/profile-pictures',
    'profile_picture_max_bytes' => 2097152,
];
```

Configuration fields:

| Field | Required | Purpose |
| --- | --- | --- |
| `dsn` | Yes | PostgreSQL PDO connection string. |
| `user` | Yes | PostgreSQL username. |
| `password` | Yes | PostgreSQL password. |
| `portal_base_url` | Yes | Public portal URL used when generating invitation and password-reset links. |
| `mail_from` | For mail | Sender address passed to PHP `mail()`. This does not configure SMTP by itself. |
| `meeting_attachment_dir` | Recommended | Private storage path for meeting attachments. |
| `meeting_attachment_max_bytes` | No | Attachment limit in bytes; defaults to 10 MB. |
| `profile_picture_dir` | Recommended | Private storage path for profile pictures. |
| `profile_picture_max_bytes` | No | Profile-picture limit in bytes; defaults to 2 MB. |

Never place real credentials in `api/config.example.php`, GitHub Actions files,
issues, commits or screenshots. GitHub deployment credentials must be stored as
GitHub Actions secrets.

## Database setup

### New database

Run `database/schema.sql` once against an empty PostgreSQL database. It contains
the complete current schema.

### Existing database

Do not rerun the full schema as a substitute for release management. Apply only
the migrations that have not previously been applied, in numeric order:

| Migration | Change |
| --- | --- |
| 002 | Groups, group memberships and profile links |
| 003 | Partner labels |
| 004 | User invitations |
| 005 | Optional profile fields |
| 006 | Link existing profiles to matching users |
| 007 | Profile/group relation indexes and links |
| 008 | Meeting invitations, RSVPs and attachments |
| 009 | Reusable admin invitation link token |
| 010 | Meetings associated with multiple groups |
| 011 | Profile-picture metadata |
| 012 | Meeting guests |
| 013 | Group bulletin board |
| 014 | Administrator-generated password-reset links |
| 015 | Admin-only meeting attendance tracking |
| 016 | Administrator-managed information banner |

The project does not yet have a migration ledger. Record the last applied
migration as part of the deployment notes. Back up the database before applying
a new migration, and apply required migrations before deploying code that uses
them.

## Initial administrator

`database/create-admin.php` creates a new administrator or promotes and resets
an existing account with the same email address.

The script expects the same relative layout as the repository, with
`portal-config.php` two directories above it. If it is needed on Simply.com,
temporarily upload it as `portal/database/create-admin.php`, run it over SSH and
remove the public `database/` directory immediately afterwards:

```bash
php ~/portal/database/create-admin.php admin@example.com "use-a-long-password"
rm ~/portal/database/create-admin.php
rmdir ~/portal/database
```

Do not leave database maintenance scripts in the public portal.

## Local development

Local development uses the installed PHP runtime and a PostgreSQL 16 container.
Docker Desktop must be installed and running. The setup remains isolated from
the parent-level production configuration.

Initialize PostgreSQL, the schema, sample data and a local administrator:

```powershell
pwsh -File scripts/setup-dev.ps1
```

The script generates an ignored `.dev/php.ini`, enables the required PHP
extensions, creates the ignored `portal-config.local.php`, and starts the
`database` service from `compose.yaml`. It does not read or modify the normal
`portal-config.php` outside the repository.

Start the application:

```powershell
pwsh -File scripts/start-dev.ps1
```

Use `pwsh -File scripts/start-dev.ps1 -Background` to run PHP without keeping a
terminal open. `stop-dev.ps1` also stops a server started in this mode.

Open `http://127.0.0.1:8000/index.html` and sign in with:

```text
admin@local.test
LocalDev123!
```

The seeded meeting is available at
`http://127.0.0.1:8000/moede.html?id=lokalt-testmoede`.

Stop PostgreSQL after ending the PHP server with Ctrl+C:

```powershell
pwsh -File scripts/stop-dev.ps1
```

The Docker volume is preserved between sessions. To recreate the local database
from scratch, run `docker compose down -v` and then rerun `setup-dev.ps1`.

Apache's extensionless URL rewrites are not applied by PHP's built-in server,
so use the `.html` URLs during local development.

## Verification

Run PHP syntax checks before deployment:

```bash
find api database -name '*.php' -exec php -l {} \;
```

The main JavaScript files can be parsed with Node.js when it is available:

```bash
node --check portal-data.js
node --check portal-header.js
```

After deployment, verify at minimum:

```text
GET https://portal.e-nv.dk/
GET https://portal.e-nv.dk/api/index.php?action=session
```

The session endpoint should return JSON and must not expose configuration or
database errors.

## Deployment artifact

Build the exact set of public files before uploading or deploying:

```powershell
pwsh -NoProfile -File scripts/build-deploy.ps1
```

The command recreates `dist/portal/` from `deployment/portal-files.txt`. The
builder rejects missing or duplicate entries, unsafe paths, private files and
new conventional public files that have not been added to the manifest.

`dist/portal/` is ignored by Git. It is the only local directory whose contents
should be uploaded to Simply.com's public `portal/` directory. Files such as
`README.md`, `database/` and `api/config.example.php` cannot enter the artifact
unless the safety checks are deliberately changed.

## Manual deployment

If GitHub Actions deployment is unavailable:

1. Back up the current `portal/` directory and PostgreSQL database.
2. Apply any required database migrations.
3. Run `pwsh -NoProfile -File scripts/build-deploy.ps1`.
4. Upload the contents of `dist/portal/` to `portal/`.
5. Do not modify `portal-config.php`, `portal-private/` or `public_html/`.
6. Run the health checks above and test login, an admin page and one upload.

The GitHub Actions workflow packages the same explicit public-file allowlist,
deploys through SSH, retains a code backup and rolls back if health checks fail.
Database migrations remain a deliberate manual step until the project has a
migration ledger.

Simply's reviewed public SSH host keys are pinned in
`deployment/simply-known-hosts`. If Simply rotates these keys, verify the new
fingerprints through a trusted channel before updating that file.

## GitHub Actions deployment

Production deployment is defined in `.github/workflows/deploy.yml`. Publishing a
stable GitHub Release automatically deploys the commit referenced by that
release's tag. Draft releases and prereleases do not deploy.

Before publishing a release, apply any required database migrations and ensure
the release tag points to the intended production commit. For an exceptional or
repeat deployment, retain the manual option at **GitHub → Actions → Deploy portal
to Simply.com → Run workflow** and select the intended branch or tag.

The private repository must contain this Actions secret:

| Secret | Purpose |
| --- | --- |
| `SIMPLY_SSH_PRIVATE_KEY` | Dedicated private SSH key whose public half is registered in Simply.com's SSH access panel. |

The workflow performs these operations in order:

1. Check PHP and frontend syntax using PHP 8.4 and Node.js.
2. Build the explicit `dist/portal/` artifact.
3. Verify the private key fingerprint and pinned Simply host keys.
4. Confirm the four protected server paths and required remote tools.
5. Archive the current public `portal/` code outside the web root.
6. Upload and count a staged release.
7. Synchronize only that staged release into `portal/`.
8. Check the public front page and unauthenticated session API.
9. Restore the archive automatically if a step after backup fails.

The workflow does not run database migrations and does not write to
`portal-config.php`, `portal-private/` or `public_html/`. Deployments are
serialized so two production runs cannot overlap. Pushes to `main` do not deploy
directly; production changes remain tied to an explicit stable release or manual
workflow run.

## Security notes

- Keep the GitHub repository private.
- Use a dedicated SSH deployment key for GitHub Actions.
- Store all deployment credentials in GitHub Actions secrets.
- Never commit production configuration or uploaded user files.
- Treat password-reset and invitation URLs as secrets while they are valid.
- Rotate any credential immediately if it appears in Git history, logs or chat.
