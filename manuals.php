<?php
declare(strict_types=1);

require_once 'config.php';

$pageTitle = 'Manuals';
$seoTitle = 'Programme Manuals';
$seoDescription =
    'Programme manuals and user guides for schools, teachers, parents and students.';

require 'includes/header.php';
?>

<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-lg-8 col-xl-7">
        <div class="card content-card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5 text-center">
                <div
                    class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary mb-4"
                    style="width:72px;height:72px;"
                >
                    <i class="bi bi-book fs-2"></i>
                </div>

                <span class="badge text-bg-primary mb-3">
                    Manuals
                </span>

                <h1 class="h2 mb-3">
                    Programme Manuals
                </h1>

                <p class="lead text-secondary mb-3">
                    User guides and programme manuals are being prepared.
                </p>

                <p class="text-secondary mb-4">
                    Manuals for schools, teachers, parents and students will be
                    published here soon.
                </p>

                <div class="alert alert-light border mb-4">
                    <i class="bi bi-clock-history me-2"></i>
                    <strong>Coming Soon</strong>
                </div>

                <a href="/about-programme.php" class="btn btn-primary">
                    <i class="bi bi-info-circle me-2"></i>
                    About Programme
                </a>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
