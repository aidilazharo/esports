# Email-only verification policy

Registration and login verification use **email only**.

| Role | Verification mechanism |
| --- | --- |
| School | Existing school registration and email-based login |
| Student + guardian | Email confirmation link to guardian after student registration |
| Teacher | One-time email confirmation link after registration |
| Esports Coach | One-time email confirmation link after registration |
| Standalone Parent / Guardian | Six-digit code delivered to email before registration is saved |
| Existing logins | Existing email OTP or email sign-in mechanism |

**No SMS verification request, code, fallback, or bypass is permitted.** Mobile numbers remain contact data. Existing historic `phone_verified_at` column values are retained for database compatibility, but are not set by new email confirmations. Parents' `email_verified_at` is recorded after a correctly matched, unexpired code; student/teacher/coach email links set email-verified status only when clicked.

For parent email codes: 6 digits, 10-minute expiration, at most 5 incorrect attempts, 60-second resend cooldown, code session bound to the exact email. A failed send does not issue a usable code; delivery failures never bypass verification.

**Required environment:** `BREVO_API_KEY`, `BREVO_SENDER_EMAIL`, `APP_URL`, and working PHP cURL. `BREVO_SMS_SENDER` is no longer used. Existing MySQL schema for teacher/coach email tokens is still required. No schema migration is needed to remove SMS.

Student/teacher/coach records are stored with pending email confirmation until the recipient clicks the verification link. If email delivery fails, the account remains unverified; add a future authenticated resend-confirmation flow before enforcing strict login blocking. Use non-production inboxes to verify the end-to-end flow in staging.
