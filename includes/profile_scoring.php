<?php
declare(strict_types=1);

function profileTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function profileMaskName(?string $name): string
{
    $name = trim((string)$name);

    if ($name === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $length = mb_strlen($name, 'UTF-8');
        $visible = max(1, (int)ceil($length / 2));

        return rtrim(
            mb_substr($name, 0, $visible, 'UTF-8')
        ) . ' xxx';
    }

    $visible = max(1, (int)ceil(strlen($name) / 2));

    return rtrim(substr($name, 0, $visible)) . ' xxx';
}

function profileFormatMinutes(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    if ($hours > 0 && $remaining > 0) {
        return $hours . 'h ' . $remaining . 'm';
    }

    if ($hours > 0) {
        return $hours . 'h';
    }

    return $remaining . 'm';
}

function profileTournamentPoints(?int $place): int
{
    return match ($place) {
        1 => 50,
        2 => 25,
        3 => 15,
        default => 10,
    };
}

function profileTournamentPlaceLabel(?int $place): string
{
    return match ($place) {
        1 => '1st Place',
        2 => '2nd Place',
        3 => '3rd Place',
        default => 'Participant',
    };
}

function profileApplyRanks(array &$rows): void
{
    $ids = array_keys($rows);

    usort(
        $ids,
        static function ($a, $b) use ($rows): int {
            $scoreCompare =
                ((int)($rows[$b]['points'] ?? 0))
                <=>
                ((int)($rows[$a]['points'] ?? 0));

            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return ((int)$a) <=> ((int)$b);
        }
    );

    $position = 0;
    $rank = 0;
    $lastPoints = null;

    foreach ($ids as $id) {
        $position++;
        $points = (int)($rows[$id]['points'] ?? 0);

        if ($points <= 0) {
            $rows[$id]['rank'] = null;
            continue;
        }

        if ($lastPoints === null || $points !== $lastPoints) {
            $rank = $position;
            $lastPoints = $points;
        }

        $rows[$id]['rank'] = $rank;
    }
}

function profileScoringData(PDO $pdo): array
{
    static $cache = [];

    $cacheKey = spl_object_id($pdo);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $students = [];

    $stmt = $pdo->query(
        'SELECT id, school_id
         FROM students'
    );

    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['id'];

        $students[$id] = [
            'student_id' => $id,
            'school_id' => (int)$row['school_id'],
            'modules_completed' => 0,
            'module_points' => 0,
            'tournaments_joined' => 0,
            'tournament_points' => 0,
            'first_places' => 0,
            'second_places' => 0,
            'third_places' => 0,
            'points' => 0,
            'rank' => null,
        ];
    }

    if (
        $students
        && profileTableExists($pdo, 'student_module_progress')
    ) {
        $moduleStmt = $pdo->query(
            "SELECT
                student_id,
                COUNT(DISTINCT module_id) AS completed_modules
             FROM student_module_progress
             WHERE progress_status = 'completed'
             GROUP BY student_id"
        );

        foreach ($moduleStmt->fetchAll() as $row) {
            $studentId = (int)$row['student_id'];

            if (!isset($students[$studentId])) {
                continue;
            }

            $completed = (int)$row['completed_modules'];

            $students[$studentId]['modules_completed'] = $completed;
            $students[$studentId]['module_points'] = $completed * 10;
        }
    }

    $hasTournamentTables =
        profileTableExists($pdo, 'programme_applications')
        && profileTableExists($pdo, 'programme_application_students')
        && profileTableExists($pdo, 'programme_events')
        && profileTableExists($pdo, 'programme_winners');

    if ($students && $hasTournamentTables) {
        $tournamentSql = "
            SELECT
                p.student_id,
                p.event_id,
                p.application_id,
                MIN(w.place) AS place
            FROM (
                SELECT
                    pa.student_id,
                    pa.event_id,
                    pa.id AS application_id
                FROM programme_applications pa
                WHERE pa.student_id IS NOT NULL
                  AND pa.status IN ('applied','approved')

                UNION ALL

                SELECT
                    pas.student_id,
                    pas.event_id,
                    pas.application_id
                FROM programme_application_students pas
                JOIN programme_applications pa
                  ON pa.id = pas.application_id
                WHERE pa.status IN ('applied','approved')
            ) p
            JOIN programme_events e
              ON e.id = p.event_id
             AND e.event_type = 'tournament'
            LEFT JOIN programme_winners w
              ON w.event_id = p.event_id
             AND w.application_id = p.application_id
            GROUP BY
                p.student_id,
                p.event_id,
                p.application_id
        ";

        $eventBest = [];

        foreach ($pdo->query($tournamentSql)->fetchAll() as $row) {
            $studentId = (int)$row['student_id'];
            $eventId = (int)$row['event_id'];

            if (!isset($students[$studentId])) {
                continue;
            }

            $place = $row['place'] !== null
                ? (int)$row['place']
                : null;

            $points = profileTournamentPoints($place);
            $key = $studentId . ':' . $eventId;

            if (
                !isset($eventBest[$key])
                || $points > $eventBest[$key]['points']
            ) {
                $eventBest[$key] = [
                    'student_id' => $studentId,
                    'event_id' => $eventId,
                    'place' => $place,
                    'points' => $points,
                ];
            }
        }

        foreach ($eventBest as $event) {
            $studentId = (int)$event['student_id'];
            $place = $event['place'];

            $students[$studentId]['tournaments_joined']++;
            $students[$studentId]['tournament_points'] +=
                (int)$event['points'];

            if ($place === 1) {
                $students[$studentId]['first_places']++;
            } elseif ($place === 2) {
                $students[$studentId]['second_places']++;
            } elseif ($place === 3) {
                $students[$studentId]['third_places']++;
            }
        }
    }

    foreach ($students as &$student) {
        $student['points'] =
            (int)$student['module_points']
            + (int)$student['tournament_points'];
    }
    unset($student);

    profileApplyRanks($students);

    $schools = [];

    foreach (
        $pdo->query('SELECT id FROM schools')->fetchAll()
        as $row
    ) {
        $id = (int)$row['id'];

        $schools[$id] = [
            'school_id' => $id,
            'points' => 0,
            'rank' => null,
        ];
    }

    foreach ($students as $student) {
        $schoolId = (int)$student['school_id'];

        if (!isset($schools[$schoolId])) {
            continue;
        }

        $schools[$schoolId]['points'] +=
            (int)$student['points'];
    }

    profileApplyRanks($schools);

    $teachers = [];

    foreach (
        $pdo->query('SELECT id FROM teachers')->fetchAll()
        as $row
    ) {
        $id = (int)$row['id'];

        $teachers[$id] = [
            'teacher_id' => $id,
            'students' => 0,
            'points' => 0,
            'rank' => null,
        ];
    }

    if (
        $teachers
        && profileTableExists($pdo, 'student_coach_assignments')
    ) {
        $assignmentStmt = $pdo->query(
            'SELECT student_id, teacher_id
             FROM student_coach_assignments'
        );

        foreach ($assignmentStmt->fetchAll() as $row) {
            $studentId = (int)$row['student_id'];
            $teacherId = (int)$row['teacher_id'];

            if (
                !isset($teachers[$teacherId])
                || !isset($students[$studentId])
            ) {
                continue;
            }

            $teachers[$teacherId]['students']++;
            $teachers[$teacherId]['points'] +=
                (int)$students[$studentId]['points'];
        }
    }

    profileApplyRanks($teachers);

    return $cache[$cacheKey] = [
        'students' => $students,
        'schools' => $schools,
        'teachers' => $teachers,
    ];
}

function profileRankLabel(?int $rank, int $points): string
{
    if ($points <= 0 || $rank === null) {
        return 'Not Ranked';
    }

    return '#' . number_format($rank);
}

function profileStudentIdsForSchool(
    PDO $pdo,
    int $schoolId
): array {
    $stmt = $pdo->prepare(
        'SELECT id
         FROM students
         WHERE school_id = ?'
    );
    $stmt->execute([$schoolId]);

    return array_map(
        'intval',
        array_column($stmt->fetchAll(), 'id')
    );
}

function profileStudentIdsForTeacher(
    PDO $pdo,
    int $teacherId
): array {
    if (!profileTableExists($pdo, 'student_coach_assignments')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT student_id
         FROM student_coach_assignments
         WHERE teacher_id = ?
         ORDER BY assigned_at DESC'
    );
    $stmt->execute([$teacherId]);

    return array_map(
        'intval',
        array_column($stmt->fetchAll(), 'student_id')
    );
}

function profileAggregateStudentScores(
    array $scoreData,
    array $studentIds
): array {
    $summary = [
        'student_count' => count($studentIds),
        'modules_completed' => 0,
        'module_points' => 0,
        'tournaments_joined' => 0,
        'tournament_points' => 0,
        'first_places' => 0,
        'second_places' => 0,
        'third_places' => 0,
        'points' => 0,
    ];

    foreach ($studentIds as $studentId) {
        $student = $scoreData['students'][(int)$studentId] ?? null;

        if (!$student) {
            continue;
        }

        foreach (
            [
                'modules_completed',
                'module_points',
                'tournaments_joined',
                'tournament_points',
                'first_places',
                'second_places',
                'third_places',
                'points',
            ]
            as $key
        ) {
            $summary[$key] += (int)$student[$key];
        }
    }

    return $summary;
}

function profileActivitySummary(
    PDO $pdo,
    array $studentIds
): array {
    $summary = [
        'attendance_minutes' => 0,
        'attendance_sessions' => 0,
        'certificates' => 0,
    ];

    $studentIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $studentIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if (!$studentIds) {
        return $summary;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($studentIds), '?')
    );

    if (profileTableExists($pdo, 'attendance_sessions')) {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(
                    SUM(
                        CASE
                            WHEN status = 'completed'
                            THEN credited_minutes
                            ELSE 0
                        END
                    ),
                    0
                ) AS minutes,
                COALESCE(
                    SUM(
                        CASE
                            WHEN status = 'completed'
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS sessions
             FROM attendance_sessions
             WHERE student_id IN ($placeholders)"
        );
        $stmt->execute($studentIds);
        $row = $stmt->fetch();

        $summary['attendance_minutes'] =
            (int)($row['minutes'] ?? 0);

        $summary['attendance_sessions'] =
            (int)($row['sessions'] ?? 0);
    }

    if (profileTableExists($pdo, 'issued_certificates')) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM issued_certificates
             WHERE student_id IN ($placeholders)
               AND status = 'completed'"
        );
        $stmt->execute($studentIds);

        $summary['certificates'] =
            (int)$stmt->fetchColumn();
    }

    return $summary;
}

function profileAssignedTeacher(
    PDO $pdo,
    int $studentId
): ?array {
    if (!profileTableExists($pdo, 'student_coach_assignments')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            t.id,
            t.full_name,
            t.position,
            t.school_id,
            a.assigned_at
         FROM student_coach_assignments a
         JOIN teachers t
           ON t.id = a.teacher_id
         WHERE a.student_id = ?
         LIMIT 1'
    );
    $stmt->execute([$studentId]);

    return $stmt->fetch() ?: null;
}

function profileStudentTournamentRows(
    PDO $pdo,
    int $studentId
): array {
    if (
        !profileTableExists($pdo, 'programme_applications')
        || !profileTableExists($pdo, 'programme_application_students')
        || !profileTableExists($pdo, 'programme_events')
        || !profileTableExists($pdo, 'programme_winners')
    ) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT
            p.event_id,
            p.application_id,
            e.title,
            e.event_level,
            e.start_at,
            MIN(w.place) AS place
         FROM (
            SELECT
                pa.event_id,
                pa.id AS application_id
            FROM programme_applications pa
            WHERE pa.student_id = ?
              AND pa.status IN ('applied','approved')

            UNION ALL

            SELECT
                pas.event_id,
                pas.application_id
            FROM programme_application_students pas
            JOIN programme_applications pa
              ON pa.id = pas.application_id
            WHERE pas.student_id = ?
              AND pa.status IN ('applied','approved')
         ) p
         JOIN programme_events e
           ON e.id = p.event_id
          AND e.event_type = 'tournament'
         LEFT JOIN programme_winners w
           ON w.event_id = p.event_id
          AND w.application_id = p.application_id
         GROUP BY
            p.event_id,
            p.application_id,
            e.title,
            e.event_level,
            e.start_at
         ORDER BY
            COALESCE(e.start_at, '1970-01-01') DESC,
            e.title"
    );
    $stmt->execute([$studentId, $studentId]);

    $bestByEvent = [];

    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        $place = $row['place'] !== null
            ? (int)$row['place']
            : null;

        $points = profileTournamentPoints($place);

        if (
            !isset($bestByEvent[$eventId])
            || $points > $bestByEvent[$eventId]['points']
        ) {
            $row['place'] = $place;
            $row['points'] = $points;
            $bestByEvent[$eventId] = $row;
        }
    }

    return array_values($bestByEvent);
}

function profileTournamentRowsForStudents(
    PDO $pdo,
    array $studentIds,
    ?array $scoreData = null
): array {
    $studentIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $studentIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if (
        !$studentIds
        || !profileTableExists($pdo, 'programme_applications')
        || !profileTableExists($pdo, 'programme_application_students')
        || !profileTableExists($pdo, 'programme_events')
        || !profileTableExists($pdo, 'programme_winners')
    ) {
        return [];
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($studentIds), '?')
    );

    $params = [
        ...$studentIds,
        ...$studentIds,
    ];

    $stmt = $pdo->prepare(
        "SELECT
            p.student_id,
            p.event_id,
            p.application_id,
            st.full_name AS student_name,
            st.student_number,
            e.title,
            e.event_level,
            e.start_at,
            MIN(w.place) AS place
         FROM (
            SELECT
                pa.student_id,
                pa.event_id,
                pa.id AS application_id
            FROM programme_applications pa
            WHERE pa.student_id IN ($placeholders)
              AND pa.status IN ('applied','approved')

            UNION ALL

            SELECT
                pas.student_id,
                pas.event_id,
                pas.application_id
            FROM programme_application_students pas
            JOIN programme_applications pa
              ON pa.id = pas.application_id
            WHERE pas.student_id IN ($placeholders)
              AND pa.status IN ('applied','approved')
         ) p
         JOIN students st
           ON st.id = p.student_id
         JOIN programme_events e
           ON e.id = p.event_id
          AND e.event_type = 'tournament'
         LEFT JOIN programme_winners w
           ON w.event_id = p.event_id
          AND w.application_id = p.application_id
         GROUP BY
            p.student_id,
            p.event_id,
            p.application_id,
            st.full_name,
            st.student_number,
            e.title,
            e.event_level,
            e.start_at
         ORDER BY
            COALESCE(e.start_at, '1970-01-01') DESC,
            e.title,
            st.full_name"
    );

    $stmt->execute($params);

    $scoreData = $scoreData ?? profileScoringData($pdo);
    $bestRows = [];

    foreach ($stmt->fetchAll() as $row) {
        $studentId = (int)$row['student_id'];
        $eventId = (int)$row['event_id'];
        $place = $row['place'] !== null
            ? (int)$row['place']
            : null;

        $points = profileTournamentPoints($place);
        $key = $studentId . ':' . $eventId;

        $row['place'] = $place;
        $row['points'] = $points;
        $row['student_points'] =
            (int)($scoreData['students'][$studentId]['points'] ?? 0);

        if (
            !isset($bestRows[$key])
            || $points > (int)$bestRows[$key]['points']
        ) {
            $bestRows[$key] = $row;
        }
    }

    $rows = array_values($bestRows);

    usort(
        $rows,
        static function (array $a, array $b): int {
            $pointsCompare =
                ((int)$b['points'])
                <=>
                ((int)$a['points']);

            if ($pointsCompare !== 0) {
                return $pointsCompare;
            }

            $dateA = !empty($a['start_at'])
                ? strtotime((string)$a['start_at'])
                : 0;

            $dateB = !empty($b['start_at'])
                ? strtotime((string)$b['start_at'])
                : 0;

            return $dateB <=> $dateA;
        }
    );

    return $rows;
}

function profileCertificateRowsForStudents(
    PDO $pdo,
    array $studentIds,
    ?array $scoreData = null
): array {
    $studentIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $studentIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if (
        !$studentIds
        || !profileTableExists($pdo, 'issued_certificates')
        || !profileTableExists($pdo, 'certificate_templates')
    ) {
        return [];
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($studentIds), '?')
    );

    $stmt = $pdo->prepare(
        "SELECT
            ic.id,
            ic.student_id,
            ic.certificate_number,
            ic.completion_date,
            ic.created_at,
            ct.certificate_title,
            st.full_name AS student_name,
            st.student_number
         FROM issued_certificates ic
         JOIN certificate_templates ct
           ON ct.id = ic.template_id
         JOIN students st
           ON st.id = ic.student_id
         WHERE ic.student_id IN ($placeholders)
           AND ic.status = 'completed'
         ORDER BY
            COALESCE(ic.completion_date, DATE(ic.created_at)) DESC,
            ic.id DESC"
    );

    $stmt->execute($studentIds);

    $scoreData = $scoreData ?? profileScoringData($pdo);
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $studentId = (int)$row['student_id'];

        $row['student_points'] =
            (int)($scoreData['students'][$studentId]['points'] ?? 0);

        $rows[] = $row;
    }

    return $rows;
}

