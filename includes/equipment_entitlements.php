<?php
declare(strict_types=1);

/**
 * Equipment entitlement rules.
 *
 * The entitlement cycle repeats every 30 active students registered
 * in the same registration year:
 *
 * +1  TV Stand
 * +2  PS5 Controller Charger
 * +10 LCD TV
 * +15 PS5 Controller Cover
 * +20 PS5 Camera
 * +25 PS5 Controller
 * +30 PS5 + PS5 Controller + PSN Account
 */

function equipmentCatalog(): array
{
    return [
        'tv_stand' => [
            'label' => 'TV Stand',
            'icon' => 'bi-display',
        ],
        'ps5_controller_charger' => [
            'label' => 'PS5 Controller Charger',
            'icon' => 'bi-battery-charging',
        ],
        'lcd_tv' => [
            'label' => 'LCD TV',
            'icon' => 'bi-tv',
        ],
        'ps5_controller_cover' => [
            'label' => 'PS5 Controller Cover',
            'icon' => 'bi-shield',
        ],
        'ps5_camera' => [
            'label' => 'PS5 Camera',
            'icon' => 'bi-camera-video',
        ],
        'ps5_controller' => [
            'label' => 'PS5 Controller',
            'icon' => 'bi-controller',
        ],
        'ps5' => [
            'label' => 'PlayStation 5',
            'icon' => 'bi-playstation',
        ],
        'psn_account' => [
            'label' => 'PSN Account',
            'icon' => 'bi-key',
        ],
    ];
}

function equipmentMilestonesUpTo(int $activeStudents): array
{
    $activeStudents = max(0, $activeStudents);
    $milestones = [];

    if ($activeStudents < 1) {
        return [];
    }

    for ($base = 0; $base < $activeStudents; $base += 30) {
        $rules = [
            $base + 1 => [
                ['type' => 'tv_stand', 'quantity' => 1],
            ],
            $base + 2 => [
                ['type' => 'ps5_controller_charger', 'quantity' => 1],
            ],
            $base + 10 => [
                ['type' => 'lcd_tv', 'quantity' => 1],
            ],
            $base + 15 => [
                ['type' => 'ps5_controller_cover', 'quantity' => 1],
            ],
            $base + 20 => [
                ['type' => 'ps5_camera', 'quantity' => 1],
            ],
            $base + 25 => [
                ['type' => 'ps5_controller', 'quantity' => 1],
            ],
            $base + 30 => [
                ['type' => 'ps5', 'quantity' => 1],
                ['type' => 'ps5_controller', 'quantity' => 1],
                ['type' => 'psn_account', 'quantity' => 1],
            ],
        ];

        foreach ($rules as $threshold => $items) {
            if ($threshold <= $activeStudents) {
                $milestones[] = [
                    'threshold' => $threshold,
                    'items' => $items,
                ];
            }
        }
    }

    usort(
        $milestones,
        static fn(array $a, array $b): int =>
            ((int)$a['threshold']) <=> ((int)$b['threshold'])
    );

    return $milestones;
}

function equipmentEntitlementTotals(int $activeStudents): array
{
    $totals = array_fill_keys(
        array_keys(equipmentCatalog()),
        0
    );

    foreach (equipmentMilestonesUpTo($activeStudents) as $milestone) {
        foreach ($milestone['items'] as $item) {
            $type = (string)$item['type'];
            $totals[$type] += (int)$item['quantity'];
        }
    }

    return $totals;
}

function equipmentNextMilestones(
    int $activeStudents,
    int $limit = 5
): array {
    $activeStudents = max(0, $activeStudents);
    $limit = max(1, $limit);
    $next = [];

    $startBlock = intdiv($activeStudents, 30) * 30;

    for (
        $base = max(0, $startBlock - 30);
        count($next) < $limit;
        $base += 30
    ) {
        $rules = [
            $base + 1 => 'TV Stand',
            $base + 2 => 'PS5 Controller Charger',
            $base + 10 => 'LCD TV',
            $base + 15 => 'PS5 Controller Cover',
            $base + 20 => 'PS5 Camera',
            $base + 25 => 'PS5 Controller',
            $base + 30 => 'PS5 + PS5 Controller + PSN Account',
        ];

        foreach ($rules as $threshold => $label) {
            if ($threshold > $activeStudents) {
                $next[] = [
                    'threshold' => $threshold,
                    'label' => $label,
                    'students_needed' =>
                        $threshold - $activeStudents,
                ];

                if (count($next) >= $limit) {
                    break 2;
                }
            }
        }
    }

    return $next;
}

function equipmentActiveStudentsByRegistrationYear(
    PDO $pdo,
    int $schoolId
): array {
    $stmt = $pdo->prepare(
        "SELECT
            YEAR(created_at) AS academic_year,
            COUNT(*) AS active_students
         FROM students
         WHERE school_id = ?
           AND membership_status = 'active'
           AND membership_next_due_at IS NOT NULL
           AND membership_next_due_at >= NOW()
           AND created_at IS NOT NULL
         GROUP BY YEAR(created_at)
         ORDER BY academic_year DESC"
    );

    $stmt->execute([$schoolId]);

    $result = [];

    foreach ($stmt->fetchAll() as $row) {
        $year = (int)$row['academic_year'];

        if ($year > 0) {
            $result[$year] = (int)$row['active_students'];
        }
    }

    return $result;
}

function equipmentCurrentYearActiveStudents(
    PDO $pdo,
    int $schoolId
): int {
    $year = (int)date('Y');
    $all = equipmentActiveStudentsByRegistrationYear(
        $pdo,
        $schoolId
    );

    return (int)($all[$year] ?? 0);
}
