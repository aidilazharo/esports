<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jpn_auth.php';
require_once __DIR__ . '/../includes/jpn_layout.php';
require_once __DIR__ . '/../includes/jpn_report_service.php';

$official = jpnAuthRequire();
$scope = jpnAuthScope('s');

$range = jpnReportRange(
    'yearly',
    null,
    (int)date('Y')
);

$rows = jpnReportSchoolRows(
    $pdo,
    $scope,
    $range
);

$totals = jpnReportTotals($rows);
$activeStudents = jpnCurrentActiveStudents(
    $pdo,
    $scope
);

$topSchools = $rows;

usort(
    $topSchools,
    static fn(array $a, array $b): int =>
        ((int)$b['total_students'])
        <=> ((int)$a['total_students'])
);

$topSchools = array_slice(
    $topSchools,
    0,
    10
);

jpnPageStart(
    'dashboard',
    'Overall Dashboard',
    $official,
    $scope['label']
);
?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="text-secondary small mb-1">Participating Schools</div>
                <div class="display-6 fw-semibold">
                    <?= number_format($totals['schools']) ?>
                </div>
                <div class="small text-secondary">
                    <?= e((string)$range['label']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="text-secondary small mb-1">Student Registrations</div>
                <div class="display-6 fw-semibold">
                    <?= number_format($totals['total_students']) ?>
                </div>
                <div class="small text-secondary">
                    <?= e((string)$range['label']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="text-secondary small mb-1">Active Students</div>
                <div class="display-6 fw-semibold text-success">
                    <?= number_format($activeStudents) ?>
                </div>
                <div class="small text-secondary">
                    Current membership status
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body p-4">
                <div class="text-secondary small mb-1">Teacher Registrations</div>
                <div class="display-6 fw-semibold">
                    <?= number_format($totals['total_teachers']) ?>
                </div>
                <div class="small text-secondary">
                    <?= e((string)$range['label']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white p-4">
                <h2 class="h5 mb-1">Top Participating Schools</h2>
                <div class="small text-secondary">
                    Based on student registrations this year
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">School</th>
                                <th>District</th>
                                <th class="text-end">Students</th>
                                <th class="text-end pe-4">Teachers</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$topSchools): ?>
                            <tr>
                                <td colspan="4" class="text-center text-secondary py-5">
                                    No participation recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topSchools as $row): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-semibold">
                                            <?= e((string)$row['school_name']) ?>
                                        </div>
                                        <small class="text-secondary">
                                            <?= e((string)$row['school_code']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= e((string)$row['district']) ?>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format((int)$row['total_students']) ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?= number_format((int)$row['total_teachers']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white p-4">
                <h2 class="h5 mb-1">Student Demographics</h2>
                <div class="small text-secondary">
                    <?= e((string)$range['label']) ?>
                </div>
            </div>

            <div class="card-body p-4">
                <?php
                $groups = [
                    'Malay' => $totals['malay_m'] + $totals['malay_f'],
                    'Chinese' => $totals['chinese_m'] + $totals['chinese_f'],
                    'Indian' => $totals['indian_m'] + $totals['indian_f'],
                    'Orang Asli' => $totals['asli_m'] + $totals['asli_f'],
                    'Others' => $totals['other_m'] + $totals['other_f'],
                ];

                $maxGroup = max(1, ...array_values($groups));
                ?>

                <?php foreach ($groups as $label => $count): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><?= e($label) ?></span>
                            <strong><?= number_format($count) ?></strong>
                        </div>

                        <div class="progress" style="height:8px">
                            <div
                                class="progress-bar"
                                style="width:<?= (int)round(($count / $maxGroup) * 100) ?>%"
                            ></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <hr>

                <div class="d-flex justify-content-between">
                    <span>Male</span>
                    <strong>
                        <?= number_format(
                            $totals['malay_m']
                            + $totals['chinese_m']
                            + $totals['indian_m']
                            + $totals['asli_m']
                            + $totals['other_m']
                        ) ?>
                    </strong>
                </div>

                <div class="d-flex justify-content-between mt-2">
                    <span>Female</span>
                    <strong>
                        <?= number_format(
                            $totals['malay_f']
                            + $totals['chinese_f']
                            + $totals['indian_f']
                            + $totals['asli_f']
                            + $totals['other_f']
                        ) ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-body p-4 d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
        <div>
            <h2 class="h5 mb-1">Monthly / Yearly Reporting</h2>
            <div class="text-secondary">
                Generate school participation and demographic reports for the selected period.
            </div>
        </div>

        <a class="btn btn-primary" href="/jpn/report.php">
            <i class="bi bi-file-earmark-bar-graph me-2"></i>
            Open Reports
        </a>
    </div>
</div>

<?php jpnPageEnd(); ?>
