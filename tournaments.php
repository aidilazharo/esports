<?php
require_once 'config.php';
require_once 'tournament_feed.php';

$pageTitle = 'PlayStation Tournaments';
$feed = fetchTournamentFeed();
$allTournaments = $feed['items'];

$search = trim((string) ($_GET['search'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$categories = [];
foreach ($allTournaments as $item) {
    $category = trim((string) ($item['category'] ?? ''));
    if ($category !== '') {
        $categories[$category] = $category;
    }
}
natcasesort($categories);

$allowedStatuses = ['daily', 'featured', 'check_ps5'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$filteredTournaments = array_values(array_filter(
    $allTournaments,
    static function (array $item) use ($search, $categoryFilter, $statusFilter): bool {
        if ($categoryFilter !== '' && strcasecmp((string) ($item['category'] ?? ''), $categoryFilter) !== 0) {
            return false;
        }

        if ($statusFilter !== '' && (string) ($item['status'] ?? '') !== $statusFilter) {
            return false;
        }

        if ($search !== '') {
            $haystack = strtolower((string) ($item['search_text'] ?? ''));
            if (!str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        return true;
    }
));

$perPage = 10;
$totalRecords = count($filteredTournaments);
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($filteredTournaments, $offset, $perPage);
$showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalRecords);

$buildPageUrl = static function (int $targetPage): string {
    $query = $_GET;

    if ($targetPage <= 1) {
        unset($query['page']);
    } else {
        $query['page'] = $targetPage;
    }

    return $query ? '?' . http_build_query($query) : 'tournaments.php';
};

require 'includes/header.php';
?>

<div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
    <div>
        <span class="badge text-bg-primary mb-2">Official PlayStation Feed</span>
        <h1 class="h2 mb-1">PlayStation Tournaments</h1>
        <p class="text-secondary mb-0">Competitive games and featured PlayStation events available through the official Malaysia tournament hub.</p>
    </div>
    <a
        href="<?= e((string) PLAYSTATION_TOURNAMENT_FEED_URL) ?>"
        class="btn btn-primary"
        target="_blank"
        rel="noopener noreferrer"
    >
        <i class="bi bi-playstation me-2"></i>Open Official PlayStation Page
    </a>
</div>

<div class="alert alert-light border mb-4">
    <i class="bi bi-info-circle me-2"></i>
    Exact local start times, brackets, player limits and rewards are shown on the tournament card on your PS5. Availability can differ by game and region.
</div>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-5">
                <label class="form-label">Search</label>
                <input
                    class="form-control"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search tournament or game"
                >
            </div>
            <div class="col-lg-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>>
                            <?= e($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">Schedule</label>
                <select class="form-select" name="status">
                    <option value="">All schedules</option>
                    <option value="daily" <?= $statusFilter === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="featured" <?= $statusFilter === 'featured' ? 'selected' : '' ?>>Featured Event</option>
                    <option value="check_ps5" <?= $statusFilter === 'check_ps5' ? 'selected' : '' ?>>Check on PS5</option>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-outline-primary"><i class="bi bi-funnel me-2"></i>Filter</button>
            </div>
            <div class="col-12">
                <a href="tournaments.php" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
            </div>
        </form>
    </div>
</div>

<div class="card content-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Tournament / Game</th>
                        <th>Category</th>
                        <th>Schedule</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Official Page</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$pageItems): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">No matching PlayStation tournaments found.</td></tr>
                <?php else: foreach ($pageItems as $item): ?>
                    <tr>
                        <td class="ps-4" style="min-width: 310px;">
                            <div class="d-flex align-items-start gap-3">
                                <div class="icon-box bg-primary-subtle text-primary flex-shrink-0">
                                    <i class="bi bi-controller"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold"><?= e((string) $item['name']) ?></div>
                                    <div class="small text-secondary mb-1"><?= e((string) $item['game']) ?></div>
                                    <?php if (!empty($item['description'])): ?>
                                        <div class="small text-secondary"><?= e((string) $item['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge text-bg-light border"><?= e((string) $item['category']) ?></span></td>
                        <td><?= e(tournamentDateLabel($item)) ?></td>
                        <td><?= e((string) $item['platform']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(tournamentStatusBadge((string) $item['status'])) ?>">
                                <?= e(tournamentStatusLabel((string) $item['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <a
                                href="<?= e((string) $item['official_url']) ?>"
                                class="btn btn-sm btn-outline-primary"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                View <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 p-4 border-top">
            <div class="small text-secondary">
                Showing <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format($totalRecords) ?> records
                <?php if ($feed['updated_at']): ?>
                    · Feed refreshed <?= e(date('d M Y H:i', strtotime((string) $feed['updated_at']))) ?>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="PlayStation tournament pagination">
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

<?php if ($feed['error'] !== null): ?>
    <div class="small text-secondary mt-3">
        <i class="bi bi-exclamation-triangle me-1"></i><?= e((string) $feed['error']) ?>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
