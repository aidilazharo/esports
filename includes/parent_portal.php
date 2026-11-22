<?php
declare(strict_types=1);

function parentPortalOwnsStudent(PDO $pdo, int $guardianId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM student_guardians WHERE guardian_id=? AND student_id=?');
    $stmt->execute([$guardianId, $studentId]);
    return (int)$stmt->fetchColumn() > 0;
}

function parentPortalOwnsLink(PDO $pdo, int $guardianId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM student_guardians WHERE student_id=? AND guardian_id=?');
    $stmt->execute([$studentId, $guardianId]);
    return (int)$stmt->fetchColumn() > 0;
}

function parentPortalStudentOptions(PDO $pdo, int $guardianId): array
{
    $stmt = $pdo->prepare("SELECT st.id, CONCAT(st.student_number,' — ',st.full_name,' — ',COALESCE(s.school_code,'')) AS label
                           FROM student_guardians sg
                           JOIN students st ON st.id=sg.student_id
                           LEFT JOIN schools s ON s.id=st.school_id
                           WHERE sg.guardian_id=? ORDER BY st.full_name");
    $stmt->execute([$guardianId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(int)$row['id']] = (string)$row['label'];
    return $out;
}

function parentPortalScalar(PDO $pdo, string $sql, array $params = [], int|float|string $fallback = 0): int|float|string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $fallback : $value;
    } catch (Throwable $e) {
        error_log('Parent portal query failed: ' . $e->getMessage());
        return $fallback;
    }
}

function parentPortalStatusBadge(string $status): string
{
    $key = strtolower(trim($status));
    $class = match ($key) {
        'active','paid','completed','approved','open' => 'success',
        'pending','pending_payment','pending_activation','applied','draft','unpaid','in_progress' => 'warning',
        'cancelled','rejected','revoked','inactive','expired','failed','withdrawn' => 'danger',
        default => 'secondary',
    };
    return '<span class="badge text-bg-' . e($class) . '">' . e(ucwords(str_replace('_',' ',$status))) . '</span>';
}

function parentPortalDataTableScripts(string $tableId, array $order = [[0,'asc']], string $empty = 'No records found.'): void
{
    ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById(<?= json_encode($tableId) ?>);
        if (table && window.jQuery && jQuery.fn.DataTable) {
            jQuery(table).DataTable({
                pageLength: 10,
                lengthChange: false,
                searching: true,
                ordering: true,
                info: true,
                autoWidth: false,
                order: <?= json_encode($order) ?>,
                language: {search: 'Search:', emptyTable: <?= json_encode($empty) ?>}
            });
        }
    });
    </script>
    <?php
}
