# Johor SQL import: 1,192 school codes, names and emails

The SQL import (`database/imports/johor_schools_with_email.sql`) contains **1,192 Johor government-school entries** with email addresses and an audit CSV (`database/imports/johor_schools_with_email.csv`).

## Dataset and limitations
- Original rows come from the **June 2022 KPM-derived dataset**, [MySchoolList v1.2.0](https://github.com/apitlekays/MySchoolList), which declares ISC in its package.
- Seven missing or malformed source email fields were supplemented individually from public school/contact listings (below).
- The 2026 directory at https://ekoko.my/sekolah/negeri/johor lists 1,192 schools; another KPM-derived directory retrieved in November 2026 reports **1,198**: https://checksekolah.my/state/johor. **Therefore these 1,192 rows must not be assumed to be every current 2026 Johor school.**
- Email strings are valid in form, but **delivery, ownership and current validity are not verified**. One email is a publicly listed school Gmail address, not a KPM domain.
- Secondary, primary, vocational, religious and other KPM government school types are represented; private and international schools are outside this source.

## Seven supplemental public email sources

| School code | Email | Public source |
|---|---|---|
| JBC1050 | sjkccheahfah1@gmail.com | https://sekolah2u.com/listing/sekolah-jenis-kebangsaan-cina-cheah-fah/ |
| JBA2038 | jba2038@moe.edu.my | https://mys.databasesets.com/senarai-sekolah-rendah-dan-menengah-kerajaan/kod-sekolah/JBA2038 |
| JBA8036 | jba8036@moe.edu.my | https://checksekolah.my/school/johor/sekolah-kebangsaan-taman-pulai-emas/ |
| JEA8016 | jea8016@moe.edu.my | https://cdnc.heyzine.com/files/uploaded/v2/c61aaf352cc8f351c1eb01ecd0018afa40d553eb.pdf |
| JHA5002 | jha5002@moe.edu.my | https://www.tvet.gov.my/awam-agensi/5/senarai-institusi?page=3 |
| JRA5038 | jra5038@moe.edu.my | https://sites.google.com/view/kokurikulum-hmy/home |
| JBD1029 | jbd1029@moe.edu.my | https://checksekolah.my/school/johor/sekolah-jenis-kebangsaan-tamil-bandar-seri-alam/ |

## Import safety and steps
- The SQL loads a private **TEMPORARY** staging table and then inserts only **new** school codes into `schools`.
- It **never edits existing schools**, even when an existing email is blank; this prevents overwriting current participants or their preferences.
- New rows always set `suppress_school_email=1`, `unsubscribed=1`, `jpn=0`, `invite_email=0`, `invite_read=0`, `download_file=0`, `total_students=0`. Contact names, phones and logos are blank. Emails are stored for directory reference only, **never used to send Brevo** until an authorized administrator explicitly activates the school.
- This is not a schema migration and requires the existing `suppress_school_email` migration first.
- Back up MySQL, inspect `SHOW CREATE TABLE schools;`, and test on a staging copy before using the SQL on Hostinger. All referenced columns and NOT NULL constraints must be compatible.
- Apply the migration from PHP CLI: `php scripts/migrate.php apply --backup-confirmed`.
- Run the SQL in phpMyAdmin's **Import** page or MySQL CLI: `mysql -u YOUR_USER -p YOUR_DATABASE < database/imports/johor_schools_with_email.sql`.
- Verify output: the staging row count should be 1,192; `newly_imported` may be lower if schools already exist; those rows are skipped.
- GitHub deployment does **not** execute this SQL automatically or alter Hostinger MySQL.

The documented email-suppression safeguard for school invitations, login PINs and monthly reports applies to all newly inserted schools. Do not treat a populated `contact_email` as consent to receive communications.
