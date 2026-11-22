# School Esports project instructions

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
- Ensure GitHub Actions permissions allow `contents: write`; if direct push protection disallows bots, adjust repository settings or change to a PR-based workflow.
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
- Run PHP lint, JavaScript syntax checks and `tests/email_verification_test.php` before merging into main; continue normal website version increments.

## Public URL and routing stability
- Public navigation must always point directly to real PHP filenames, e.g. `/register_student.php` (never depend on extensionless routes).
- Do not overwrite or invent the original Hostinger `.htaccess`. Its original contents were never committed to this repository; preserve it from the live server. The tracked Git copy provides security rules only, not verified clean-URL rewrites.
- `scripts/safe-pull.sh` backs up and restores server `.htaccess`, `uploads/` and `download/`, while clearing `html/`. Hostinger hPanel auto-deployment needs its own preservation/exclusion for the original `.htaccess`.
- When changing pages or navigation, run `php tests/public_navigation_test.php`; check real target files exist and keep desktop/mobile links aligned.
- If a page is removed, change its links to an existing page/section instead of leaving a 404.
- The generated `html/` cache is invalidated after deployment as configured. Live PHP and generated HTML must both use working root-relative menu links.
