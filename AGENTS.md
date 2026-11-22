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
