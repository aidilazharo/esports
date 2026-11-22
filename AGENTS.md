# School Esports project instructions

## Git development and release workflow — mandatory
- For this repository, **never create a new development branch or pull request** for requested code changes.
- Make changes and commit **directly to the existing `main` branch**. Push to `main` after running the relevant available tests.
- Keep automatic website patch-version increments on `main` in place after every code update.
- If repository branch protection or permissions block a direct commit, report the blocker instead of silently creating a branch or pull request.
- This rule applies to all future School Esports code changes unless the project owner explicitly changes it.

## Authoritative source
- Main repository: https://github.com/aidilazharo/esports
- This is the PHP/MySQL school esports portal source repository.

## Versioning requirement
- Website footer text must read: `© YYYY Metadata Berhad 1573083-A vMAJOR.MINOR.PATCH`.
- Initial website version is `v0.0.0`. The source of truth is root `VERSION` (contains `0.0.0` without the `v` prefix).
- Shared public footer is `includes/footer.php`; `includes/website_version.php` reads `VERSION`.
- Every future code update pushed to `main` automatically increments the patch number using `.github/workflows/website-version.yml`. Examples: `v0.0.0` → `v0.0.1` → `v0.0.2`.
- The version bump creates a separate `main` commit with `[skip-version]` to prevent recursion. Also use `[skip-version]` for initial version setup.
- Never manually replace the version with a hard-coded footer value.
- Ensure GitHub Actions permissions allow `contents: write`; if direct push protection disallows bots, report the blocker and seek a permitted direct-to-main solution; do not create a PR as a workaround.
- Hostinger Git auto-deployment can deploy pushes to `main` automatically. Since a regular code push is followed by an automated VERSION commit, Hostinger may deploy twice per code update. Verify the second deployment finishes.
- Retain local-only `html/`, `download/`, `uploads/`. Git ignoring these paths does NOT guarantee Hostinger will preserve them during full-directory redeployments: store persistent data outside the deployment destination or use a deploy process that excludes these directories.

## Deployment HTML cache rule
- Generated `html/` must be **emptied** after every successful new-code server pull, then recreated as an empty directory. Do not remove PHP source files or `download/` / `uploads/` runtime content.
- Supported SSH pull: `bash scripts/safe-pull.sh`. For ordinary server `git pull`, install `bash scripts/install-server-html-hook.sh` once on Hostinger. Hostinger hPanel deployments that bypass Git hooks use the first-request `VERSION`-based fallback from `config.php`.
- Do not enable migration execution automatically on Git pull. Use `php scripts/migrate.php` with an explicit backup and validation; refer to `docs/mysql-migrations.md`.

## Registration verification policy (email-only)
- School, student, teacher, coach, parent and guardian registration and login must never require or send an SMS verification code.
- Student accounts: parent/guardian email verification link in `register_student.php?verify=...`; verifying the link must not mark `phone_verified_at`.
- Teachers and coaches: email verification links (`verify_email`) using token hashes and expiry; `phone_verified_at` stays NULL for new registrations.
- Standalone parents/guardians: six-digit email code sent using Brevo Transactional Email, bound to PHP session and email address; no SMS fallback or verify-later bypass; `email_verified_at` is set only after a correct code is submitted.
- Mobile numbers remain contact details only and may be stored, but must not serve as evidence of verified identity.
- Changing an already verified email requires a new email verification. Never preserve `email_verified_at` for a different email address.
- Environment configuration must require `BREVO_API_KEY`, `BREVO_SENDER_EMAIL`, and `APP_URL`, not `BREVO_SMS_SENDER`.
- Keep existing login email OTP and PSN TOTP features, which are distinct from SMS.
- Run PHP lint, JavaScript syntax checks and `tests/email_verification_test.php` before committing directly to main; continue normal website version increments.

## Public URL and routing stability
- Public navigation must always point directly to real PHP filenames, e.g. `/register_student.php` (never depend on extensionless routes).
- Do not overwrite or invent the original Hostinger `.htaccess`. Its original contents were never committed to this repository; preserve it from the live server. The tracked Git copy provides security rules only, not verified clean-URL rewrites.
- `scripts/safe-pull.sh` backs up and restores server `.htaccess`, `uploads/` and `download/`, while clearing `html/`. Hostinger hPanel auto-deployment needs its own preservation/exclusion for the original `.htaccess`.
- When changing pages or navigation, run `php tests/public_navigation_test.php`; check real target files exist and keep desktop/mobile links aligned.
- If a page is removed, change its links to an existing page/section instead of leaving a 404.
- The generated `html/` cache is invalidated after deployment as configured. Live PHP and generated HTML must both use working root-relative menu links.

## Nightly directory JSON snapshots
- The mobile public/footer navigation uses Bootstrap primary blue `#0d6efd` to match the mobile header, with white text/icons. Desktop footer remains white.
- Generate private searchable JSON snapshots of schools, teachers, students and parents/guardians at midnight Malaysia time using `cron/build_directory_snapshots.php --run`; Hostinger cron setup is required separately.
- Store snapshots outside the web root with directory mode 0700 and files mode 0600. Never commit them or publish sensitive users' names/contacts as static JSON assets.
- Use `/api/directory.php` for read-only, authenticated/scoped filtering without opening MySQL; the public schools dataset is deliberately minimal. School/teacher scope comes from session, never user input.
- MySQL remains authoritative for CRUD, account verification, access control, membership, payments and any live business decision. Snapshot data may be stale for up to 24 hours.
- The public school registration search can use snapshots and falls back to live MySQL when missing or invalidated. Other existing CRUD pages retain their live MySQL workflows until carefully migrated.
- Always run `php tests/directory_snapshot_test.php` for snapshot code changes and follow the mandatory commit-directly-to-main workflow.
