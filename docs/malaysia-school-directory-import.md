# Import all Malaysian schools without emailing them

The repository provides a **CLI-only** importer for a reviewed Malaysia-wide school directory CSV. School records are searchable for public lookup, but **new imported rows block all school-directed Brevo emails** until explicitly activated by a school administrator.

## Data required
Obtain a reliable **school-level** official list (not aggregate counts) containing each school's official code, name, type, state and district. Review licensing and freshness. The current OpenDOSM / data.gov.my `schools_district` dataset only provides **counts by district** and cannot directly populate individual schools. KPM's MyGDX catalogue includes school-list data but its actual access may require provider approval.

Required CSV headers: `school_code,school_name,state`. Optional: `school_type,district`. Additional columns, even emails or phone numbers, are deliberately **ignored**. The CSV must be saved as UTF-8, comma-delimited; codes must be unique and use official spelling. The template is `docs/school-directory-import-template.csv`.

## New MySQL email-suppression flag
Apply migration `database/migrations/20261122_220000_add_school_email_suppression.php` to add:

```sql
ALTER TABLE schools
ADD COLUMN suppress_school_email TINYINT(1) NOT NULL DEFAULT 0;
```

`0` = existing/default school email behaviour; `1` = **never send school invitations, school monthly reports, or school login PINs**. The flag is independent of `jpn` and `unsubscribed`. Existing schools stay `0`. The importer explicitly sets `1` for new records, plus `jpn=0`, `unsubscribed=1`, `invite_email=0`, `download_file=0` and no contact email.

All three sending paths check the suppression flag. The `--force` monthly option does not bypass it; manual invitations do not bypass it. If the column is not yet installed, original school email behavior continues for existing records, but the importer refuses to run.

## Hostinger server sequence
1. Back up the existing MySQL database and upload the reviewed CSV to a private server folder **outside the web document root**.
2. Run schema status, then the migration after confirming the backup:

```bash
php scripts/migrate.php status
php scripts/migrate.php apply --backup-confirmed
```

3. Preview all school records without changing MySQL:

```bash
php scripts/import-school-directory.php /private/path/malaysia-schools.csv --dry-run
```

4. When the preview and schema are valid, apply:

```bash
php scripts/import-school-directory.php /private/path/malaysia-schools.csv --apply --backup-confirmed
```

The importer validates the whole CSV before writing, skips existing school codes **without modifying their email addresses, approvals or subscription settings**, uses a MySQL advisory lock and commits in batches of 250. Existing school codes remain intact; newly imported school records have no contact email and the suppression flag turned on. If a production column is non-null without a safe default and cannot be populated from directory data, the importer stops before writing any rows. Inspect the schema instead of filling fabricated values.

## Activating a school later
A directory-only school remains email-suppressed even if it fills in its profile. An authorized administrator may turn OFF the **Block All School Emails (Directory Only)** checkbox only after verifying that the school representative is authorized and the contact email was obtained appropriately. Existing registration logic does not automatically clear this flag. Do not bulk-enable email after importing a nationwide directory.

## Verify protection

```sql
SELECT COUNT(*) AS total_schools FROM schools;
SELECT COUNT(*) AS suppressed_schools FROM schools WHERE suppress_school_email = 1;
SELECT school_code, school_name, state, district, suppress_school_email
FROM schools WHERE suppress_school_email = 1 LIMIT 20;
```

Run regression checks with `php tests/school_directory_import_test.php`. No actual school directory is bundled in Git; database data is only imported after you supply or acquire a reviewed file and run the Hostinger commands.

**Note:** The existing admin school dropdown loads at most 3,000 choices. Before importing thousands, migrate that dropdown to remote AJAX search to prevent inaccessible schools in admin forms. The public directory search itself already supports larger school lists through the nightly JSON mechanism.
