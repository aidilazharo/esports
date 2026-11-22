<?php
declare(strict_types=1);

/**
 * Daily equipment eligibility checker.
 *
 * Recommended cron:
 * 15 7 * * * /usr/bin/php /FULL/PATH/TO/esport/cron/equipment_eligibility_daily.php >> /FULL/PATH/TO/esport/logs/equipment_eligibility_daily.log 2>&1
 *
 * Option:
 * --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__)
    . '/includes/equipment_entitlements.php';

const EQUIPMENT_ALERT_RECIPIENT =
    'aidilazhar@me.com';

$options = getopt('', ['dry-run']);

$dryRun = array_key_exists(
    'dry-run',
    $options
);

function equipmentCronLog(string $message): void
{
    echo '['
        . date('Y-m-d H:i:s')
        . '] '
        . $message
        . PHP_EOL;
}

function equipmentCronTableExists(
    PDO $pdo,
    string $table
): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function equipmentCronSendEmail(
    string $subject,
    string $html,
    string $text
): array {
    $apiKey = trim(
        (string)(
            getenv('BREVO_API_KEY')
            ?: ''
        )
    );

    $senderEmail = trim(
        (string)(
            getenv('BREVO_SENDER_EMAIL')
            ?: ''
        )
    );

    $senderName = trim(
        (string)(
            getenv('BREVO_SENDER_NAME')
            ?: 'Metadata Berhad'
        )
    );

    if (
        $apiKey === ''
        || !filter_var(
            $senderEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'message' => 'Brevo is not configured.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'PHP cURL is required.',
        ];
    }

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [[
            'name' => 'Aidil Azhar',
            'email' =>
                EQUIPMENT_ALERT_RECIPIENT,
        ]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
        'tags' => [
            'equipment-eligibility',
        ],
    ];

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
    $error = curl_error($ch);
    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if (
        $response === false
        || $error !== ''
    ) {
        return [
            'success' => false,
            'message' =>
                'Brevo connection failed: '
                . $error,
        ];
    }

    if (
        $status < 200
        || $status >= 300
    ) {
        error_log(
            'Equipment eligibility Brevo HTTP '
            . $status
            . ': '
            . (string)$response
        );

        return [
            'success' => false,
            'message' =>
                'Brevo HTTP '
                . $status,
        ];
    }

    return [
        'success' => true,
    ];
}

$requiredTables = [
    'school_equipment_received',
    'school_psn_accounts',
    'school_equipment_notifications',
];

foreach ($requiredTables as $requiredTable) {
    if (
        !equipmentCronTableExists(
            $pdo,
            $requiredTable
        )
    ) {
        fwrite(
            STDERR,
            "Missing {$requiredTable}. "
            . "Run sql/add_yearly_equipment_entitlements.sql first.\n"
        );

        exit(2);
    }
}

$stmt = $pdo->query(
    "SELECT
        s.id AS school_id,
        s.school_code,
        s.school_name,
        YEAR(st.created_at) AS academic_year,
        COUNT(*) AS active_students
     FROM schools s
     JOIN students st
       ON st.school_id = s.id
     WHERE st.membership_status = 'active'
       AND st.membership_next_due_at IS NOT NULL
       AND st.membership_next_due_at >= NOW()
       AND st.created_at IS NOT NULL
     GROUP BY
        s.id,
        s.school_code,
        s.school_name,
        YEAR(st.created_at)
     HAVING COUNT(*) >= 1
     ORDER BY
        academic_year DESC,
        s.id"
);

$groups = $stmt->fetchAll();

$insertReceived = $pdo->prepare(
    "INSERT IGNORE INTO school_equipment_received
     (
        school_id,
        academic_year,
        threshold_student_count,
        equipment_type,
        quantity,
        active_students_at_award,
        source
     )
     VALUES (?, ?, ?, ?, ?, ?, 'cron')"
);

$insertPsn = $pdo->prepare(
    "INSERT IGNORE INTO school_psn_accounts
     (
        school_id,
        academic_year,
        threshold_student_count,
        account_status
     )
     VALUES (?, ?, ?, 'pending')"
);

$notificationExists = $pdo->prepare(
    "SELECT COUNT(*)
     FROM school_equipment_notifications
     WHERE school_id = ?
       AND academic_year = ?
       AND threshold_student_count = ?"
);

$insertNotification = $pdo->prepare(
    "INSERT IGNORE INTO school_equipment_notifications
     (
        school_id,
        academic_year,
        threshold_student_count,
        active_students_at_notification,
        sent_to
     )
     VALUES (?, ?, ?, ?, ?)"
);

$catalog = equipmentCatalog();

$emailsSent = 0;
$awardRowsCreated = 0;

foreach ($groups as $group) {
    $schoolId =
        (int)$group['school_id'];

    $year =
        (int)$group['academic_year'];

    $active =
        (int)$group['active_students'];

    $schoolName =
        (string)$group['school_name'];

    $schoolCode =
        (string)$group['school_code'];

    $newMilestones = [];

    foreach (
        equipmentMilestonesUpTo($active)
        as $milestone
    ) {
        $threshold =
            (int)$milestone['threshold'];

        $notificationExists->execute([
            $schoolId,
            $year,
            $threshold,
        ]);

        $alreadyNotified =
            (int)$notificationExists
                ->fetchColumn() > 0;

        foreach (
            $milestone['items']
            as $item
        ) {
            if (!$dryRun) {
                $insertReceived->execute([
                    $schoolId,
                    $year,
                    $threshold,
                    (string)$item['type'],
                    (int)$item['quantity'],
                    $active,
                ]);

                $awardRowsCreated +=
                    $insertReceived->rowCount();

                if (
                    (string)$item['type']
                    === 'psn_account'
                ) {
                    $insertPsn->execute([
                        $schoolId,
                        $year,
                        $threshold,
                    ]);
                }
            }
        }

        if (!$alreadyNotified) {
            $newMilestones[] =
                $milestone;
        }
    }

    if (!$newMilestones) {
        continue;
    }

    $lines = [];
    $htmlRows = '';

    foreach (
        $newMilestones
        as $milestone
    ) {
        $threshold =
            (int)$milestone['threshold'];

        $itemLabels = [];

        foreach (
            $milestone['items']
            as $item
        ) {
            $type =
                (string)$item['type'];

            $qty =
                (int)$item['quantity'];

            $label =
                (string)(
                    $catalog[$type]['label']
                    ?? $type
                );

            $itemLabels[] =
                $qty
                . ' × '
                . $label;
        }

        $description =
            implode(
                ', ',
                $itemLabels
            );

        $lines[] =
            $threshold
            . ' active students: '
            . $description;

        $htmlRows .=
            '<tr>'
            . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;">'
            . '<strong>'
            . $threshold
            . '</strong>'
            . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;">'
            . htmlspecialchars(
                $description,
                ENT_QUOTES,
                'UTF-8'
            )
            . '</td>'
            . '</tr>';
    }

    $subject =
        'Equipment milestone reached: '
        . $schoolName
        . ' ('
        . $year
        . ')';

    $safeSchoolName =
        htmlspecialchars(
            $schoolName,
            ENT_QUOTES,
            'UTF-8'
        );

    $safeSchoolCode =
        htmlspecialchars(
            $schoolCode,
            ENT_QUOTES,
            'UTF-8'
        );

    $html =
        '<!doctype html>'
        . '<html>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;color:#111827;">'
        . '<h2 style="margin-bottom:8px;">Equipment Milestone Reached</h2>'
        . '<p><strong>School:</strong> '
        . $safeSchoolName
        . ' ('
        . $safeSchoolCode
        . ')</p>'
        . '<p><strong>Registration year:</strong> '
        . $year
        . '<br><strong>Current active students for that year:</strong> '
        . $active
        . '</p>'
        . '<table cellspacing="0" cellpadding="0" border="0" '
        . 'style="border:1px solid #e5e7eb;border-radius:8px;'
        . 'width:100%;max-width:650px;">'
        . '<thead><tr>'
        . '<th align="left" style="padding:8px;">Milestone</th>'
        . '<th align="left" style="padding:8px;">Equipment</th>'
        . '</tr></thead>'
        . '<tbody>'
        . $htmlRows
        . '</tbody>'
        . '</table>'
        . '<p style="margin-top:16px;color:#6b7280;">'
        . 'Generated automatically by the daily equipment eligibility cron job.'
        . '</p>'
        . '</body>'
        . '</html>';

    $text =
        "Equipment Milestone Reached\n\n"
        . "School: {$schoolName} ({$schoolCode})\n"
        . "Registration year: {$year}\n"
        . "Active students: {$active}\n\n"
        . implode(
            "\n",
            $lines
        );

    if ($dryRun) {
        equipmentCronLog(
            "DRY-RUN {$schoolCode} {$year}: "
            . implode(
                ' | ',
                $lines
            )
        );

        continue;
    }

    $send =
        equipmentCronSendEmail(
            $subject,
            $html,
            $text
        );

    if (empty($send['success'])) {
        equipmentCronLog(
            "{$schoolCode} {$year}: "
            . 'email failed - '
            . (string)(
                $send['message']
                ?? 'unknown error'
            )
        );

        continue;
    }

    foreach (
        $newMilestones
        as $milestone
    ) {
        $insertNotification->execute([
            $schoolId,
            $year,
            (int)$milestone['threshold'],
            $active,
            EQUIPMENT_ALERT_RECIPIENT,
        ]);
    }

    $emailsSent++;

    equipmentCronLog(
        "{$schoolCode} {$year}: sent "
        . count($newMilestones)
        . ' new milestone(s) to '
        . EQUIPMENT_ALERT_RECIPIENT
    );
}

equipmentCronLog(
    'Completed. groups='
    . count($groups)
    . ' equipment_rows_created='
    . $awardRowsCreated
    . ' emails_sent='
    . $emailsSent
);
