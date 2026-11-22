<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/role_auth.php';
require_once __DIR__ . '/portal_layout.php';
require_once __DIR__ . '/equipment_entitlements.php';

$equipmentPortalRole = strtolower(trim((string)($equipmentPortalRole ?? '')));
if (!in_array($equipmentPortalRole, ['school', 'teacher'], true)) {
    http_response_code(403);
    exit('Equipment dashboard is available only to School and Teacher portal users.');
}
requirePortalRole($equipmentPortalRole);

$schoolId = (int)($_SESSION['school_id'] ?? 0);
if ($schoolId < 1) {
    http_response_code(403);
    exit('School context is required.');
}

$currentYear = (int)date('Y');
$minimumActiveStudents = 30;

function equipmentTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function equipmentStatusBadge(string $status): string
{
    return match ($status) {
        'approved' => 'success',
        'in_progress' => 'primary',
        'completed', 'returned' => 'info',
        'rejected', 'cancelled' => 'danger',
        default => 'warning',
    };
}

function equipmentLabel(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

$schoolStmt = $pdo->prepare(
    'SELECT id, school_code, school_name, state, district
     FROM schools
     WHERE id = ?
     LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch();

if (!$school) {
    http_response_code(404);
    exit('School not found.');
}

$tableReady = equipmentTableExists(
    $pdo,
    'equipment_requests'
);
$receivedTableReady = equipmentTableExists(
    $pdo,
    'school_equipment_received'
);
$psnTableReady = equipmentTableExists(
    $pdo,
    'school_psn_accounts'
);

$activeStudentsByYear =
    equipmentActiveStudentsByRegistrationYear(
        $pdo,
        $schoolId
    );

$activeStudents =
    (int)($activeStudentsByYear[$currentYear] ?? 0);

$isEligible =
    $activeStudents >= $minimumActiveStudents;

$studentsNeeded =
    max(
        0,
        $minimumActiveStudents - $activeStudents
    );

$currentEntitlements =
    equipmentEntitlementTotals($activeStudents);

$nextMilestones =
    equipmentNextMilestones($activeStudents, 5);

$equipmentCatalog = equipmentCatalog();

$equipmentReceivedTotals = array_fill_keys(
    array_keys($equipmentCatalog),
    0
);

$equipmentReceivedByYear = [];
$receivedRows = [];

if ($receivedTableReady) {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            academic_year,
            threshold_student_count,
            equipment_type,
            quantity,
            active_students_at_award,
            serial_number,
            date_received,
            asset_condition,
            assigned_location,
            provision_type,
            asset_status,
            received_at
         FROM school_equipment_received
         WHERE school_id = ?
         ORDER BY
            academic_year DESC,
            threshold_student_count ASC,
            id ASC"
    );

    $stmt->execute([$schoolId]);
    $receivedRows = $stmt->fetchAll();

    foreach ($receivedRows as $row) {
        $year = (int)$row['academic_year'];
        $type = (string)$row['equipment_type'];
        $qty = (int)$row['quantity'];

        if (!isset($equipmentReceivedByYear[$year])) {
            $equipmentReceivedByYear[$year] =
                array_fill_keys(
                    array_keys($equipmentCatalog),
                    0
                );
        }

        $isPhysicallyReceived =
            !empty($row['date_received']);

        if (
            $isPhysicallyReceived
            && array_key_exists(
                $type,
                $equipmentReceivedTotals
            )
        ) {
            $equipmentReceivedTotals[$type] += $qty;
            $equipmentReceivedByYear[$year][$type] += $qty;
        }
    }
}

$psnAccounts = [];

if ($psnTableReady) {
    $stmt = $pdo->prepare(
        "SELECT id,school_id,academic_year,threshold_student_count,account_status,updated_at
         FROM school_psn_accounts
         WHERE school_id = ?
         ORDER BY
            academic_year DESC,
            threshold_student_count ASC,
            id ASC"
    );

    $stmt->execute([$schoolId]);
    $psnAccounts = $stmt->fetchAll();
}

$requestedById = $equipmentPortalRole === 'teacher'
    ? (int)($_SESSION['teacher_id'] ?? 0)
    : $schoolId;

$requestedByName = $equipmentPortalRole === 'teacher'
    ? trim((string)($_SESSION['teacher_name'] ?? 'Teacher'))
    : (string)$school['school_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        if (!$tableReady) {
            throw new RuntimeException('Run add_equipment_requests.sql before submitting requests.');
        }

        $activeStudents = equipmentCurrentYearActiveStudents($pdo, $schoolId);
        if ($activeStudents < $minimumActiveStudents) {
            throw new RuntimeException(
                'Equipment requests require at least 30 active students registered during the current year.'
            );
        }

        $type = strtolower(trim((string)($_POST['request_type'] ?? '')));
        if (!in_array($type, ['repair', 'replacement', 'loan'], true)) {
            throw new RuntimeException('Invalid equipment request type.');
        }

        $equipmentType = trim((string)($_POST['equipment_type'] ?? ''));
        $assetTag = strtoupper(trim((string)($_POST['asset_tag'] ?? '')));
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $issue = trim((string)($_POST['issue_description'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));

        $tournamentName = trim((string)($_POST['tournament_name'] ?? ''));
        $tournamentLocation = trim((string)($_POST['tournament_location'] ?? ''));
        $loanStart = trim((string)($_POST['loan_start_date'] ?? ''));
        $loanEnd = trim((string)($_POST['loan_end_date'] ?? ''));
        $equipmentRequired = trim((string)($_POST['equipment_required'] ?? ''));
        $loanDays = null;

        if ($type !== 'loan') {
            if ($equipmentType === '') {
                throw new RuntimeException('Please select the equipment.');
            }
            if ($issue === '') {
                throw new RuntimeException('Please describe the equipment problem.');
            }
            if ($type === 'replacement' && $reason === '') {
                throw new RuntimeException('Please provide the replacement reason.');
            }
        } else {
            if ($tournamentName === '') {
                throw new RuntimeException('Tournament name is required.');
            }
            if ($loanStart === '' || $loanEnd === '') {
                throw new RuntimeException('Loan start and end dates are required.');
            }

            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $loanStart);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $loanEnd);

            if (!$start || !$end || $end < $start) {
                throw new RuntimeException('Please enter a valid loan date range.');
            }

            $loanDays = (int)$start->diff($end)->days + 1;

            if ($equipmentRequired === '') {
                throw new RuntimeException('Please list the equipment required for the tournament.');
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO equipment_requests
             (
                school_id, request_type, request_status,
                equipment_type, asset_tag, quantity,
                issue_description, reason,
                tournament_name, tournament_location,
                loan_start_date, loan_end_date, loan_days,
                equipment_required,
                requested_by_role, requested_by_id, requested_by_name,
                active_students_at_request, academic_year
             )
             VALUES
             (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $schoolId,
            $type,
            $equipmentType !== '' ? $equipmentType : null,
            $assetTag !== '' ? $assetTag : null,
            $quantity,
            $issue !== '' ? $issue : null,
            $reason !== '' ? $reason : null,
            $tournamentName !== '' ? $tournamentName : null,
            $tournamentLocation !== '' ? $tournamentLocation : null,
            $loanStart !== '' ? $loanStart : null,
            $loanEnd !== '' ? $loanEnd : null,
            $loanDays,
            $equipmentRequired !== '' ? $equipmentRequired : null,
            $equipmentPortalRole,
            $requestedById > 0 ? $requestedById : null,
            $requestedByName,
            $activeStudents,
            $currentYear,
        ]);

        setFlash('success', equipmentLabel($type) . ' request submitted successfully.');
        redirect('equipment.php');
    } catch (Throwable $e) {
        error_log('Equipment request error: ' . $e->getMessage());
        setFlash(
            'danger',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to submit the equipment request.'
        );
        redirect('equipment.php');
    }
}

$requests = [];
$openRequests = 0;
$openLoans = 0;

if ($tableReady) {
    $stmt = $pdo->prepare(
        'SELECT *
         FROM equipment_requests
         WHERE school_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 100'
    );
    $stmt->execute([$schoolId]);
    $requests = $stmt->fetchAll();

    foreach ($requests as $row) {
        if (in_array((string)$row['request_status'], ['pending','approved','in_progress'], true)) {
            $openRequests++;
            if ((string)$row['request_type'] === 'loan') {
                $openLoans++;
            }
        }
    }
}

$progress = min(100, (int)round(($activeStudents / $minimumActiveStudents) * 100));

portalPageStart($equipmentPortalRole, 'equipment', 'Equipment');
?>

<style>
.equipment-compact-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: .55rem;
}
.equipment-mini-card {
    padding: .7rem .55rem;
    text-align: center;
}
.equipment-mini-icon,
.equipment-entitlement-icon {
    display: block;
    font-size: .95rem;
    line-height: 1;
    margin-bottom: .4rem;
}
.equipment-mini-label,
.equipment-entitlement-label {
    font-size: .72rem;
    line-height: 1.15;
    font-weight: 600;
}
.equipment-mini-value {
    margin-top: .35rem;
    font-size: 1.1rem;
    line-height: 1;
    font-weight: 700;
}
.equipment-mini-note {
    margin-top: .25rem;
    font-size: .65rem;
    line-height: 1;
    color: #6c757d;
}
.equipment-entitlement-item {
    min-width: 0;
    min-height: 92px;
    padding: .7rem .45rem;
    border: 1px solid;
    border-radius: .65rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.equipment-item-eligible {
    color: var(--bs-success-text-emphasis);
    background: var(--bs-success-bg-subtle);
    border-color: var(--bs-success-border-subtle);
}
.equipment-item-locked {
    color: #6c757d;
    background: #f8f9fa;
    border-color: #dee2e6;
}

/* Force entitlement icon colours on every screen size, including mobile. */
.equipment-item-eligible .equipment-entitlement-icon {
    color: var(--bs-success) !important;
}

.equipment-item-locked .equipment-entitlement-icon {
    color: #6c757d !important;
}
.equipment-entitlement-label {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: center;
}
.equipment-entitlement-count {
    margin-top: .45rem;
    font-size: .66rem;
}
@media (max-width: 1199.98px) {
    .equipment-compact-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
@media (max-width: 575.98px) {
    .equipment-compact-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .equipment-item-eligible .equipment-entitlement-icon {
        color: var(--bs-success) !important;
    }

    .equipment-item-locked .equipment-entitlement-icon {
        color: #6c757d !important;
    }
}
</style>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase fw-semibold text-primary mb-1">
            <?= e((string)$school['school_code']) ?>
        </div>
        <h1 class="h3 mb-1">School Equipment</h1>
        <p class="text-secondary mb-0">
            <?= e((string)$school['school_name']) ?>
            · <?= $equipmentPortalRole === 'teacher' ? 'Teacher View' : 'School View' ?>
        </p>
    </div>
    <span class="badge rounded-pill text-bg-<?= $isEligible ? 'success' : 'secondary' ?> fs-6">
        <?= $isEligible ? 'Requests Enabled' : 'Requests Locked' ?>
    </span>
</div>

<?php if (!$tableReady): ?>
<div class="alert alert-warning">
    <i class="bi bi-database-exclamation me-2"></i>
    Run <code>sql/add_equipment_requests.sql</code> first.
</div>
<?php endif; ?>

<?php if (!$receivedTableReady || !$psnTableReady): ?>
<div class="alert alert-warning">
    <i class="bi bi-database-exclamation me-2"></i>
    Run <code>sql/add_yearly_equipment_entitlements.sql</code>
    to enable yearly equipment and PSN account tracking.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 text-secondary small">
                    <i class="bi bi-people"></i>
                    Active Students — <?= $currentYear ?>
                </div>
                <div class="display-6 fw-semibold text-success">
                    <?= number_format($activeStudents) ?>
                </div>
                <div class="small text-secondary">
                    Active students registered in <?= $currentYear ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 text-secondary small">
                    <i class="bi bi-shield-check"></i>
                    Request Eligibility
                </div>
                <div class="h3 fw-semibold mb-1">
                    <?= $isEligible ? 'Eligible' : 'Not Eligible' ?>
                </div>
                <div class="small text-secondary">
                    Repair / replacement / loan at 30 active students
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 text-secondary small">
                    <i class="bi bi-inboxes"></i>
                    Equipment Records
                </div>
                <div class="display-6 fw-semibold">
                    <?= number_format(array_sum($equipmentReceivedTotals)) ?>
                </div>
                <div class="small text-secondary">
                    All registration years combined
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 text-secondary small">
                    <i class="bi bi-key"></i>
                    PSN Accounts
                </div>
                <div class="display-6 fw-semibold">
                    <?= number_format(count($psnAccounts)) ?>
                </div>
                <div class="small text-secondary">
                    Allocated accounts
                </div>
            </div>
        </div>
    </div>
</div>

<div class="equipment-compact-grid mb-4">
<?php foreach ($equipmentCatalog as $type => $meta): ?>
    <div class="card stat-card h-100">
        <div class="card-body equipment-mini-card">
            <i class="bi <?= e((string)$meta['icon']) ?> equipment-mini-icon"></i>
            <div class="equipment-mini-label">
                <?= e((string)$meta['label']) ?>
            </div>
            <div class="equipment-mini-value">
                <?= number_format((int)$equipmentReceivedTotals[$type]) ?>
            </div>
            <div class="equipment-mini-note">Received</div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="card content-card mb-4">
    <div class="card-header bg-white p-4 border-0">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <h2 class="h5 mb-1"><i class="bi bi-box-seam me-2"></i>Equipment Request Eligibility & <?= $currentYear ?> Equipment Entitlement</h2>
                <div class="small text-secondary">
                    Repair, replacement and loan requests require 30 active students registered in the same year.
                    Eligible equipment items are highlighted in green based on current active-student count.
                </div>
            </div>
            <strong><?= $activeStudents ?>/30</strong>
        </div>
    </div>

    <div class="card-body p-4 pt-0">
        <div class="progress mb-3" style="height:10px">
            <div class="progress-bar <?= $isEligible ? 'bg-success' : '' ?>" style="width:<?= $progress ?>%"></div>
        </div>

        <?php if ($isEligible): ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle me-2"></i>
                This school is eligible to submit equipment requests.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary mb-4">
                <i class="bi bi-lock me-2"></i>
                <?= number_format($studentsNeeded) ?> more qualifying student<?= $studentsNeeded === 1 ? '' : 's' ?> required.
            </div>
        <?php endif; ?>

        <div class="equipment-compact-grid">
            <?php foreach ($equipmentCatalog as $type => $meta): ?>
                <?php
                $eligibleItem =
                    ((int)$currentEntitlements[$type]) > 0;
                ?>
                <div
                    class="equipment-entitlement-item <?= $eligibleItem ? 'equipment-item-eligible' : 'equipment-item-locked' ?>"
                >
                    <i
                        class="bi <?= e((string)$meta['icon']) ?> equipment-entitlement-icon"
                    ></i>

                    <div class="equipment-entitlement-label">
                        <?= e((string)$meta['label']) ?>
                    </div>

                    <span
                        class="badge <?= $eligibleItem ? 'text-bg-success' : 'text-bg-secondary' ?> equipment-entitlement-count"
                    >
                        <?= number_format(
                            (int)$currentEntitlements[$type]
                        ) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card content-card mb-4">
    <div class="card-header bg-white p-4">
        <h2 class="h5 mb-1">
            <i class="bi bi-boxes me-2"></i>
            Equipment Inventory
        </h2>

        <div class="small text-secondary">
            Equipment is grouped by student registration year.
            Received totals count only items with a Date Received.
        </div>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table
                class="table table-hover align-middle w-100"
                id="equipmentInventoryTable"
            >
                <thead>
                    <tr>
                        <th>Registration Year</th>
                        <th>Equipment</th>
                        <th>Eligibility Milestone</th>
                        <th>Qty</th>
                        <th>Serial No.</th>
                        <th>Date Received</th>
                        <th>Condition</th>
                        <th>Assigned Location</th>
                        <th>Provision Type</th>
                        <th>Current Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($receivedRows as $row): ?>
                    <?php
                    $type =
                        (string)$row['equipment_type'];

                    $meta =
                        $equipmentCatalog[$type]
                        ?? [
                            'label' =>
                                equipmentLabel($type),
                            'icon' => 'bi-box',
                        ];

                    $condition =
                        (string)(
                            $row['asset_condition']
                            ?? 'new'
                        );

                    $provisionType =
                        (string)(
                            $row['provision_type']
                            ?? 'new'
                        );

                    $assetStatus =
                        (string)(
                            $row['asset_status']
                            ?? 'pending_delivery'
                        );

                    $statusBadge = match ($assetStatus) {
                        'received', 'in_use' => 'success',
                        'on_loan' => 'primary',
                        'under_repair' => 'warning',
                        'replaced', 'retired' => 'secondary',
                        default => 'light',
                    };

                    $conditionBadge = match ($condition) {
                        'new' => 'success',
                        'refurbished' => 'warning',
                        default => 'secondary',
                    };
                    ?>

                    <tr>
                        <td>
                            <?= (int)$row['academic_year'] ?>
                        </td>

                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi <?= e((string)$meta['icon']) ?>"></i>
                                <strong>
                                    <?= e((string)$meta['label']) ?>
                                </strong>
                            </div>
                        </td>

                        <td data-order="<?= (int)$row['threshold_student_count'] ?>">
                            <?= (int)$row['threshold_student_count'] ?>
                            active students
                        </td>

                        <td>
                            <?= number_format(
                                (int)$row['quantity']
                            ) ?>
                        </td>

                        <td>
                            <?php if (!empty($row['serial_number'])): ?>
                                <code>
                                    <?= e(
                                        (string)$row['serial_number']
                                    ) ?>
                                </code>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>

                        <td
                            data-order="<?= e((string)($row['date_received'] ?? '')) ?>"
                        >
                            <?php if (!empty($row['date_received'])): ?>
                                <?= e(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            (string)$row['date_received']
                                        )
                                    )
                                ) ?>
                            <?php else: ?>
                                <span class="badge text-bg-light border text-dark">
                                    Pending
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge text-bg-<?= e($conditionBadge) ?>">
                                <?= e(
                                    equipmentLabel($condition)
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= !empty($row['assigned_location'])
                                ? e(
                                    (string)$row['assigned_location']
                                )
                                : '<span class="text-secondary">—</span>' ?>
                        </td>

                        <td>
                            <?= e(
                                match ($provisionType) {
                                    'new' => 'New Allocation',
                                    'loan' => 'Loan',
                                    'replacement' => 'Replacement',
                                    'repair' => 'Repair',
                                    default =>
                                        equipmentLabel(
                                            $provisionType
                                        ),
                                }
                            ) ?>
                        </td>

                        <td>
                            <span
                                class="badge <?= $statusBadge === 'light' ? 'text-bg-light border text-dark' : 'text-bg-' . e($statusBadge) ?>"
                            >
                                <?= e(
                                    match ($assetStatus) {
                                        'pending_delivery' =>
                                            'Pending Delivery',
                                        'received' =>
                                            'Received',
                                        'in_use' =>
                                            'In Use',
                                        'on_loan' =>
                                            'On Loan',
                                        'under_repair' =>
                                            'Under Repair',
                                        'replaced' =>
                                            'Replaced',
                                        'retired' =>
                                            'Retired',
                                        default =>
                                            equipmentLabel(
                                                $assetStatus
                                            ),
                                    }
                                ) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card content-card mb-4">
    <div class="card-header bg-white p-4">
        <h2 class="h5 mb-1">
            <i class="bi bi-key me-2"></i>
            PSN Accounts
        </h2>

        <div class="small text-secondary">
            One PSN account is allocated at every 30-student milestone.
            Credentials are available only in the School PSN Accounts page after verification.
        </div>
        <?php if ($equipmentPortalRole === 'school'): ?>
        <a class="btn btn-sm btn-outline-primary mt-2" href="/school/psn_accounts.php"><i class="bi bi-shield-lock me-1"></i>View PSN Accounts</a>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table
                class="table table-hover align-middle w-100"
                id="psnAccountsTable"
            >
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Milestone</th>
                        <th>Credentials</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($psnAccounts as $account): ?>
                    <tr>
                        <td>
                            <?= (int)$account['academic_year'] ?>
                        </td>

                        <td>
                            <?= (int)$account['threshold_student_count'] ?>
                            active
                        </td>

                        <td><span class="text-secondary">Protected in School PSN Accounts</span></td>

                        <td>
                            <?php
                            $psnStatus =
                                (string)$account['account_status'];

                            $psnBadge =
                                $psnStatus === 'active'
                                    ? 'success'
                                    : (
                                        $psnStatus === 'disabled'
                                            ? 'secondary'
                                            : 'warning'
                                    );
                            ?>

                            <span class="badge text-bg-<?= e($psnBadge) ?>">
                                <?= e(
                                    equipmentLabel($psnStatus)
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= e(
                                date(
                                    'd M Y H:i',
                                    strtotime(
                                        (string)$account['updated_at']
                                    )
                                )
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
<?php
$actions = [
    ['repair','Repair Request','bi-tools','primary'],
    ['replacement','Replacement Request','bi-arrow-repeat','warning'],
    ['loan','Tournament Equipment Loan','bi-box-arrow-up-right','success'],
];
foreach ($actions as [$type,$title,$icon,$color]):
?>
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body p-4">
                <div class="d-flex gap-3 align-items-center mb-3">
                    <div class="icon-box bg-<?= e($color) ?>-subtle text-<?= e($color) ?>">
                        <i class="bi <?= e($icon) ?>"></i>
                    </div>
                    <h2 class="h5 mb-0"><?= e($title) ?></h2>
                </div>
                <button
                    type="button"
                    class="btn btn-outline-<?= e($color) ?> w-100 request-button"
                    data-type="<?= e($type) ?>"
                    data-bs-toggle="modal"
                    data-bs-target="#requestModal"
                    <?= (!$isEligible || !$tableReady) ? 'disabled' : '' ?>
                >
                    Submit Request
                </button>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="card content-card">
    <div class="card-header bg-white p-4">
        <h2 class="h5 mb-1">Request History</h2>
        <div class="small text-secondary"><?= count($requests) ?> recent request(s)</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Type</th>
                        <th>Details</th>
                        <th>Submitted By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-5">No requests submitted yet.</td></tr>
                <?php else: foreach ($requests as $row): ?>
                    <tr>
                        <td class="ps-4">
                            <strong><?= e(equipmentLabel((string)$row['request_type'])) ?></strong><br>
                            <small class="text-secondary">#<?= (int)$row['id'] ?></small>
                        </td>
                        <td>
                            <?php if ((string)$row['request_type'] === 'loan'): ?>
                                <strong><?= e((string)($row['tournament_name'] ?? 'Tournament')) ?></strong><br>
                                <small class="text-secondary">
                                    <?= e((string)$row['loan_start_date']) ?> → <?= e((string)$row['loan_end_date']) ?>
                                    · <?= (int)$row['loan_days'] ?> day(s)
                                </small><br>
                                <small><?= nl2br(e((string)($row['equipment_required'] ?? ''))) ?></small>
                            <?php else: ?>
                                <strong><?= e((string)($row['equipment_type'] ?? 'Equipment')) ?> × <?= (int)$row['quantity'] ?></strong>
                                <?php if (!empty($row['asset_tag'])): ?>
                                    <br><small class="text-secondary">Asset: <?= e((string)$row['asset_tag']) ?></small>
                                <?php endif; ?>
                                <br><small><?= nl2br(e((string)($row['issue_description'] ?? ''))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string)($row['requested_by_name'] ?? '')) ?><br>
                            <small class="text-secondary"><?= e(equipmentLabel((string)$row['requested_by_role'])) ?></small>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(equipmentStatusBadge((string)$row['request_status'])) ?>">
                                <?= e(equipmentLabel((string)$row['request_status'])) ?>
                            </span>
                        </td>
                        <td><?= e(date('d M Y', strtotime((string)$row['created_at']))) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="equipmentRequestForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="modal-header">
                    <h2 class="modal-title fs-5">Equipment Request</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label required">Request Type</label>
                            <select class="form-select" id="requestType" name="request_type" required>
                                <option value="repair">Repair</option>
                                <option value="replacement">Replacement</option>
                                <option value="loan">Tournament Equipment Loan</option>
                            </select>
                        </div>

                        <div class="col-12" id="equipmentFields">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required">Equipment</label>
                                    <select class="form-select" id="equipmentType" name="equipment_type">
                                        <option value="">SELECT EQUIPMENT</option>
                                        <option>PlayStation 5</option>
                                        <option>PS5 Controller</option>
                                        <option>PS5 Controller Charger</option>
                                        <option>PS5 Controller Cover</option>
                                        <option>PS5 Camera</option>
                                        <option>LCD TV</option>
                                        <option>TV Stand</option>
                                        <option>PSN Account</option>
                                        <option>Network / Accessory</option>
                                        <option>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Asset / Serial No.</label>
                                    <input class="form-control" name="asset_tag" maxlength="100">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Quantity</label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" max="99">
                                </div>
                                <div class="col-12">
                                    <label class="form-label required">Problem / Damage</label>
                                    <textarea class="form-control" id="issueDescription" name="issue_description" rows="3"></textarea>
                                </div>
                                <div class="col-12 d-none" id="replacementReasonGroup">
                                    <label class="form-label required">Replacement Reason</label>
                                    <textarea class="form-control" id="replacementReason" name="reason" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 d-none" id="loanFields">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label required">Tournament Name</label>
                                    <input class="form-control" id="tournamentName" name="tournament_name" maxlength="200">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Location</label>
                                    <input class="form-control" name="tournament_location" maxlength="200">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Loan Start</label>
                                    <input type="date" class="form-control" id="loanStart" name="loan_start_date">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Loan End</label>
                                    <input type="date" class="form-control" id="loanEnd" name="loan_end_date">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Loan Duration</label>
                                    <div class="form-control bg-light" id="loanDays">Select dates</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label required">Equipment Required</label>
                                    <textarea
                                        class="form-control"
                                        id="equipmentRequired"
                                        name="equipment_required"
                                        rows="4"
                                        placeholder="Example: 2 × PlayStation 5, 4 × controllers, 2 × 43-inch TV"
                                    ></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" <?= (!$isEligible || !$tableReady) ? 'disabled' : '' ?>>
                        <i class="bi bi-send me-2"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var requestType = document.getElementById('requestType');
    var equipmentFields = document.getElementById('equipmentFields');
    var loanFields = document.getElementById('loanFields');
    var replacementReasonGroup = document.getElementById('replacementReasonGroup');

    var equipmentType = document.getElementById('equipmentType');
    var quantity = document.getElementById('quantity');
    var issue = document.getElementById('issueDescription');
    var replacementReason = document.getElementById('replacementReason');
    var tournamentName = document.getElementById('tournamentName');
    var loanStart = document.getElementById('loanStart');
    var loanEnd = document.getElementById('loanEnd');
    var equipmentRequired = document.getElementById('equipmentRequired');
    var loanDays = document.getElementById('loanDays');

    function refreshForm() {
        var type = requestType.value;
        var isLoan = type === 'loan';
        var isReplacement = type === 'replacement';

        equipmentFields.classList.toggle('d-none', isLoan);
        loanFields.classList.toggle('d-none', !isLoan);
        replacementReasonGroup.classList.toggle('d-none', !isReplacement);

        equipmentType.required = !isLoan;
        quantity.required = !isLoan;
        issue.required = !isLoan;
        replacementReason.required = isReplacement;
        tournamentName.required = isLoan;
        loanStart.required = isLoan;
        loanEnd.required = isLoan;
        equipmentRequired.required = isLoan;
    }

    function updateDays() {
        if (!loanStart.value || !loanEnd.value) {
            loanDays.textContent = 'Select dates';
            return;
        }

        var start = new Date(loanStart.value + 'T00:00:00');
        var end = new Date(loanEnd.value + 'T00:00:00');

        if (end < start) {
            loanDays.textContent = 'Invalid date range';
            return;
        }

        var days = Math.floor((end - start) / 86400000) + 1;
        loanDays.textContent = days + ' day' + (days === 1 ? '' : 's');
    }

    document.querySelectorAll('.request-button').forEach(function (button) {
        button.addEventListener('click', function () {
            requestType.value = button.getAttribute('data-type') || 'repair';
            refreshForm();
            updateDays();
        });
    });

    requestType.addEventListener('change', refreshForm);
    loanStart.addEventListener('change', updateDays);
    loanEnd.addEventListener('change', updateDays);

    refreshForm();
});
</script>


<link
    rel="stylesheet"
    href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css"
>
<link
    rel="stylesheet"
    href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.bootstrap5.min.css"
>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (
        window.jQuery
        && jQuery.fn
        && jQuery.fn.DataTable
        && document.getElementById('equipmentInventoryTable')
    ) {
        jQuery('#equipmentInventoryTable').DataTable({
            pageLength: 10,
            lengthChange: false,
            order: [
                [0, 'desc'],
                [2, 'asc'],
                [1, 'asc']
            ],
            columnDefs: [
                {
                    targets: 0,
                    visible: false
                }
            ],
            rowGroup: {
                dataSrc: 0,
                startRender: function (rows, group) {
                    return jQuery('<tr/>')
                        .append(
                            '<td colspan="9" class="fw-semibold bg-light">'
                            + '<i class="bi bi-calendar3 me-2"></i>'
                            + group
                            + ' Registration Year'
                            + ' <span class="badge text-bg-dark ms-2">'
                            + rows.count()
                            + ' record(s)</span>'
                            + '</td>'
                        );
                }
            },
            language: {
                emptyTable: 'No equipment inventory records yet.'
            }
        });
    }

    if (
        window.jQuery
        && jQuery.fn
        && jQuery.fn.DataTable
        && document.getElementById('psnAccountsTable')
    ) {
        jQuery('#psnAccountsTable').DataTable({
            pageLength: 10,
            lengthChange: false,
            order: [[0, 'desc'], [1, 'asc']],
            language: {
                emptyTable: 'No PSN accounts allocated yet.'
            }
        });
    }
});
</script>

<?php portalPageEnd(); ?>

