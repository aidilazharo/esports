<?php
require_once 'config.php';
require_once 'includes/admin_auth.php';

requireAdminAccess('Stripe Payments');

$perPage = 10;
$month = trim((string) ($_GET['month'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));

$statusGroups = [
    'success' => [
        'label' => 'Successful',
        'values' => ['paid', 'succeeded', 'success', 'successful', 'complete', 'completed'],
    ],
    'failed' => [
        'label' => 'Failed',
        'values' => ['failed', 'payment_failed', 'declined'],
    ],
    'pending' => [
        'label' => 'Pending / Processing',
        'values' => ['pending', 'processing', 'open', 'unpaid'],
    ],
    'requires_action' => [
        'label' => 'Requires Action',
        'values' => ['requires_action', 'payment_action_required'],
    ],
    'refunded' => [
        'label' => 'Refunded',
        'values' => ['refunded', 'partially_refunded'],
    ],
    'cancelled' => [
        'label' => 'Cancelled / Expired',
        'values' => ['cancelled', 'canceled', 'expired', 'void'],
    ],
    'other' => [
        'label' => 'Other',
        'values' => [],
    ],
];

if ($statusFilter !== '' && !array_key_exists($statusFilter, $statusGroups)) {
    $statusFilter = '';
}

$normaliseStatus = static function (?string $status): string {
    $normalised = strtolower(trim((string) $status));
    return str_replace(['-', ' '], '_', $normalised);
};

$statusDisplay = static function (?string $status) use ($normaliseStatus, $statusGroups): array {
    $normalised = $normaliseStatus($status);

    foreach ($statusGroups as $key => $group) {
        if ($key !== 'other' && in_array($normalised, $group['values'], true)) {
            return match ($key) {
                'success' => ['label' => 'Successful', 'badge' => 'success'],
                'failed' => ['label' => 'Failed', 'badge' => 'danger'],
                'pending' => ['label' => 'Pending', 'badge' => 'warning'],
                'requires_action' => ['label' => 'Requires Action', 'badge' => 'warning'],
                'refunded' => ['label' => 'Refunded', 'badge' => 'info'],
                'cancelled' => ['label' => 'Cancelled', 'badge' => 'secondary'],
                default => ['label' => ucwords(str_replace('_', ' ', $normalised)), 'badge' => 'dark'],
            };
        }
    }

    return [
        'label' => $normalised !== '' ? ucwords(str_replace('_', ' ', $normalised)) : 'Unknown',
        'badge' => 'dark',
    ];
};

$bindParams = static function (PDOStatement $statement, array $params): void {
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
};

$baseWhere = [];
$baseParams = [];

if ($month !== '') {
    $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0);

    if ($monthDate === false || $hasDateErrors || $monthDate->format('Y-m') !== $month) {
        $month = '';
    } else {
        $baseWhere[] = 'p.created_at >= :month_start AND p.created_at < :month_end';
        $baseParams[':month_start'] = $monthDate->format('Y-m-01 00:00:00');
        $baseParams[':month_end'] = $monthDate->modify('+1 month')->format('Y-m-01 00:00:00');
    }
}

if ($search !== '') {
    $baseWhere[] = '(st.full_name LIKE :search_student
                     OR st.student_number LIKE :search_number
                     OR s.school_name LIKE :search_school
                     OR s.school_code LIKE :search_code
                     OR p.description LIKE :search_description
                     OR p.stripe_invoice_id LIKE :search_invoice)';
    $like = '%' . $search . '%';
    $baseParams[':search_student'] = $like;
    $baseParams[':search_number'] = $like;
    $baseParams[':search_school'] = $like;
    $baseParams[':search_code'] = $like;
    $baseParams[':search_description'] = $like;
    $baseParams[':search_invoice'] = $like;
}

$tableWhere = $baseWhere;
$tableParams = $baseParams;

$knownStatuses = [];
foreach ($statusGroups as $key => $group) {
    if ($key !== 'other') {
        $knownStatuses = array_merge($knownStatuses, $group['values']);
    }
}
$knownStatuses = array_values(array_unique($knownStatuses));

if ($statusFilter !== '') {
    if ($statusFilter === 'other') {
        $placeholders = [];
        foreach ($knownStatuses as $index => $statusValue) {
            $placeholder = ':known_status_' . $index;
            $placeholders[] = $placeholder;
            $tableParams[$placeholder] = $statusValue;
        }

        $tableWhere[] = "LOWER(REPLACE(REPLACE(COALESCE(p.status, ''), '-', '_'), ' ', '_')) NOT IN ("
            . implode(', ', $placeholders)
            . ")";
    } else {
        $placeholders = [];
        foreach ($statusGroups[$statusFilter]['values'] as $index => $statusValue) {
            $placeholder = ':filter_status_' . $index;
            $placeholders[] = $placeholder;
            $tableParams[$placeholder] = $statusValue;
        }

        $tableWhere[] = "LOWER(REPLACE(REPLACE(COALESCE(p.status, ''), '-', '_'), ' ', '_')) IN ("
            . implode(', ', $placeholders)
            . ")";
    }
}

$fromSql = ' FROM payments p
             JOIN students st ON st.id = p.student_id
             JOIN schools s ON s.id = st.school_id';
$baseWhereSql = $baseWhere ? ' WHERE ' . implode(' AND ', $baseWhere) : '';
$tableWhereSql = $tableWhere ? ' WHERE ' . implode(' AND ', $tableWhere) : '';

$successValues = $statusGroups['success']['values'];
$failedValues = $statusGroups['failed']['values'];
$pendingValues = array_merge(
    $statusGroups['pending']['values'],
    $statusGroups['requires_action']['values']
);

$quoteValues = static fn(array $values): string => implode(
    ', ',
    array_map(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", $values)
);

$normalisedStatusSql = "LOWER(REPLACE(REPLACE(COALESCE(p.status, ''), '-', '_'), ' ', '_'))";
$summarySql = 'SELECT
                  COUNT(*) AS total_records,
                  COALESCE(SUM(CASE WHEN ' . $normalisedStatusSql . ' IN (' . $quoteValues($successValues) . ') THEN 1 ELSE 0 END), 0) AS success_count,
                  COALESCE(SUM(CASE WHEN ' . $normalisedStatusSql . ' IN (' . $quoteValues($failedValues) . ') THEN 1 ELSE 0 END), 0) AS failed_count,
                  COALESCE(SUM(CASE WHEN ' . $normalisedStatusSql . ' IN (' . $quoteValues($pendingValues) . ') THEN 1 ELSE 0 END), 0) AS pending_count,
                  COALESCE(SUM(CASE WHEN ' . $normalisedStatusSql . ' IN (' . $quoteValues($successValues) . ') THEN p.amount_minor ELSE 0 END), 0) AS successful_amount_minor'
              . $fromSql
              . $baseWhereSql;

$summaryStmt = $pdo->prepare($summarySql);
$bindParams($summaryStmt, $baseParams);
$summaryStmt->execute();
$summary = $summaryStmt->fetch() ?: [
    'total_records' => 0,
    'success_count' => 0,
    'failed_count' => 0,
    'pending_count' => 0,
    'successful_amount_minor' => 0,
];

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $tableWhereSql);
$bindParams($countStmt, $tableParams);
$countStmt->execute();
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT p.*, st.full_name, st.student_number, s.school_name, s.school_code'
    . $fromSql
    . $tableWhereSql
    . ' ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset'
);
$bindParams($stmt, $tableParams);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

$showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalRecords);

$buildPageUrl = static function (int $targetPage): string {
    $query = $_GET;
    $query['page'] = $targetPage;

    if ($targetPage <= 1) {
        unset($query['page']);
    }

    return '?' . http_build_query($query);
};

$pageTitle = 'Stripe Payments';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h2 mb-1">Stripe Payments</h1>
        <p class="text-secondary mb-0">View student payments. New memberships use one RM100 payment for 1 year; historical records may still show the previous payment structure.</p>
    </div>
    <a href="memberships.php" class="btn btn-outline-primary">View Memberships</a>
</div>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="payment-month">Payment Month</label>
                <input
                    type="month"
                    class="form-control"
                    id="payment-month"
                    name="month"
                    value="<?= e($month) ?>"
                >
                <div class="form-text">Leave empty to display all months.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="payment-status">Payment Status</label>
                <select class="form-select" id="payment-status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statusGroups as $key => $group): ?>
                        <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                            <?= e($group['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="payment-search">Search</label>
                <input
                    type="search"
                    class="form-control"
                    id="payment-search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Student, school, description or invoice"
                >
            </div>

            <div class="col-md-2">
                <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-funnel me-2"></i>Filter
                    </button>
                    <a class="btn btn-outline-secondary" href="payments.php">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="small text-secondary mb-1">Total Records</div>
                <div class="h3 mb-0"><?= number_format((int) $summary['total_records']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="small text-secondary mb-1">Successful</div>
                <div class="h3 mb-0 text-success"><?= number_format((int) $summary['success_count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="small text-secondary mb-1">Failed</div>
                <div class="h3 mb-0 text-danger"><?= number_format((int) $summary['failed_count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="small text-secondary mb-1">Pending / Action</div>
                <div class="h3 mb-0 text-warning"><?= number_format((int) $summary['pending_count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="small text-secondary mb-1">Successful Amount</div>
                <div class="h3 mb-0"><?= e(moneyFromMinor((int) $summary['successful_amount_minor'])) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card content-card">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">Payment Records</h2>
                <div class="small text-secondary">
                    <?= $month !== '' ? e(date('F Y', strtotime($month . '-01'))) : 'All months' ?>
                    <?= $statusFilter !== '' ? ' · ' . e($statusGroups[$statusFilter]['label']) : ' · All statuses' ?>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>School</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Invoice</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="7" class="text-center text-secondary py-5">
                            No payment records match the selected month and status.
                        </td>
                    </tr>
                <?php else: foreach ($payments as $payment): ?>
                    <?php $paymentStatus = $statusDisplay($payment['status'] ?? null); ?>
                    <tr>
                        <td class="text-nowrap"><?= e(date('d M Y H:i', strtotime($payment['created_at']))) ?></td>
                        <td>
                            <div class="fw-semibold"><?= e($payment['full_name']) ?></div>
                            <small class="text-secondary"><?= e($payment['student_number']) ?></small>
                        </td>
                        <td>
                            <?= e($payment['school_name']) ?><br>
                            <small class="text-secondary"><?= e($payment['school_code']) ?></small>
                        </td>
                        <td><?= e($payment['description']) ?></td>
                        <td class="fw-semibold text-nowrap"><?= e(moneyFromMinor((int) $payment['amount_minor'])) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e($paymentStatus['badge']) ?>">
                                <?= e($paymentStatus['label']) ?>
                            </span>
                            <?php if (!empty($payment['status'])): ?>
                                <div class="small text-secondary mt-1"><?= e((string) $payment['status']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($payment['stripe_invoice_id'] ?: '-') ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-4">
            <div class="small text-secondary">
                Showing <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format($totalRecords) ?> filtered records
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Payment records pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(max(1, $page - 1))) ?>">Previous</a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                        ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($buildPageUrl($pageNumber)) ?>"><?= $pageNumber ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(min($totalPages, $page + 1))) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
