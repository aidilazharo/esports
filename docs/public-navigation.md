# Public navigation and 404 prevention

## Root cause fixed
Earlier cleanup replaced `.htaccess` with security-only rules, but the public navbar still linked to extensionless routes such as `register_student`. On Apache configurations without implicit extension mapping, these URLs returned HTTP 404.

## Routing policy
- Public header and both mobile navigation menus use root-relative PHP links, e.g. `/register_student.php`.
- Apache additionally rewrites *existing* root-level extensionless PHP endpoints for old bookmarks (e.g. `/register_student`), while preserving existing static files, directories, and upload/script restrictions.
- The manual page, school-registration cancellation and FAQ navigation also point to existing destinations.
- The static `html/` cache inserts `<base href="../">`. Root-relative links remain valid when pages are served from `/html/`.
- `php tests/public_navigation_test.php` verifies target files, public page links, desktop/mobile menu targets and the required Apache rules. CI runs it for PRs and main pushes.

Hostinger should deploy the new root `.htaccess` together with PHP source. If the live site still returns 404 on the explicit `.php` URLs, verify domain document-root configuration, deployment history and that `.htaccess` is applied on the active server. The PHP navigation fix does not depend on Apache rewrite to function.

The live URL was not accessible to the audit's public web fetch, so check these paths after deployment: `/index.php`, `/about-programme.php`, `/events-competitions.php`, `/register_school.php`, `/register_student.php`, `/register_teacher.php`, `/manuals.php`, `/contact.php`, `/login.php`, and legacy `/register_student`.
