# Public navigation and 404 prevention

## Root cause
The menu linked to extensionless URLs (such as `register_student`) even though the repository contains `register_student.php`. Some Apache configurations return 404 without explicit rewrite rules.

The repository history **does not contain the original Hostinger .htaccess**: it first appeared in Git in commit `9151f659` during the repository cleanup. It would be unsafe to invent or overwrite the original hosting rules.

## Fix
- Public desktop and mobile navigation now use direct, root-relative PHP links (e.g. `/register_student.php`), independent of URL rewriting.
- Fixed orphaned links on Manuals, school registration and the FAQ section.
- The tracked `.htaccess` was **not modified** by this fix. It retains the current Git security rules, not a recovered Hostinger-original configuration.
- The `html/` cache's `<base href="../">` remains compatible with the new root-relative public menu links.
- The PHP navigation CI test checks menu destinations, linked files and existing security directives, **not** clean-URL rewrite rules.

## Protect the original server .htaccess
For a manually managed Git checkout use `bash scripts/safe-pull.sh`: it backs up and restores server `.htaccess`, `uploads/` and `download/`, and clears only generated `html/` after a successful revision change. A plain `git pull` does not provide the same `.htaccess` backup protection.

For Hostinger hPanel auto-deployment, Git deployment behaviour may overwrite `.htaccess` because it is still a tracked file. Back up the actual server-original file and configure a deployment exclusion or post-deployment restore according to Hostinger's deployment mechanism **before** deploying. The original Hostinger file cannot be reproduced from this Git repository.

After deployment, test `/index.php`, `/about-programme.php`, `/events-competitions.php`, `/register_school.php`, `/register_student.php`, `/register_teacher.php`, `/manuals.php`, `/contact.php` and `/login.php`. Older extensionless bookmarks such as `/register_student` might still need a separate host-approved rewrite rule, but the website menu no longer relies on one.

## Responsive navigation and mobile hero
Public pages use the full desktop menu at 1200px and above; below 1200px, only the icon header and blue bottom navigation appear. The hamburger/collapse menu is removed. Language selection is available in the compact footer, with a separate accessible control ID. Authenticated portal navigation is unchanged.
On the homepage below 992px the original hero artwork is reused as a decorative background with opacity 0.2 (80% transparent); the desktop side image is hidden at those widths. Foreground text and actions retain their own opacity. No additional image files are needed.
