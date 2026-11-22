<?php
declare(strict_types=1);

function jpnReportRange(
    string $period,
    ?string $month = null,
    ?int $year = null
): array {
    $period = strtolower(trim($period));

    if ($period === 'monthly') {
        $month = trim((string)$month);

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $month . '-01'
        );

        if (!$start) {
            $start = new DateTimeImmutable('first day of this month');
        }

        $end = $start->modify('first day of next month');

        return [
            'period' => 'monthly',
            'start' => $start->format('Y-m-d 00:00:00'),
            'end' => $end->format('Y-m-d 00:00:00'),
            'label' => $start->format('F Y'),
            'month' => $start->format('Y-m'),
            'year' => (int)$start->format('Y'),
        ];
    }

    $year = $year ?: (int)date('Y');
    $year = max(2020, min(2100, $year));

    return [
        'period' => 'yearly',
        'start' => $year . '-01-01 00:00:00',
        'end' => ($year + 1) . '-01-01 00:00:00',
        'label' => (string)$year,
        'month' => null,
        'year' => $year,
    ];
}

function jpnReportSchoolRows(
    PDO $pdo,
    array $scope,
    array $range
): array {
    $sql = "
        SELECT
            s.id,
            s.school_code,
            s.school_name,
            s.district,
            s.state,

            COALESCE(st.malay_m,0) AS malay_m,
            COALESCE(st.malay_f,0) AS malay_f,
            COALESCE(st.chinese_m,0) AS chinese_m,
            COALESCE(st.chinese_f,0) AS chinese_f,
            COALESCE(st.indian_m,0) AS indian_m,
            COALESCE(st.indian_f,0) AS indian_f,
            COALESCE(st.asli_m,0) AS asli_m,
            COALESCE(st.asli_f,0) AS asli_f,
            COALESCE(st.other_m,0) AS other_m,
            COALESCE(st.other_f,0) AS other_f,
            COALESCE(st.total_students,0) AS total_students,
            COALESCE(tc.total_teachers,0) AS total_teachers

        FROM schools s

        LEFT JOIN (
            SELECT
                school_id,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'malay' AND gender='Male' THEN 1 ELSE 0 END) AS malay_m,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'malay' AND gender='Female' THEN 1 ELSE 0 END) AS malay_f,

                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'chinese' AND gender='Male' THEN 1 ELSE 0 END) AS chinese_m,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'chinese' AND gender='Female' THEN 1 ELSE 0 END) AS chinese_f,

                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'indian' AND gender='Male' THEN 1 ELSE 0 END) AS indian_m,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) = 'indian' AND gender='Female' THEN 1 ELSE 0 END) AS indian_f,

                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) IN ('orang asli','asli') AND gender='Male' THEN 1 ELSE 0 END) AS asli_m,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) IN ('orang asli','asli') AND gender='Female' THEN 1 ELSE 0 END) AS asli_f,

                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) NOT IN ('malay','chinese','indian','orang asli','asli')
                         AND gender='Male' THEN 1 ELSE 0 END) AS other_m,
                SUM(CASE WHEN LOWER(COALESCE(ethnicity,'')) NOT IN ('malay','chinese','indian','orang asli','asli')
                         AND gender='Female' THEN 1 ELSE 0 END) AS other_f,

                COUNT(*) AS total_students
            FROM students
            WHERE created_at >= ?
              AND created_at < ?
            GROUP BY school_id
        ) st ON st.school_id = s.id

        LEFT JOIN (
            SELECT
                school_id,
                COUNT(*) AS total_teachers
            FROM teachers
            WHERE created_at >= ?
              AND created_at < ?
            GROUP BY school_id
        ) tc ON tc.school_id = s.id

        WHERE {$scope['sql']}
          AND (
              COALESCE(st.total_students,0) > 0
              OR COALESCE(tc.total_teachers,0) > 0
          )

        ORDER BY s.district, s.school_name
    ";

    $params = [
        $range['start'],
        $range['end'],
        $range['start'],
        $range['end'],
        ...$scope['params'],
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function jpnReportTotals(array $rows): array
{
    $keys = [
        'malay_m','malay_f',
        'chinese_m','chinese_f',
        'indian_m','indian_f',
        'asli_m','asli_f',
        'other_m','other_f',
        'total_students','total_teachers',
    ];

    $totals = array_fill_keys($keys, 0);
    $totals['schools'] = count($rows);

    foreach ($rows as $row) {
        foreach ($keys as $key) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
    }

    return $totals;
}

function jpnCurrentActiveStudents(
    PDO $pdo,
    array $scope
): int {
    $sql = "
        SELECT COUNT(*)
        FROM students st
        JOIN schools s ON s.id = st.school_id
        WHERE {$scope['sql']}
          AND st.membership_status = 'active'
          AND st.membership_next_due_at IS NOT NULL
          AND st.membership_next_due_at >= NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);

    return (int)$stmt->fetchColumn();
}
