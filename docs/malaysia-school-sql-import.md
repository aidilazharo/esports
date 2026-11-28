# Nationwide school directory SQL import

Source: [MySchoolList v1.2.0](https://github.com/apitlekays/MySchoolList), June 2022 KPM-derived data, 10,230 rows. Source school blob SHA: 22819f2afb5ad10fe70eea7700d762fefe719594. The package declares ISC. The later CheckSekolah directory reports 10,259 government schools (EMIS retrieved 4 Nov 2026) but no longer offers bulk CSV/JSON. See https://checksekolah.my/data/. This import is historical, not a certified exhaustive November 2026 list. It does not comprehensively cover private schools, international schools, MRSM or kindergartens.

- 10,198 source entries contained syntactically valid contact emails.
- 18 additional published contact addresses were sourced individually, including seven documented Johor corrections.
- 10,216 unique rows with a syntactically valid address, all 16 states/territories. 14 unresolved entries are excluded and listed separately.
- Published address ownership and deliverability are not verified; no emails were sent. Do not infer missing addresses from school codes.
- Only new normalized official school codes are inserted. No existing school or contact data is changed.
- New schools set `suppress_school_email=1`, `unsubscribed=1`, `jpn=0`, `invite_email=0`, `invite_read=0`, `download_file=0`, `total_students=0`. Do not bulk-enable communications.
- Existing 20261122 email suppression migration must be applied, MySQL schema checked, and backup taken first. Run the complete SQL in a single session (phpMyAdmin Import or MySQL CLI), not individual pasted statements. Test on a staging copy. Run during a maintenance window to avoid concurrent inserts. No Git deployment runs the import.
- Source-level checks: 10,230 unique source codes; 10,216 import rows with valid-format emails; 14 missing; 16 states. Live MySQL execution not performed.

| State | Source | Import with email |
| --- | ---: | ---: |
| JOHOR | 1192 | 1192 |
| KEDAH | 752 | 752 |
| KELANTAN | 596 | 595 |
| MELAKA | 316 | 315 |
| NEGERI SEMBILAN | 479 | 478 |
| PAHANG | 741 | 741 |
| PULAU PINANG | 398 | 397 |
| PERAK | 1105 | 1104 |
| PERLIS | 104 | 104 |
| SELANGOR | 940 | 938 |
| TERENGGANU | 506 | 505 |
| SABAH | 1296 | 1296 |
| SARAWAK | 1458 | 1455 |
| KUALA LUMPUR | 292 | 292 |
| LABUAN | 28 | 27 |
| PUTRAJAYA | 27 | 25 |
| Total | 10230 | 10216 |

## Supplemental sources

The seven Johor corrections are documented in [johor-school-sql-import.md](johor-school-sql-import.md).

- KFA8001: kfa8001@moe.edu.my — https://checksekolah.my/school/kedah/maahad-dini-sultan-abdul-halim/
- NCA4001: nca4001@moe.edu.my — https://sites.google.com/view/tahfiz-sertaqia
- CBC4053: cbc4053@moe.edu.my — https://checksekolah.my/school/pahang/sekolah-jenis-kebangsaan-cina-chung-ching-2/
- NBA4056: nba4056@moe.edu.my — https://sites.google.com/moe-dl.edu.my/sriseps2022/home
- NEA4122: nea4122@moe.edu.my — https://sites.google.com/moe-dl.edu.my/smknilaiimpian/
- PBA2041: pba2041@moe.edu.my — https://linktr.ee/pba2041
- AFA8001: afa8001@moe.edu.my — https://app.herepay.org/smrajadrnazrinshah/legasisarjana_2026
- BBD4065: bbd4065@moe.edu.my — https://myschoolchildren.com/data/SEK_REN_Selangor_files/sheet001.htm
- BBB0038: bbb0038@moe.edu.my — https://mys.databasesets.com/senarai-sekolah-rendah-dan-menengah-kerajaan/kod-sekolah/BBB0038
- YBA9101: yba9101@moe.edu.my — https://checksekolah.my/school/sarawak/sekolah-kebangsaan-orang-kaya-mohammad/
- AFA2002: sek.izzuddin.shah@gmail.com — https://sekizzuddinshah.edu.my/wp-content/uploads/2019/04/TakwimSIS2019.pdf

## Performance review (proposed, not implemented)

Current header cache TTL is 86,400 seconds; cache check occurs after the entry PHP has often queried MySQL or an external feed. Start with index.php, about-programme.php and tournaments.php as guest-only HTML snapshots on a six-hour schedule. Keep manuals.php static or longer-lived. School profile pages need a public-data audit before bulk caching; do not pre-render all 10,000 schools. Never cache authenticated portal pages, reports containing identifiable data, registration, login, payment, QR, verification, PSN, or application forms. Existing public cache blocklist should be replaced with a strict allowlist and an early cache hit before DB initialization. Use localized cache keys, atomic writes, version invalidation and retain last good output on failures. Hostinger cron at 00:00, 06:00, 12:00, 18:00 Malaysia time; actual scheduled generation requires a dedicated renderer/warmer and a verified server configuration.
