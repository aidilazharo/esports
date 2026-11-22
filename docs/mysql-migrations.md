# MySQL schema and CRUD management

The portal uses MySQL via PDO in `config.php`, plus the existing role-specific CRUD code:
`includes/admin_crud.php`, `includes/school_crud.php`, `includes/teacher_crud.php` and their respective `_configs.php`.

## Schema updates
Store one immutable PHP migration per change in `database/migrations/`. Use this format:

```php
<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE schools ADD COLUMN sample_flag TINYINT(1) NOT NULL DEFAULT 0");
};
```

Save it as e.g. `20261122_170000_add_sample_flag.php` after confirming the column does not exist. **This is an illustration; no example schema alteration is shipped or applied.** Use additive, backward-compatible migrations before switching PHP code to rely on new fields.

1. Export/backup the production MySQL database via Hostinger/phpMyAdmin or `mysqldump`.
2. On a staging copy, run `php scripts/migrate.php status`, then `php scripts/migrate.php apply --backup-confirmed`.
3. Test registration, payments, role-specific forms and reports.
4. Deploy code, then separately execute the migration on production via SSH, with a verified backup and maintenance plan.
5. Re-run `status` and inspect logs. Each migration's SHA-256 checksum is recorded in `schema_migrations` to prevent accidental modification/replay.

The runner is **CLI only**, requires the explicit backup confirmation flag, takes a MySQL advisory lock, checks file checksums and records successful migrations. It never executes migration scripts during normal HTTP requests. MySQL DDL commonly **implicitly commits**, so do not assume a PHP transaction can roll back an ALTER TABLE. Plan reversals with a new migration and a tested backup/restore procedure.

## CRUD changes
- Define role-specific forms/fields and permitted tables in `admin/_configs.php`, `school/_configs.php` and `teacher/_configs.php`.
- Keep prepared parameterized queries through PDO; never accept table/column names directly from HTTP input.
- Validate authorization and school ownership on **every** create, read, update and delete, not only when rendering buttons.
- Keep CSRF checks on mutations, whitelist fields and validate data types.
- Add foreign keys, unique constraints and indexes in migrations after reviewing production data and query plans.
- Test each migration and affected CRUD pages with representative school, teacher, parent and administrator accounts.

## Not yet automated
Schema upgrades are intentionally **not auto-run on every Hostinger Git pull**, because a failed DDL change could break a live student/payments database. Automate them only after adding backups, staging tests, monitoring and an approved rollback strategy.
