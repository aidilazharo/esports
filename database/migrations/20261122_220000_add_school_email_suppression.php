<?php
declare(strict_types=1);

/**
 * Preserve existing participating schools' email behavior.
 * Directory-only imports explicitly set suppress_school_email=1.
 */
return static function (PDO $pdo): void {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'schools'
           AND COLUMN_NAME = 'suppress_school_email'"
    );
    $exists->execute();
    if ((int) $exists->fetchColumn() !== 0) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE schools
         ADD COLUMN suppress_school_email TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1=directory-only school; block school-directed Brevo emails'"
    );
};
