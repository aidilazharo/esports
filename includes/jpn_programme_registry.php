<?php
declare(strict_types=1);

function jpnProgrammeDefaultRows(): array
{
    $names = [
        ['JOHOR', 'Jabatan Pendidikan Negeri Johor'],
        ['KEDAH', 'Jabatan Pendidikan Negeri Kedah'],
        ['KELANTAN', 'Jabatan Pendidikan Negeri Kelantan'],
        ['MELAKA', 'Jabatan Pendidikan Negeri Melaka'],
        ['NEGERI_SEMBILAN', 'Jabatan Pendidikan Negeri Sembilan'],
        ['PAHANG', 'Jabatan Pendidikan Negeri Pahang'],
        ['PERAK', 'Jabatan Pendidikan Negeri Perak'],
        ['PERLIS', 'Jabatan Pendidikan Negeri Perlis'],
        ['PULAU_PINANG', 'Jabatan Pendidikan Negeri Pulau Pinang'],
        ['SABAH', 'Jabatan Pendidikan Negeri Sabah'],
        ['SARAWAK', 'Jabatan Pendidikan Negeri Sarawak'],
        ['SELANGOR', 'Jabatan Pendidikan Negeri Selangor'],
        ['TERENGGANU', 'Jabatan Pendidikan Negeri Terengganu'],
        ['WPKL', 'Jabatan Pendidikan Wilayah Persekutuan Kuala Lumpur'],
        ['WPLABUAN', 'Jabatan Pendidikan Wilayah Persekutuan Labuan'],
        ['WPPUTRAJAYA', 'Jabatan Pendidikan Wilayah Persekutuan Putrajaya'],
    ];

    $rows = [];

    foreach ($names as [$code, $name]) {
        $isPahang = $code === 'PAHANG';

        $rows[] = [
            'id' => null,
            'code' => $code,
            'jpn_name' => $name,
            'status' => $isPahang
                ? 'approved'
                : 'pending_approval',
            'approval_date' => $isPahang
                ? '2026-08-01'
                : null,
            'programme_end_date' => $isPahang
                ? '2026-12-31'
                : null,
            'letter_pdf_path' => null,
        ];
    }

    return $rows;
}

function jpnProgrammeTableExists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'programme_jpn_authorisations'"
        );

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function jpnProgrammeRows(PDO $pdo): array
{
    if (!jpnProgrammeTableExists($pdo)) {
        return jpnProgrammeDefaultRows();
    }

    try {
        $stmt = $pdo->query(
            "SELECT
                id,
                code,
                jpn_name,
                status,
                approval_date,
                programme_end_date,
                letter_pdf_path
             FROM programme_jpn_authorisations
             ORDER BY
                CASE status
                    WHEN 'approved' THEN 0
                    WHEN 'active' THEN 1
                    WHEN 'pending_approval' THEN 2
                    WHEN 'completed' THEN 3
                    ELSE 4
                END ASC,
                jpn_name ASC"
        );

        $rows = $stmt->fetchAll();

        return $rows ?: jpnProgrammeDefaultRows();
    } catch (Throwable $e) {
        error_log(
            'Unable to load JPN programme rows: '
            . $e->getMessage()
        );

        return jpnProgrammeDefaultRows();
    }
}

function jpnProgrammeFind(
    PDO $pdo,
    string $code
): ?array {
    $code = strtoupper(
        trim($code)
    );

    if ($code === '') {
        return null;
    }

    foreach (jpnProgrammeRows($pdo) as $row) {
        if (
            strtoupper(
                (string)($row['code'] ?? '')
            ) === $code
        ) {
            return $row;
        }
    }

    return null;
}

function jpnProgrammeStatusMeta(
    string $status
): array {
    return match ($status) {
        'approved' => [
            'label' => 'Approved',
            'badge' => 'success',
            'order' => 0,
        ],
        'active' => [
            'label' => 'Programme Active',
            'badge' => 'primary',
            'order' => 1,
        ],
        'completed' => [
            'label' => 'Completed',
            'badge' => 'secondary',
            'order' => 3,
        ],
        default => [
            'label' => 'Pending Approval',
            'badge' => 'warning',
            'order' => 2,
        ],
    };
}

function jpnProgrammeDisplayDate(
    mixed $value
): string {
    $value = trim(
        (string)$value
    );

    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date('d M Y', $timestamp)
        : '-';
}
