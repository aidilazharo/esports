<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jpn_auth.php';
require_once __DIR__ . '/../includes/jpn_layout.php';
require_once __DIR__ . '/../includes/jpn_report_service.php';

$official = jpnAuthRequire();
$scope = jpnAuthScope('s');

$period = strtolower(
    trim((string)($_GET['period'] ?? 'monthly'))
);

if (!in_array($period, ['monthly', 'yearly'], true)) {
    $period = 'monthly';
}

$month = trim(
    (string)($_GET['month'] ?? date('Y-m'))
);

$year = (int)(
    $_GET['year']
    ?? date('Y')
);

$range = jpnReportRange(
    $period,
    $month,
    $year
);

$rows = jpnReportSchoolRows(
    $pdo,
    $scope,
    $range
);

$totals = jpnReportTotals($rows);

if (
    isset($_GET['export'])
    && $_GET['export'] === 'csv'
) {
    header(
        'Content-Type: text/csv; charset=utf-8'
    );
    header(
        'Content-Disposition: attachment; filename="jpn-report-'
        . $range['period']
        . '-'
        . preg_replace('/[^0-9A-Za-z_-]/', '-', (string)$range['label'])
        . '.csv"'
    );

    $out = fopen('php://output', 'wb');

    fputcsv($out, [
        'Bil',
        'Kod Sekolah',
        'Sekolah',
        'Daerah',
        'Negeri',
        'Melayu L',
        'Melayu P',
        'Cina L',
        'Cina P',
        'India L',
        'India P',
        'Asli L',
        'Asli P',
        'Lain-lain L',
        'Lain-lain P',
        'Jumlah Murid',
        'Jumlah Guru',
    ]);

    foreach ($rows as $index => $row) {
        fputcsv($out, [
            $index + 1,
            $row['school_code'],
            $row['school_name'],
            $row['district'],
            $row['state'],
            $row['malay_m'],
            $row['malay_f'],
            $row['chinese_m'],
            $row['chinese_f'],
            $row['indian_m'],
            $row['indian_f'],
            $row['asli_m'],
            $row['asli_f'],
            $row['other_m'],
            $row['other_f'],
            $row['total_students'],
            $row['total_teachers'],
        ]);
    }

    fclose($out);
    exit;
}

jpnPageStart(
    'report',
    'Monthly / Yearly Report',
    $official,
    $scope['label']
);
?>

<div class="card stat-card mb-4 no-print">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Report Type</label>
                <select
                    class="form-select"
                    name="period"
                    id="reportPeriod"
                >
                    <option
                        value="monthly"
                        <?= $range['period'] === 'monthly' ? 'selected' : '' ?>
                    >
                        Monthly
                    </option>
                    <option
                        value="yearly"
                        <?= $range['period'] === 'yearly' ? 'selected' : '' ?>
                    >
                        Yearly
                    </option>
                </select>
            </div>

            <div class="col-md-3" id="monthGroup">
                <label class="form-label">Month</label>
                <input
                    type="month"
                    class="form-control"
                    name="month"
                    value="<?= e((string)($range['month'] ?? date('Y-m'))) ?>"
                >
            </div>

            <div class="col-md-3" id="yearGroup">
                <label class="form-label">Year</label>
                <input
                    type="number"
                    class="form-control"
                    name="year"
                    min="2020"
                    max="2100"
                    value="<?= (int)$range['year'] ?>"
                >
            </div>

            <div class="col-md-3 d-grid">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-search me-2"></i>View Report
                </button>
            </div>
        </form>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <a
                class="btn btn-outline-success"
                href="?period=<?= e($range['period']) ?>&month=<?= e((string)($range['month'] ?? '')) ?>&year=<?= (int)$range['year'] ?>&export=csv"
            >
                <i class="bi bi-filetype-csv me-2"></i>Export CSV
            </a>

            <button
                type="button"
                class="btn btn-outline-dark"
                onclick="window.print()"
            >
                <i class="bi bi-printer me-2"></i>Print
            </button>
        </div>
    </div>
</div>

<div class="card stat-card mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="text-center mb-4">
            <div class="small text-uppercase text-secondary">
                JPN Education Department
            </div>
            <h2 class="h4 mb-1">
                LAPORAN PROGRAM / AKTIVITI
            </h2>
            <div class="fw-semibold">
                Program Pembangunan Bakat STEM dan Sukan Elektronik
            </div>
        </div>

        <div class="row g-2 mb-4">
            <div class="col-md-3 text-secondary">
                Tempoh Laporan
            </div>
            <div class="col-md-9 fw-semibold">
                <?= e((string)$range['label']) ?>
            </div>

            <div class="col-md-3 text-secondary">
                Kawasan
            </div>
            <div class="col-md-9 fw-semibold">
                <?= e((string)$scope['label']) ?>
            </div>

            <div class="col-md-3 text-secondary">
                Sekolah Terlibat
            </div>
            <div class="col-md-9 fw-semibold">
                <?= number_format($totals['schools']) ?>
            </div>

            <div class="col-md-3 text-secondary">
                Penyertaan
            </div>
            <div class="col-md-9 fw-semibold">
                <?= number_format($totals['total_students']) ?> Murid
                ·
                <?= number_format($totals['total_teachers']) ?> Guru
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered report-table text-center">
                <thead class="table-light">
                    <tr>
                        <th rowspan="3">Bil</th>
                        <th rowspan="3">Kod Sekolah</th>
                        <th rowspan="3">Sekolah</th>
                        <th rowspan="3">Daerah / Negeri</th>
                        <th colspan="10">Bangsa / Jantina Murid</th>
                        <th colspan="2">Jumlah</th>
                    </tr>
                    <tr>
                        <th colspan="2">Melayu</th>
                        <th colspan="2">Cina</th>
                        <th colspan="2">India</th>
                        <th colspan="2">Asli</th>
                        <th colspan="2">Lain-lain</th>
                        <th rowspan="2">Murid</th>
                        <th rowspan="2">Guru</th>
                    </tr>
                    <tr>
                        <th>L</th><th>P</th>
                        <th>L</th><th>P</th>
                        <th>L</th><th>P</th>
                        <th>L</th><th>P</th>
                        <th>L</th><th>P</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td
                            colspan="16"
                            class="py-5 text-secondary"
                        >
                            No participation records found for this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e((string)$row['school_code']) ?></td>
                            <td class="text-start">
                                <?= e((string)$row['school_name']) ?>
                            </td>
                            <td class="text-start">
                                <?= e((string)$row['district']) ?>
                                <br>
                                <small class="text-secondary">
                                    <?= e((string)$row['state']) ?>
                                </small>
                            </td>

                            <td><?= (int)$row['malay_m'] ?></td>
                            <td><?= (int)$row['malay_f'] ?></td>
                            <td><?= (int)$row['chinese_m'] ?></td>
                            <td><?= (int)$row['chinese_f'] ?></td>
                            <td><?= (int)$row['indian_m'] ?></td>
                            <td><?= (int)$row['indian_f'] ?></td>
                            <td><?= (int)$row['asli_m'] ?></td>
                            <td><?= (int)$row['asli_f'] ?></td>
                            <td><?= (int)$row['other_m'] ?></td>
                            <td><?= (int)$row['other_f'] ?></td>

                            <td class="fw-semibold">
                                <?= (int)$row['total_students'] ?>
                            </td>
                            <td class="fw-semibold">
                                <?= (int)$row['total_teachers'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="4" class="text-end">
                            JUMLAH
                        </td>
                        <td><?= $totals['malay_m'] ?></td>
                        <td><?= $totals['malay_f'] ?></td>
                        <td><?= $totals['chinese_m'] ?></td>
                        <td><?= $totals['chinese_f'] ?></td>
                        <td><?= $totals['indian_m'] ?></td>
                        <td><?= $totals['indian_f'] ?></td>
                        <td><?= $totals['asli_m'] ?></td>
                        <td><?= $totals['asli_f'] ?></td>
                        <td><?= $totals['other_m'] ?></td>
                        <td><?= $totals['other_f'] ?></td>
                        <td><?= $totals['total_students'] ?></td>
                        <td><?= $totals['total_teachers'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-4 small text-secondary">
            Generated:
            <?= e(date('d M Y, h:i A')) ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var period = document.getElementById('reportPeriod');
    var monthGroup = document.getElementById('monthGroup');
    var yearGroup = document.getElementById('yearGroup');

    function refreshPeriodFields() {
        var monthly = period.value === 'monthly';

        monthGroup.classList.toggle('d-none', !monthly);
        yearGroup.classList.toggle('d-none', monthly);
    }

    period.addEventListener('change', refreshPeriodFields);
    refreshPeriodFields();
});
</script>

<?php jpnPageEnd(); ?>
