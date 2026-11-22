<?php

declare(strict_types=1);

function programmeTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function programmeColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function programmeSchemaReady(PDO $pdo): bool
{
    return programmeTableExists($pdo, 'programmes')
        && programmeTableExists($pdo, 'programme_learning_modules')
        && programmeTableExists($pdo, 'programme_event_links');
}

function programmeCurrent(PDO $pdo): ?array
{
    if (!programmeSchemaReady($pdo)) {
        return null;
    }

    $row = $pdo->query("SELECT * FROM programmes WHERE is_default = 1 ORDER BY id LIMIT 1")->fetch();
    if ($row) {
        return $row;
    }

    $row = $pdo->query("SELECT * FROM programmes WHERE status = 'active' ORDER BY id LIMIT 1")->fetch();
    return $row ?: null;
}

function programmeAll(PDO $pdo, bool $activeOnly = false): array
{
    if (!programmeSchemaReady($pdo)) {
        return [];
    }

    $sql = 'SELECT * FROM programmes';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY is_default DESC, name';
    return $pdo->query($sql)->fetchAll();
}

function programmeMoney(int $minor): string
{
    return 'RM ' . number_format($minor / 100, 2);
}

function programmeAmountToMinor(string $value): int
{
    $clean = preg_replace('/[^0-9.\-]/', '', trim($value)) ?? '0';
    return max(0, (int) round((float) $clean * 100));
}

function programmeCodeFromName(string $name): string
{
    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)));
    $code = trim($code, '-');
    if ($code === '') {
        $code = 'PROGRAMME';
    }
    return substr($code, 0, 28);
}

function programmeUniqueCode(PDO $pdo, string $name, int $ignoreId = 0): string
{
    $base = programmeCodeFromName($name);
    $code = $base;
    $n = 1;
    while (true) {
        $sql = 'SELECT id FROM programmes WHERE code = ?';
        $params = [$code];
        if ($ignoreId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
        $n++;
        $suffix = '-' . $n;
        $code = substr($base, 0, max(1, 32 - strlen($suffix))) . $suffix;
    }
}
