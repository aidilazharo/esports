# Nightly JSON directory snapshots

## Overview
The School Esports Portal can export read-only, searchable JSON snapshots of its MySQL schools, teachers, students, and guardians every day at midnight. MySQL is still the source of truth for login, editing, authorization, payments, membership status, and real-time data.

## Data and privacy
- `schools.json`: public school code, name, type, state, district, and coach availability only.
- `schools_admin.json`: private school directory with school contact email, JPN flag, and student count.
- `teachers.json`: private names, positions, emails, school linkage, and email-verification display data.
- `students.json`: private student number, name, class, school linkage, and last-snapshot membership display fields.
- `parents.json`: private guardian name, contact email/phone, count of linked children, and linked school IDs (used only for scope checks).

Never snapshot identity numbers, DOB, QR secrets, login credentials, access/verification tokens, or payment identifiers. Do not put the JSON files under the document root or expose a raw file download route. The snapshot folder defaults to an installation-specific private subfolder under the PHP temp directory (0700 directory / 0600 files). Prefer the optional `ESPORTS_DIRECTORY_SNAPSHOT_DIR` environment variable pointing at a persistent absolute directory OUTSIDE the Hostinger web root. The code refuses an inside-web-root path.

## Cron setup (must be configured in Hostinger)
Manually test from the website's PHP CLI account:
```bash
php cron/build_directory_snapshots.php --run
```
Then configure **Hostinger hPanel → Advanced → Cron Jobs** to execute once daily at **00:00 Asia/Kuala_Lumpur**. Example when the scheduler itself uses Malaysia time:
```cron
0 0 * * * /usr/bin/php /ABSOLUTE/PATH/esports/cron/build_directory_snapshots.php --run
```
Use the actual PHP executable and absolute project location. If the scheduler uses UTC, 16:00 UTC on the previous day corresponds to midnight in Malaysia. Check the configured cron timezone. The code commit does not automatically configure your Hostinger cron job.

The CLI script uses a file lock; each successful dataset is published with an atomic rename. On error, the previous completed copy of that dataset remains. Monitor cron output. The JSON API cannot trigger a rebuild itself.

## Read-only JSON API
Use the endpoint below to search or filter without opening a MySQL connection:
```text
/api/directory.php?type=schools&q=KUANTAN&state=PAHANG&page=1&limit=25
/api/directory.php?type=teachers&q=ALI&school_id=10&page=1&limit=25
/api/directory.php?type=students&q=ST-001&school_id=10&page=1&limit=25
/api/directory.php?type=parents&q=NAMA&page=1&limit=25
```
Response contains `generated_at`, `total`, `page`, `limit`, and a `data` array. The page limit is capped at 50.

Public callers can search the basic school directory only. Private calls require a valid logged-in role: admin sees all; school/teacher see only teachers, students, guardians linked to their own school; a parent can read only their own guardian record. Query parameters cannot bypass session scope. Private responses are `Cache-Control: private, no-store`. No MySQL connection is opened by this dedicated directory API.

## Actual integration and freshness
- Existing `api/registration/schools.php` broad search now reads the public school JSON if present and makes no MySQL connection. Exact detailed registration lookups still use live MySQL.
- When a school registers or is updated through cache-invalidating code, school snapshots and v4 search cache are invalidated; public school search uses the original MySQL fallback until the next cron build.
- Other PHP CRUD pages remain on MySQL; do NOT treat the midnight snapshots as live data. Move them to the API only after assessing the page's security and freshness requirements.
- Missing private JSON snapshots return 503 rather than silently opening MySQL; the site can continue using existing MySQL pages.
- All JSON data can be up to 24 hours old; display the `generated_at` timestamp on snapshot-driven screens and keep security/payment/membership decisions on live MySQL.
- Run `php tests/directory_snapshot_test.php` before changing snapshot security or exports.

## Deployment
Do not put these files in `html/` (cleared after deploy), `uploads/`, `download/`, or Git. Ensure the same Hostinger PHP user can read/write the configured private snapshot directory from both CLI cron and web requests.
