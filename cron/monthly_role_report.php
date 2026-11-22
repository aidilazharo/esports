<?php
declare(strict_types=1);

/**
 * Monthly role dashboard email sender.
 *
 * Safe to run repeatedly. A recipient is only marked after a successful
 * email API response. `email_report` stores MMYYYY, e.g. 012026.
 *
 * Recommended cron:
 *   15 * * * * /usr/bin/php /path/to/esport/cron/monthly_role_report.php >> /path/to/logs/monthly_role_report.log 2>&1
 *
 * The script may run hourly for retry/batching purposes, while each recipient
 * still receives at most one report per calendar month.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/monthly_report_email.php';


if (
    PHP_SAPI !== 'cli'
    && session_status() !== PHP_SESSION_ACTIVE
) {
    session_start();
}

function monthlyCronReleaseSessionLock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function monthlyCronSendBrevoEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    string $text,
    string $tag
): array {
    $apiKey = trim(
        (string)(getenv('BREVO_API_KEY') ?: '')
    );

    $senderEmail = trim(
        (string)(getenv('BREVO_SENDER_EMAIL') ?: '')
    );

    $senderName = trim(
        (string)(
            getenv('BREVO_SENDER_NAME')
            ?: 'Metadata Berhad'
        )
    );

    $replyToEmail = trim(
        (string)(
            getenv('BREVO_REPLY_TO_EMAIL')
            ?: $senderEmail
        )
    );

    if ($apiKey === '') {
        return [
            'success' => false,
            'message' => 'BREVO_API_KEY is not configured.',
        ];
    }

    if (
        !filter_var(
            $senderEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'message' => 'BREVO_SENDER_EMAIL is not configured.',
        ];
    }

    if (
        !filter_var(
            $toEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'message' => 'Test recipient email is invalid.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'PHP cURL extension is not available.',
        ];
    }

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [[
            'name' => $toName !== ''
                ? $toName
                : $toEmail,
            'email' => $toEmail,
        ]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
        'tags' => [$tag],
    ];

    if (
        filter_var(
            $replyToEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $payload['replyTo'] = [
            'name' => $senderName,
            'email' => $replyToEmail,
        ];
    }

    $ch = curl_init(
        'https://api.brevo.com/v3/smtp/email'
    );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ),
        ]
    );

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if (
        $response === false
        || $curlError !== ''
    ) {
        return [
            'success' => false,
            'message' =>
                'Unable to connect to Brevo: '
                . $curlError,
        ];
    }

    if (
        $httpCode < 200
        || $httpCode >= 300
    ) {
        error_log(
            'Monthly report Brevo HTTP '
            . $httpCode
            . ': '
            . (string)$response
        );

        return [
            'success' => false,
            'message' =>
                'Brevo returned HTTP '
                . $httpCode
                . '. Check sender/API configuration.',
        ];
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
    ];
}



if (
    PHP_SAPI !== 'cli'
    && strtolower(
        trim(
            (string)($_GET['mode'] ?? '')
        )
    ) === 'test'
) {
    set_exception_handler(
        static function (Throwable $e): void {
            error_log(
                'Monthly report test error: '
                . $e->getMessage()
                . ' in '
                . $e->getFile()
                . ':'
                . $e->getLine()
            );

            if (!headers_sent()) {
                http_response_code(500);
                header(
                    'Content-Type: text/html; charset=UTF-8'
                );
            }

            echo '<h1>Test email error</h1>';
            echo '<p>'
                . monthlyReportEsc(
                    $e->getMessage()
                )
                . '</p>';
            echo '<p>Check the PHP error log if more detail is required.</p>';
        }
    );
}

/*
 * Browser test mode using REAL account data.
 *
 * Example:
 *   /cron/monthly_role_report.php?mode=test&role=parent&email=parentname@me.com
 *
 * `email` identifies the real source account only.
 * Test delivery is ALWAYS forced to aidilazhar@me.com.
 * Test mode never updates email_report and requires no portal login.
 */
if (
    PHP_SAPI !== 'cli'
    && strtolower(trim((string)($_GET['mode'] ?? ''))) === 'test'
) {
    header('Content-Type: text/html; charset=UTF-8');

    $configuredTestSecret = trim(
        (string)(getenv('MONTHLY_REPORT_TEST_SECRET') ?: '')
    );

    if ($configuredTestSecret !== '') {
        $providedTestSecret = trim((string)($_GET['key'] ?? ''));

        if (
            $providedTestSecret === ''
            || !hash_equals($configuredTestSecret, $providedTestSecret)
        ) {
            http_response_code(403);
            exit(
                '<h1>Invalid test key</h1>'
                . '<p>The monthly report test key is missing or incorrect.</p>'
            );
        }
    }

    $testRole = strtolower(trim((string)($_GET['role'] ?? 'parent')));
    $allowedTestRoles = ['school', 'teacher', 'parent', 'jpn'];

    if (!in_array($testRole, $allowedTestRoles, true)) {
        http_response_code(400);
        exit('<h1>Invalid role</h1><p>Use: school, teacher, parent, or jpn.</p>');
    }

    $sourceEmail = strtolower(trim((string)($_GET['email'] ?? '')));

    if ($sourceEmail === '' || !filter_var($sourceEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit(
            '<h1>Source email required</h1>'
            . '<p>Example: <code>?mode=test&amp;role=parent&amp;email=parentname@me.com</code></p>'
        );
    }

    $testDeliveryEmail = 'aidilazhar@me.com';
    $testDeliveryName = 'Aidil Azhar';
    $testPeriod = date('F Y');
    $testMonthStart = date('Y-m-01 00:00:00');
    $testNextMonthStart = date(
        'Y-m-01 00:00:00',
        strtotime('first day of next month')
    );
    $testAttendanceMonth = date('Y-m-01');

    if ($testRole === 'school') {
        $stmt = $pdo->prepare(
            "SELECT id, school_name AS recipient_name, contact_email AS email, school_code
             FROM schools
             WHERE LOWER(TRIM(contact_email)) = LOWER(TRIM(?))
             LIMIT 1"
        );
    } elseif ($testRole === 'teacher') {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.full_name AS recipient_name, t.email,
                    t.school_id, s.school_name, s.school_code
             FROM teachers t
             JOIN schools s ON s.id = t.school_id
             WHERE LOWER(TRIM(t.email)) = LOWER(TRIM(?))
             LIMIT 1"
        );
    } elseif ($testRole === 'parent') {
        $stmt = $pdo->prepare(
            "SELECT id, full_name AS recipient_name, email, guardian_number
             FROM guardians
             WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name AS recipient_name, email, position,
                    department, scope_state, scope_district
             FROM jpn_officials
             WHERE is_active = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             LIMIT 1"
        );
    }

    $stmt->execute([$sourceEmail]);
    $sourceRecipient = $stmt->fetch();

    if (!$sourceRecipient) {
        http_response_code(404);
        exit(
            '<h1>Account not found</h1>'
            . '<p>No matching '
            . monthlyReportEsc($testRole)
            . ' account was found.</p>'
        );
    }

    $sourceId = (int)$sourceRecipient['id'];
    $sourceName = trim((string)$sourceRecipient['recipient_name']);

    if ($testRole === 'school') {
        $report = monthlyCronSchoolData(
            $pdo, $sourceId, $testMonthStart,
            $testNextMonthStart, $testAttendanceMonth
        );

        $emailData = monthlyReportBuildEmail(
            'school', $sourceName, $testPeriod,
            $report['metrics'], 'Equipment & Participation',
            $report['highlight'], '/school/'
        );
    } elseif ($testRole === 'teacher') {
        $report = monthlyCronTeacherData(
            $pdo, $sourceId, (int)$sourceRecipient['school_id'],
            $testMonthStart, $testNextMonthStart, $testAttendanceMonth
        );

        $schoolLabel = trim((string)$sourceRecipient['school_name']);

        $emailData = monthlyReportBuildEmail(
            'teacher', $sourceName, $testPeriod,
            $report['metrics'], 'Teaching Dashboard',
            $report['highlight']
                . ($schoolLabel !== ''
                    ? '<br><strong>School:</strong> ' . monthlyReportEsc($schoolLabel)
                    : ''),
            '/teacher/', $report['details'], 'Coached Students'
        );
    } elseif ($testRole === 'parent') {
        $report = monthlyCronParentData(
            $pdo, $sourceId, $testMonthStart,
            $testNextMonthStart, $testAttendanceMonth
        );

        $emailData = monthlyReportBuildEmail(
            'parent', $sourceName, $testPeriod,
            $report['metrics'], 'Family Programme Summary',
            $report['highlight'], '/parent/',
            $report['details'], 'Children'
        );
    } else {
        $report = monthlyCronJpnData(
            $pdo, $sourceRecipient, $testMonthStart,
            $testNextMonthStart, $testAttendanceMonth
        );

        $emailData = monthlyReportBuildEmail(
            'jpn', $sourceName, $testPeriod,
            $report['metrics'], 'JPN Reporting Scope',
            $report['highlight'], '/jpn/',
            $report['details'], 'Top Active Schools'
        );
    }

    $testNotice =
        '<div style="margin-bottom:10px;padding:10px;border:1px solid #d1d5db;'
        . 'border-radius:8px;background:#f9fafb;color:#111827;font-size:12px;line-height:1.5;">'
        . '<strong>TEST — REAL DATA</strong><br>'
        . 'Source account: ' . monthlyReportEsc($sourceEmail)
        . '<br>Delivered only to: ' . monthlyReportEsc($testDeliveryEmail)
        . '<br>email_report is not updated.'
        . '</div>';

    $emailData['html'] = str_replace(
        '<td style="padding:6px 20px 4px;">',
        '<td style="padding:6px 20px 4px;">' . $testNotice,
        (string)$emailData['html']
    );

    $emailData['subject'] = '[TEST REAL DATA] ' . (string)$emailData['subject'];
    $emailData['text'] =
        "TEST — REAL DATA\n"
        . 'Source account: ' . $sourceEmail
        . "\nDelivered only to: " . $testDeliveryEmail
        . "\nemail_report is not updated.\n\n"
        . (string)$emailData['text'];

    $send = monthlyCronSendBrevoEmail(
        $testDeliveryEmail,
        $testDeliveryName,
        (string)$emailData['subject'],
        (string)$emailData['html'],
        (string)$emailData['text'],
        'monthly-report-test-real-' . $testRole
    );

    if (empty($send['success'])) {
        http_response_code(500);
        exit(
            '<h1>Test email failed</h1><p>'
            . monthlyReportEsc((string)($send['message'] ?? 'Unknown email error.'))
            . '</p>'
        );
    }

    exit(
        '<h1>Real-data test email sent</h1>'
        . '<p><strong>Role:</strong> ' . monthlyReportEsc($testRole) . '</p>'
        . '<p><strong>Source account:</strong> ' . monthlyReportEsc($sourceEmail) . '</p>'
        . '<p><strong>Source name:</strong> ' . monthlyReportEsc($sourceName) . '</p>'
        . '<p><strong>Delivered to:</strong> ' . monthlyReportEsc($testDeliveryEmail) . '</p>'
        . '<p><strong>Monthly marker:</strong> not updated</p>'
    );
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only, except mode=test.\\n");
}

require_once dirname(__DIR__) . '/includes/jpn_report_service.php';

monthlyCronReleaseSessionLock();

$options = getopt(
    '',
    [
        'role::',
        'limit::',
        'dry-run',
        'force',
    ]
);

$requestedRole = strtolower(
    trim((string)($options['role'] ?? 'all'))
);

$allowedRoles = [
    'school',
    'teacher',
    'parent',
    'jpn',
];

if (
    $requestedRole !== 'all'
    && !in_array($requestedRole, $allowedRoles, true)
) {
    fwrite(
        STDERR,
        "Invalid --role. Use school, teacher, parent, jpn or all.\n"
    );
    exit(2);
}

$dryRun = array_key_exists(
    'dry-run',
    $options
);

$force = array_key_exists(
    'force',
    $options
);

$defaultLimit = max(
    1,
    (int)(
        getenv('MONTHLY_REPORT_BATCH_SIZE_PER_ROLE')
        ?: 100
    )
);

$limit = isset($options['limit'])
    ? max(1, (int)$options['limit'])
    : $defaultLimit;

$monthKey = date('mY');
$periodLabel = date('F Y');
$monthStart = date('Y-m-01 00:00:00');
$nextMonthStart = date(
    'Y-m-01 00:00:00',
    strtotime('first day of next month')
);
$attendanceMonth = date('Y-m-01');

$lockPath = sys_get_temp_dir()
    . '/metadata-monthly-role-report.lock';

$lockHandle = fopen($lockPath, 'c');

if (
    $lockHandle === false
    || !flock(
        $lockHandle,
        LOCK_EX | LOCK_NB
    )
) {
    echo "Another monthly report process is already running.\n";
    exit(0);
}

function monthlyCronLog(
    string $message
): void {
    echo '['
        . date('Y-m-d H:i:s')
        . '] '
        . $message
        . PHP_EOL;
}

function monthlyCronTableExists(
    PDO $pdo,
    string $table
): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function monthlyCronColumnExists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );

    $stmt->execute([
        $table,
        $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function monthlyCronScalar(
    PDO $pdo,
    string $sql,
    array $params = [],
    int|float|string $fallback = 0
): int|float|string {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return (
            $value === false
            || $value === null
        )
            ? $fallback
            : $value;
    } catch (Throwable $e) {
        error_log(
            'Monthly report scalar query failed: '
            . $e->getMessage()
        );

        return $fallback;
    }
}

function monthlyCronMoney(
    int $minor,
    string $currency = 'MYR'
): string {
    return strtoupper($currency)
        . ' '
        . number_format(
            $minor / 100,
            2
        );
}

function monthlyCronMembershipLabel(
    string $status
): string {
    $label = ucwords(
        str_replace(
            '_',
            ' ',
            strtolower(trim($status))
        )
    );

    return $label !== ''
        ? $label
        : 'Unknown';
}

function monthlyCronEquipmentSummary(
    int $registeredStudents,
    int $activeStudents
): string {
    $equipment = $registeredStudents >= 10
        ? '<strong>Equipment &amp; accessories:</strong> eligible'
        : '<strong>Equipment &amp; accessories:</strong> '
            . $registeredStudents
            . '/10 participants';

    $ps5Count = $activeStudents < 30
        ? 0
        : 1 + intdiv(
            $activeStudents - 30,
            30
        );

    $ps5 = $activeStudents >= 30
        ? '<strong>PS5 &amp; account activation:</strong> eligible'
            . ($ps5Count > 1
                ? ' (' . $ps5Count . ' PS5 eligibility levels)'
                : '')
        : '<strong>PS5 &amp; account activation:</strong> '
            . $activeStudents
            . '/30 active participants';

    $workstations = intdiv(
        max(0, $activeStudents),
        100
    );

    return $equipment
        . '<br>'
        . $ps5
        . '<br>'
        . '<strong>Workstation eligibility:</strong> '
        . $workstations;
}


function monthlyCronPct(int|float $part, int|float $whole): float
{
    if ($whole <= 0) {
        return 0.0;
    }

    return round(
        ((float)$part / (float)$whole) * 100,
        1
    );
}

function monthlyCronAdviceBlock(array $items): string
{
    $html = '';

    foreach ($items as $item) {
        $html .=
            '<div style="margin-bottom:8px;"><strong>'
            . monthlyReportEsc((string)($item['label'] ?? ''))
            . ':</strong> '
            . monthlyReportEsc((string)($item['text'] ?? ''))
            . '</div>';
    }

    return $html;
}

function monthlyCronBuildPerformanceAdvice(
    int $activeStudents,
    int $totalStudents,
    int $teachers,
    int $attendanceSessions,
    int $modulesCompleted,
    int $schoolCount = 1
): string {
    $activeRate = monthlyCronPct(
        $activeStudents,
        max(1, $totalStudents)
    );

    $attendancePerActive =
        $activeStudents > 0
            ? round(
                $attendanceSessions / $activeStudents,
                2
            )
            : 0.0;

    $modulesPerActive =
        $activeStudents > 0
            ? round(
                $modulesCompleted / $activeStudents,
                2
            )
            : 0.0;

    $studentsPerTeacher =
        $teachers > 0
            ? round(
                $activeStudents / $teachers,
                1
            )
            : 0.0;

    $avgActivePerSchool =
        $schoolCount > 0
            ? round(
                $activeStudents / $schoolCount,
                1
            )
            : 0.0;

    $studentAdvice =
        ($modulesPerActive < 1 || $attendancePerActive < 1)
            ? 'Prioritise attendance follow-up and module completion. '
                . 'Current engagement is '
                . number_format($attendancePerActive, 2)
                . ' attendance session(s) and '
                . number_format($modulesPerActive, 2)
                . ' completed module(s) per active student this month.'
            : 'Student engagement is progressing well. '
                . 'Maintain regular attendance and continue moving active students through the learning modules.';

    if ($activeRate < 70) {
        $schoolAdvice =
            'Improve activation conversion. '
            . number_format($activeRate, 1)
            . '% of registered students are currently active. '
            . 'Follow up pending or inactive students and track monthly activation by school.';
    } elseif ($avgActivePerSchool < 30) {
        $schoolAdvice =
            'Activation is healthy, but average scale is '
            . number_format($avgActivePerSchool, 1)
            . ' active students per school. '
            . 'Focus on increasing participation within existing active schools.';
    } else {
        $schoolAdvice =
            'School participation is at a healthy level. '
            . 'Continue monitoring growth, retention and module completion by school.';
    }

    if ($teachers <= 0) {
        $teacherAdvice =
            'No teacher capacity is recorded for the current active-student population. '
            . 'Assign or register teachers before expanding student participation.';
    } elseif ($studentsPerTeacher > 30) {
        $teacherAdvice =
            'Teacher load is high at approximately '
            . number_format($studentsPerTeacher, 1)
            . ' active students per teacher. '
            . 'Consider adding teacher capacity or redistributing coached students.';
    } elseif ($modulesPerActive < 1) {
        $teacherAdvice =
            'Teacher capacity is available, but module completion is below one module per active student this month. '
            . 'Set a monthly coaching target for attendance and module completion.';
    } else {
        $teacherAdvice =
            'Teacher load is manageable at approximately '
            . number_format($studentsPerTeacher, 1)
            . ' active students per teacher. '
            . 'Maintain coaching quality and review low-engagement students individually.';
    }

    return monthlyCronAdviceBlock([
        [
            'label' => 'Student Performance',
            'text' => $studentAdvice,
        ],
        [
            'label' => 'School KPI',
            'text' => $schoolAdvice,
        ],
        [
            'label' => 'Teacher KPI',
            'text' => $teacherAdvice,
        ],
    ]);
}

function monthlyCronSchoolData(
    PDO $pdo,
    int $schoolId,
    string $monthStart,
    string $nextMonthStart,
    string $attendanceMonth
): array {
    $students = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM students
         WHERE school_id = ?',
        [$schoolId]
    );

    $active = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students
         WHERE school_id = ?
           AND membership_status = 'active'
           AND membership_next_due_at IS NOT NULL
           AND membership_next_due_at >= NOW()",
        [$schoolId]
    );

    $teachers = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM teachers
         WHERE school_id = ?',
        [$schoolId]
    );

    $parents = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(DISTINCT sg.guardian_id)
         FROM student_guardians sg
         JOIN students st
           ON st.id = sg.student_id
         WHERE st.school_id = ?',
        [$schoolId]
    );

    $newStudents = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM students
         WHERE school_id = ?
           AND created_at >= ?
           AND created_at < ?',
        [
            $schoolId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $attendance = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM attendance_sessions a
         JOIN students st
           ON st.id = a.student_id
         WHERE st.school_id = ?
           AND a.attendance_month = ?',
        [
            $schoolId,
            $attendanceMonth,
        ]
    );

    $modules = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM student_module_progress p
         JOIN students st
           ON st.id = p.student_id
         WHERE st.school_id = ?
           AND p.progress_status = 'completed'
           AND p.completed_at >= ?
           AND p.completed_at < ?",
        [
            $schoolId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    return [
        'metrics' => [
            [
                'label' => 'Total Students',
                'value' => number_format($students),
            ],
            [
                'label' => 'Active Students',
                'value' => number_format($active),
            ],
            [
                'label' => 'Teachers',
                'value' => number_format($teachers),
            ],
            [
                'label' => 'Parents / Guardians',
                'value' => number_format($parents),
            ],
            [
                'label' => 'New Students This Month',
                'value' => number_format($newStudents),
            ],
            [
                'label' => 'Attendance Sessions',
                'value' => number_format($attendance),
                'note' => 'Current calendar month',
            ],
            [
                'label' => 'Modules Completed',
                'value' => number_format($modules),
                'note' => 'Current calendar month',
            ],
        ],
        'highlight' =>
            monthlyCronBuildPerformanceAdvice(
                $active,
                $students,
                $teachers,
                $attendance,
                $modules,
                1
            )
            . '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #d7e8ff;">'
            . monthlyCronEquipmentSummary(
                $students,
                $active
            )
            . '</div>',
    ];
}

function monthlyCronTeacherStudentScope(
    PDO $pdo,
    int $teacherId,
    int $schoolId
): array {
    if (
        monthlyCronTableExists(
            $pdo,
            'student_coach_assignments'
        )
    ) {
        return [
            'where' =>
                'EXISTS (
                    SELECT 1
                    FROM student_coach_assignments sca
                    WHERE sca.student_id = st.id
                      AND sca.teacher_id = ?
                )',
            'params' => [$teacherId],
            'label' => 'coached students',
        ];
    }

    return [
        'where' => 'st.school_id = ?',
        'params' => [$schoolId],
        'label' => 'school students',
    ];
}

function monthlyCronTeacherData(
    PDO $pdo,
    int $teacherId,
    int $schoolId,
    string $monthStart,
    string $nextMonthStart,
    string $attendanceMonth
): array {
    $scope = monthlyCronTeacherStudentScope(
        $pdo,
        $teacherId,
        $schoolId
    );

    $where = $scope['where'];
    $params = $scope['params'];

    $students = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students st
         WHERE {$where}",
        $params
    );

    $active = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students st
         WHERE {$where}
           AND st.membership_status = 'active'
           AND st.membership_next_due_at IS NOT NULL
           AND st.membership_next_due_at >= NOW()",
        $params
    );

    $attendance = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM attendance_sessions a
         JOIN students st
           ON st.id = a.student_id
         WHERE {$where}
           AND a.attendance_month = ?",
        [
            ...$params,
            $attendanceMonth,
        ]
    );

    $modules = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM student_module_progress p
         JOIN students st
           ON st.id = p.student_id
         WHERE {$where}
           AND p.progress_status = 'completed'
           AND p.completed_at >= ?
           AND p.completed_at < ?",
        [
            ...$params,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $certificates = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM issued_certificates
         WHERE teacher_id = ?
           AND status <> 'revoked'
           AND created_at >= ?
           AND created_at < ?",
        [
            $teacherId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $applications = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM teacher_event_applications
         WHERE teacher_id = ?
           AND created_at >= ?
           AND created_at < ?',
        [
            $teacherId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $detailStmt = $pdo->prepare(
        "SELECT
            st.full_name,
            st.class_name,
            st.membership_status
         FROM students st
         WHERE {$where}
         ORDER BY
            CASE
                WHEN st.membership_status = 'active'
                    THEN 0
                ELSE 1
            END,
            st.full_name
         LIMIT 4"
    );

    $detailStmt->execute($params);
    $detailRows = [];

    foreach ($detailStmt->fetchAll() as $row) {
        $detailRows[] = [
            'left' => (string)$row['full_name'],
            'right' => monthlyCronMembershipLabel(
                (string)$row['membership_status']
            ),
            'sub' => (string)($row['class_name'] ?? ''),
        ];
    }

    return [
        'metrics' => [
            [
                'label' => 'Coached Students',
                'value' => number_format($students),
            ],
            [
                'label' => 'Active Students',
                'value' => number_format($active),
            ],
            [
                'label' => 'Attendance Sessions',
                'value' => number_format($attendance),
                'note' => 'Current month',
            ],
            [
                'label' => 'Modules Completed',
                'value' => number_format($modules),
                'note' => 'Current month',
            ],
            [
                'label' => 'Your Certificates',
                'value' => number_format($certificates),
                'note' => 'Issued this month',
            ],
            [
                'label' => 'Event / Competition Applications',
                'value' => number_format($applications),
                'note' => 'Submitted this month',
            ],
        ],
        'highlight' =>
            monthlyCronBuildPerformanceAdvice(
                $active,
                $students,
                1,
                $attendance,
                $modules,
                1
            )
            . '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #d7e8ff;">'
            . 'This dashboard focuses on your '
            . monthlyReportEsc((string)$scope['label'])
            . '. Open the Teacher Portal for individual student progress and attendance.'
            . '</div>',
        'details' => $detailRows,
    ];
}

function monthlyCronParentData(
    PDO $pdo,
    int $guardianId,
    string $monthStart,
    string $nextMonthStart,
    string $attendanceMonth
): array {
    $children = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(DISTINCT student_id)
         FROM student_guardians
         WHERE guardian_id = ?',
        [$guardianId]
    );

    $active = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM student_guardians sg
         JOIN students st
           ON st.id = sg.student_id
         WHERE sg.guardian_id = ?
           AND st.membership_status = 'active'
           AND st.membership_next_due_at IS NOT NULL
           AND st.membership_next_due_at >= NOW()",
        [$guardianId]
    );

    $attendance = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM attendance_sessions a
         JOIN student_guardians sg
           ON sg.student_id = a.student_id
         WHERE sg.guardian_id = ?
           AND a.attendance_month = ?',
        [
            $guardianId,
            $attendanceMonth,
        ]
    );

    $modules = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM student_module_progress p
         JOIN student_guardians sg
           ON sg.student_id = p.student_id
         WHERE sg.guardian_id = ?
           AND p.progress_status = 'completed'
           AND p.completed_at >= ?
           AND p.completed_at < ?",
        [
            $guardianId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $certificates = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM issued_certificates ic
         JOIN student_guardians sg
           ON sg.student_id = ic.student_id
         WHERE sg.guardian_id = ?
           AND ic.status <> 'revoked'
           AND ic.created_at >= ?
           AND ic.created_at < ?",
        [
            $guardianId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $paymentMinor = (int)monthlyCronScalar(
        $pdo,
        "SELECT COALESCE(SUM(p.amount_minor),0)
         FROM payments p
         JOIN student_guardians sg
           ON sg.student_id = p.student_id
         WHERE sg.guardian_id = ?
           AND p.status = 'paid'
           AND p.paid_at >= ?
           AND p.paid_at < ?",
        [
            $guardianId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $applications = (int)monthlyCronScalar(
        $pdo,
        'SELECT COUNT(*)
         FROM parent_event_applications
         WHERE guardian_id = ?
           AND created_at >= ?
           AND created_at < ?',
        [
            $guardianId,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT
            st.full_name,
            st.class_name,
            st.membership_status,
            s.school_name
         FROM student_guardians sg
         JOIN students st
           ON st.id = sg.student_id
         LEFT JOIN schools s
           ON s.id = st.school_id
         WHERE sg.guardian_id = ?
         ORDER BY st.full_name
         LIMIT 4"
    );

    $stmt->execute([$guardianId]);
    $details = [];

    foreach ($stmt->fetchAll() as $row) {
        $details[] = [
            'left' => (string)$row['full_name'],
            'right' => monthlyCronMembershipLabel(
                (string)$row['membership_status']
            ),
            'sub' =>
                trim(
                    (string)($row['school_name'] ?? '')
                    . (
                        trim(
                            (string)($row['class_name'] ?? '')
                        ) !== ''
                            ? ' · '
                                . (string)$row['class_name']
                            : ''
                    )
                ),
        ];
    }

    return [
        'metrics' => [
            [
                'label' => 'Children',
                'value' => number_format($children),
            ],
            [
                'label' => 'Active Memberships',
                'value' => number_format($active),
            ],
            [
                'label' => 'Attendance Sessions',
                'value' => number_format($attendance),
                'note' => 'Current month',
            ],
            [
                'label' => 'Modules Completed',
                'value' => number_format($modules),
                'note' => 'Current month',
            ],
            [
                'label' => 'Certificates',
                'value' => number_format($certificates),
                'note' => 'Issued this month',
            ],
            [
                'label' => 'Payments',
                'value' => monthlyCronMoney($paymentMinor),
                'note' => 'Paid this month',
            ],
        ],
        'highlight' =>
            '<strong>Event applications this month:</strong> '
            . number_format($applications)
            . '. Review each child in the Parent Portal for detailed attendance, learning progress and certificates.',
        'details' => $details,
    ];
}

function monthlyCronJpnScope(
    array $official
): array {
    $conditions = [];
    $params = [];
    $labels = [];

    $state = trim(
        (string)($official['scope_state'] ?? '')
    );

    $district = trim(
        (string)($official['scope_district'] ?? '')
    );

    if ($state !== '') {
        $conditions[] =
            'UPPER(s.state) = UPPER(?)';

        $params[] = $state;
        $labels[] = $state;
    }

    if ($district !== '') {
        $conditions[] =
            'UPPER(s.district) = UPPER(?)';

        $params[] = $district;
        $labels[] = $district;
    }

    return [
        'sql' => $conditions
            ? implode(' AND ', $conditions)
            : '1=1',
        'params' => $params,
        'label' => $labels
            ? implode(' · ', $labels)
            : 'All Malaysia',
    ];
}

function monthlyCronJpnData(
    PDO $pdo,
    array $official,
    string $monthStart,
    string $nextMonthStart,
    string $attendanceMonth
): array {
    $scope = monthlyCronJpnScope($official);
    $scopeSql = $scope['sql'];
    $scopeParams = $scope['params'];

    /*
     * JPN report includes ACTIVE SCHOOLS ONLY.
     *
     * Active school = at least one currently active student
     * whose membership has not expired.
     */
    $activeSchoolCondition =
        "EXISTS (
            SELECT 1
            FROM students ast
            WHERE ast.school_id = s.id
              AND ast.membership_status = 'active'
              AND ast.membership_next_due_at IS NOT NULL
              AND ast.membership_next_due_at >= NOW()
        )";

    $activeSchools = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM schools s
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}",
        $scopeParams
    );

    $activeStudents = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students st
         JOIN schools s
           ON s.id = st.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}
           AND st.membership_status = 'active'
           AND st.membership_next_due_at IS NOT NULL
           AND st.membership_next_due_at >= NOW()",
        $scopeParams
    );

    $totalStudents = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students st
         JOIN schools s
           ON s.id = st.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}",
        $scopeParams
    );

    $teachers = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM teachers t
         JOIN schools s
           ON s.id = t.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}",
        $scopeParams
    );

    $newStudents = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM students st
         JOIN schools s
           ON s.id = st.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}
           AND st.created_at >= ?
           AND st.created_at < ?",
        [
            ...$scopeParams,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $attendance = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM attendance_sessions a
         JOIN students st
           ON st.id = a.student_id
         JOIN schools s
           ON s.id = st.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}
           AND a.attendance_month = ?",
        [
            ...$scopeParams,
            $attendanceMonth,
        ]
    );

    $modules = (int)monthlyCronScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM student_module_progress p
         JOIN students st
           ON st.id = p.student_id
         JOIN schools s
           ON s.id = st.school_id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}
           AND p.progress_status = 'completed'
           AND p.completed_at >= ?
           AND p.completed_at < ?",
        [
            ...$scopeParams,
            $monthStart,
            $nextMonthStart,
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT
            s.id,
            s.school_name,
            s.school_code,
            s.district,
            COUNT(st.id) AS total_students,
            SUM(
                CASE
                    WHEN st.membership_status = 'active'
                     AND st.membership_next_due_at IS NOT NULL
                     AND st.membership_next_due_at >= NOW()
                    THEN 1
                    ELSE 0
                END
            ) AS active_students,
            (
                SELECT COUNT(*)
                FROM teachers t
                WHERE t.school_id = s.id
            ) AS teacher_count
         FROM schools s
         JOIN students st
           ON st.school_id = s.id
         WHERE {$scopeSql}
           AND {$activeSchoolCondition}
         GROUP BY
            s.id,
            s.school_name,
            s.school_code,
            s.district
         ORDER BY
            active_students DESC,
            total_students DESC,
            s.school_name
         LIMIT 4"
    );

    $stmt->execute($scopeParams);

    $details = [];

    foreach ($stmt->fetchAll() as $row) {
        $details[] = [
            'left' => (string)$row['school_name'],
            'right' =>
                number_format(
                    (int)$row['active_students']
                )
                . ' active',
            'sub' =>
                trim(
                    (string)($row['district'] ?? '')
                    . ' · '
                    . (string)$row['school_code']
                    . ' · '
                    . number_format(
                        (int)$row['teacher_count']
                    )
                    . ' teacher(s)'
                ),
        ];
    }

    $advice = monthlyCronBuildPerformanceAdvice(
        $activeStudents,
        $totalStudents,
        $teachers,
        $attendance,
        $modules,
        max(1, $activeSchools)
    );

    return [
        'metrics' => [
            [
                'label' => 'Active Schools',
                'value' => number_format($activeSchools),
                'note' => 'Active schools only',
            ],
            [
                'label' => 'Active Students',
                'value' => number_format($activeStudents),
                'note' => 'Current status',
            ],
            [
                'label' => 'Teachers',
                'value' => number_format($teachers),
                'note' => 'In active schools',
            ],
            [
                'label' => 'New Student Registrations',
                'value' => number_format($newStudents),
                'note' => 'Current month',
            ],
            [
                'label' => 'Attendance Sessions',
                'value' => number_format($attendance),
                'note' => 'Current month',
            ],
            [
                'label' => 'Modules Completed',
                'value' => number_format($modules),
                'note' => 'Current month',
            ],
        ],
        'highlight' =>
            '<strong>Reporting scope:</strong> '
            . monthlyReportEsc(
                (string)$scope['label']
            )
            . '. Only schools with at least one currently active student are included.'
            . '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #d7e8ff;">'
            . $advice
            . '</div>',
        'details' => $details,
        'scope_label' => (string)$scope['label'],
    ];
}

function monthlyCronRecipients(
    PDO $pdo,
    string $role,
    string $monthKey,
    int $limit,
    bool $force
): array {
    $filter = $force
        ? '1=1'
        : '(email_report IS NULL OR email_report <> ?)';

    $params = $force
        ? []
        : [$monthKey];

    if ($role === 'school') {
        $sql =
            "SELECT
                id,
                school_name AS recipient_name,
                contact_email AS email,
                school_code
             FROM schools
             WHERE {$filter}
               AND TRIM(COALESCE(contact_email,'')) <> ''
             ORDER BY id
             LIMIT {$limit}";
    } elseif ($role === 'teacher') {
        $sql =
            "SELECT
                t.id,
                t.full_name AS recipient_name,
                t.email,
                t.school_id,
                s.school_name,
                s.school_code
             FROM teachers t
             JOIN schools s
               ON s.id = t.school_id
             WHERE {$filter}
               AND TRIM(COALESCE(t.email,'')) <> ''
             ORDER BY t.id
             LIMIT {$limit}";
    } elseif ($role === 'parent') {
        $sql =
            "SELECT
                id,
                full_name AS recipient_name,
                email,
                guardian_number
             FROM guardians
             WHERE {$filter}
               AND TRIM(COALESCE(email,'')) <> ''
             ORDER BY id
             LIMIT {$limit}";
    } elseif ($role === 'jpn') {
        $sql =
            "SELECT
                id,
                full_name AS recipient_name,
                email,
                position,
                department,
                scope_state,
                scope_district
             FROM jpn_officials
             WHERE is_active = 1
               AND {$filter}
               AND TRIM(COALESCE(email,'')) <> ''
             ORDER BY id
             LIMIT {$limit}";
    } else {
        return [];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function monthlyCronMarkSent(
    PDO $pdo,
    string $role,
    int $id,
    string $monthKey
): void {
    $table = match ($role) {
        'school' => 'schools',
        'teacher' => 'teachers',
        'parent' => 'guardians',
        'jpn' => 'jpn_officials',
        default => '',
    };

    if ($table === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE `'
        . $table
        . '`
         SET email_report = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $monthKey,
        $id,
    ]);
}

$roleTables = [
    'school' => 'schools',
    'teacher' => 'teachers',
    'parent' => 'guardians',
    'jpn' => 'jpn_officials',
];

foreach ($roleTables as $role => $table) {
    if (
        ($requestedRole === 'all'
            || $requestedRole === $role)
        && !monthlyCronColumnExists(
            $pdo,
            $table,
            'email_report'
        )
    ) {
        fwrite(
            STDERR,
            "Missing {$table}.email_report. "
            . "Run sql/add_monthly_email_report_field.sql first.\n"
        );
        exit(3);
    }
}

if (!$dryRun) {
    $brevoKey = trim(
        (string)(getenv('BREVO_API_KEY') ?: '')
    );

    $senderEmail = trim(
        (string)(getenv('BREVO_SENDER_EMAIL') ?: '')
    );

    if (
        $brevoKey === ''
        || !filter_var(
            $senderEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        fwrite(
            STDERR,
            "Brevo email is not configured. "
            . "Set BREVO_API_KEY and BREVO_SENDER_EMAIL.\n"
        );
        exit(4);
    }
}

$roles = $requestedRole === 'all'
    ? $allowedRoles
    : [$requestedRole];

$summary = [
    'candidates' => 0,
    'sent' => 0,
    'failed' => 0,
    'invalid_email' => 0,
];

monthlyCronLog(
    'Monthly report run started. Marker='
    . $monthKey
    . ($dryRun ? ' DRY-RUN' : '')
);

foreach ($roles as $role) {
    $recipients = monthlyCronRecipients(
        $pdo,
        $role,
        $monthKey,
        $limit,
        $force
    );

    monthlyCronLog(
        strtoupper($role)
        . ': '
        . count($recipients)
        . ' candidate(s)'
    );

    foreach ($recipients as $recipient) {
        $summary['candidates']++;

        $id = (int)$recipient['id'];
        $name = trim(
            (string)$recipient['recipient_name']
        );
        $email = strtolower(
            trim((string)$recipient['email'])
        );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $summary['invalid_email']++;

            monthlyCronLog(
                strtoupper($role)
                . " #{$id}: skipped invalid email"
            );

            continue;
        }

        try {
            if ($role === 'school') {
                $report = monthlyCronSchoolData(
                    $pdo,
                    $id,
                    $monthStart,
                    $nextMonthStart,
                    $attendanceMonth
                );

                $emailData = monthlyReportBuildEmail(
                    'school',
                    $name,
                    $periodLabel,
                    $report['metrics'],
                    'Equipment & Participation',
                    $report['highlight'],
                    '/school/'
                );
            } elseif ($role === 'teacher') {
                $report = monthlyCronTeacherData(
                    $pdo,
                    $id,
                    (int)$recipient['school_id'],
                    $monthStart,
                    $nextMonthStart,
                    $attendanceMonth
                );

                $schoolLabel = trim(
                    (string)$recipient['school_name']
                );

                $emailData = monthlyReportBuildEmail(
                    'teacher',
                    $name,
                    $periodLabel,
                    $report['metrics'],
                    'Teaching Dashboard',
                    $report['highlight']
                        . (
                            $schoolLabel !== ''
                                ? '<br><strong>School:</strong> '
                                    . monthlyReportEsc(
                                        $schoolLabel
                                    )
                                : ''
                        ),
                    '/teacher/',
                    $report['details'],
                    'Coached Students'
                );
            } elseif ($role === 'parent') {
                $report = monthlyCronParentData(
                    $pdo,
                    $id,
                    $monthStart,
                    $nextMonthStart,
                    $attendanceMonth
                );

                $emailData = monthlyReportBuildEmail(
                    'parent',
                    $name,
                    $periodLabel,
                    $report['metrics'],
                    'Family Programme Summary',
                    $report['highlight'],
                    '/parent/',
                    $report['details'],
                    'Children'
                );
            } else {
                $report = monthlyCronJpnData(
                    $pdo,
                    $recipient,
                    $monthStart,
                    $nextMonthStart,
                    $attendanceMonth
                );

                $emailData = monthlyReportBuildEmail(
                    'jpn',
                    $name,
                    $periodLabel,
                    $report['metrics'],
                    'JPN Reporting Scope',
                    $report['highlight'],
                    '/jpn/',
                    $report['details'],
                    'Top Active Schools'
                );
            }

            if ($dryRun) {
                monthlyCronLog(
                    strtoupper($role)
                    . " #{$id}: DRY-RUN "
                    . $email
                    . ' | '
                    . $emailData['subject']
                );

                continue;
            }

            $send = monthlyCronSendBrevoEmail(
                $email,
                $name,
                (string)$emailData['subject'],
                (string)$emailData['html'],
                (string)$emailData['text'],
                'monthly-' . $role . '-report'
            );

            if (empty($send['success'])) {
                $summary['failed']++;

                monthlyCronLog(
                    strtoupper($role)
                    . " #{$id}: FAILED "
                    . (string)(
                        $send['message']
                        ?? 'Unknown email error'
                    )
                );

                continue;
            }

            monthlyCronMarkSent(
                $pdo,
                $role,
                $id,
                $monthKey
            );

            $summary['sent']++;

            monthlyCronLog(
                strtoupper($role)
                . " #{$id}: sent to "
                . $email
                . ' and marked '
                . $monthKey
            );
        } catch (Throwable $e) {
            $summary['failed']++;

            monthlyCronLog(
                strtoupper($role)
                . " #{$id}: ERROR "
                . $e->getMessage()
            );

            error_log(
                'Monthly '
                . $role
                . ' report failed for ID '
                . $id
                . ': '
                . $e->getMessage()
            );
        }
    }
}

monthlyCronLog(
    'Completed. candidates='
    . $summary['candidates']
    . ' sent='
    . $summary['sent']
    . ' failed='
    . $summary['failed']
    . ' invalid_email='
    . $summary['invalid_email']
);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
