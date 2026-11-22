# Local-only runtime folders

The repository intentionally does **not** track any files under the following root paths:

- `html/` — generated site pages and snapshots.
- `download/` — deployment-provided downloads (including `metadata_jpn_esports.pdf`).
- `uploads/` — generated/user uploads, logos, and certificates.

The folders are ignored by Git through `.gitignore`. Once these paths are untracked, normal `git fetch` does not modify the working tree, and subsequent `git pull` updates do not replace the local files stored in them.

## Important: the first pull of this cleanup commit

**Do not run a plain `git pull` on an existing deployment before backing up these directories.** Because older commits tracked their contents, Git can delete those previously tracked files when you pull the commit that removes them from version control. `.gitignore` alone cannot prevent this one-time deletion.

Recommended: **download and run `scripts/safe-pull.sh` before the first pull**. Use Git Bash on Windows or Bash on Linux/macOS. This copies the three directories to a temporary backup, performs a fast-forward-only pull of `origin/main`, and restores every original file. If a pull or restoration fails, the backup remains available. Execute the following *from the existing repository checkout on its main branch*:

```bash
curl -fsSL https://raw.githubusercontent.com/aidilazharo/esports/main/scripts/safe-pull.sh -o esports-safe-pull.sh
bash esports-safe-pull.sh
rm esports-safe-pull.sh
```

The downloaded script runs relative to your current Git checkout, not its own location. You can also manually copy `html/`, `download/`, and `uploads/` outside the working tree, pull, and restore them afterward. Retain an independent production backup.

A new checkout does **not** contain the three directories or their contents. Provision required runtime directories and assets separately. In particular, `download.php` expects `download/metadata_jpn_esports.pdf` to exist on the server.

## Deployment and security

- Keep `uploads/` writable only as necessary; avoid exposing uploaded secrets.
- The former tracked `uploads/certificates/.htaccess` has been removed from Git with the rest of the folder. The repository-root `.htaccess` now blocks requests for executable/script file types under `uploads/` when Apache rewrite rules are enabled.
- For Nginx or Apache installations that ignore `.htaccess`, configure equivalent server-level access controls.
- Back up these three local folders separately from source code and before deployments.
- Do not use `git clean -fdx` or other aggressive cleanup commands in production: those may delete ignored runtime folders.
- `git fetch` alone is safe and does not replace working-tree files; the critical operation is the first checkout, merge, rebase, or pull that removes old tracked files.
