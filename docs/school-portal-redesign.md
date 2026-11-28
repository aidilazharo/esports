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
