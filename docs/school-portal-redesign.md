# School portal redesign — initial increment

Baseline: d9fa744a7e39df8a32164ffb3211e25ef958df74 (v0.0.25).

| Issue | Files | Change | Verification |
|---|---|---|---|
| PS5 awarded at 10 students | school/dashboard.php | Use authoritative current-registration-year entitlement service; show next milestone | New regression test at 0, 10, 29, 30, 59, 60, 101 |
| Failed dashboard count presented as zero | school/dashboard.php | Nullable values and unavailable label; log technical errors | PHP lint / integration test pending |
| Editable membership and verification fields | school/_configs.php, includes/school_crud.php | Remove fields from school form/POST whitelist; pending payment default; reset teacher email verification on email change | Static regression checks; MySQL integration pending |
| Form-first people management | includes/school_crud.php, school/_configs.php | List first; add/edit via existing route | Browser test pending |
| Repeated inline portal style | includes/portal_layout.php, assets/css/portal-design.css | Shared compact Bootstrap foundation and focus styles | Responsive visual/keyboard test pending |

No schema migration; no production records modified. Preserve existing version automation.

## Remaining scope
- [ ] Grouped navigation, five-destination mobile footer, account profile language/country persistence.
- [ ] Unified effective membership service and filtered links across all roles; reconcile payment/activation workflows.
- [ ] Parent relationships, attendance, learning, certificates, equipment status and requests, event eligibility and approvals, reports.
- [ ] Review admin/teacher/student/parent/JPN/public screens, translations, secure error handling and server-side permissions.
- [ ] Database-backed integration tests, school isolation tests, responsive screenshots and manual accessibility tests.
- [ ] Confirm deployed version and Hostinger runtime data preservation.

This is a partial increment, not completion of the comprehensive redesign. Do not claim full WCAG conformance or production verification.

## Second increment
- Shared equipment timeline directly below public school hero and on school dashboard. Counts across all years are separate from current-registration-year eligibility; only aggregate data rendered. Public profile follows existing daily cache policy.
- Grouped desktop navigation for school, admin, teacher and parent; school mobile uses five destinations with accessible native disclosure panels and blue footer. Existing routes preserved. JPN shares compact CSS.
- School events use existing public participation workflow and scoped application history; direct school CRUD writes removed.
- Student/teacher list filters, accessible action names and generic error states; report notes list-first.
- School language selector moved to profile; existing cookie preference only. Account-level persistence, full country support and comprehensive translations pending.
- Remaining: verify live browser layouts and all role workflows, complete unified membership/guardian/student profile, attendance, learning, certificates, reports, equipment and PSN UX, and account preferences; database-backed integration and WCAG manual testing. No production database migration.

### Additional workflow improvements
- Guardian linking no longer overwrites existing shared guardian details; email change is not allowed through the school edit form, and shared-account edits require parent/admin handling.
- Attendance, guardian, learning and certificate pages default to the existing lists with explicit add/edit entry points. Manual attendance derives dates, duration, status and credited minutes using existing monthly cap; duplicate checks added. QR endpoint unchanged and requires separate security review.
- Learning score bounds and certificate recipient type validation added. Existing certificate eligibility rules still need comprehensive review.
- Timeline includes update timestamp because public school profiles may be cached for up to 24 hours.
