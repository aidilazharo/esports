# Portal language selector

The portal offers four UI languages in the public header and the admin, school,
teacher, parent, and JPN headers:

- Bahasa Melayu (\`ms\`)
- English (\`en\`, default)
- 简体中文 (\`zh\`)
- தமிழ் (\`ta\`)

The shared implementation is in \`includes/portal_language.php\` with browser
logic in \`assets/js/portal-language.js\` and styling in
\`assets/css/portal-language.css\`. The selector persists the allowlisted
\`portal_lang\` cookie for one year (\`Path=/\`, \`SameSite=Lax\`, \`Secure\`
on HTTPS). It does not require login or a new database column.

The selected locale changes HTML \`lang\`, translated shared menu labels,
common portal headings, public landing-page copy, buttons, status labels, and
other *explicitly reviewed* interface phrases. The browser script matches
entire English text nodes/attributes against a bounded dictionary. It does
not call a third-party translation API or translate dynamic user-entered
content, student records, school data, payments, or arbitrary messages.
Interface phrases without a reviewed translation remain in English. Extend
\`portalLanguageDictionary()\` to localize more screens incrementally.

## Static HTML cache

The site has a daily \`/html/*.html\` cache. Only English requests are eligible
for that cache. Selecting a non-English language sends visitors back to the
original PHP page with the new cookie, bypassing English cached pages. Returning
to English can use the cached HTML again. After deployment, remove stale
generated pages from \`html/\` (without deleting the directory or changing
user uploads) so the updated language picker appears immediately.

## Verification

\`\`\`sh
php tests/portal_language_test.php
php tests/public_navigation_test.php
\`\`\`

The existing GitHub PHP/JS syntax-check workflow executes both tests on main
pushes. Check accessibility, navigation, and language persistence manually
on public, school, teacher, parent, admin, and JPN mobile/desktop views.
