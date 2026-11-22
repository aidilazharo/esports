<?php
declare(strict_types=1);

/**
 * Import-only schools must not receive any school-directed Brevo emails.
 * This guard is independent of invitation opt-out and JPN approval.
 */
function schoolEmailSuppressionColumnExists(PDO $pdo): bool
{
    static $known = null;
    if ($known !== null) {
        return $known;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools'
           AND COLUMN_NAME = 'suppress_school_email'"
    );
    $stmt->execute();
    $known = (int) $stmt->fetchColumn() > 0;
    return $known;
}

function schoolEmailIsSuppressed(PDO $pdo, int $schoolId): bool
{
    if ($schoolId <= 0) {
        return true;
    }
    // Backwards compatibility while the new database migration is pending:
    // historical registrations keep their previous communication behavior.
    if (!schoolEmailSuppressionColumnExists($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT suppress_school_email FROM schools WHERE id = ? LIMIT 1');
    $stmt->execute([$schoolId]);
    $value = $stmt->fetchColumn();
    return $value === false || (int) $value === 1;
}

function schoolEmailSuppressionWhere(PDO $pdo): string
{
    return schoolEmailSuppressionColumnExists($pdo)
        ? ' AND COALESCE(suppress_school_email, 0) = 0'
        : '';
}
