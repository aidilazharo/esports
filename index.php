<?php
require_once 'config.php';
require_once 'tournament_feed.php';
require_once __DIR__ . '/includes/video_gallery.php';

$pageTitle = 'Home';

$whatsappGroupUrl = 'https://chat.whatsapp.com/HmWZ6ETeYYMHYmEX2V3qH9?mode=gi_t';

$tournamentFeed = fetchTournamentFeed();
$featuredTournamentItems = featuredTournaments($tournamentFeed['items'], 1);
$featuredPlayStationTournament = $featuredTournamentItems[0] ?? null;

// Show one item from the local Events & Competitions system alongside one
// PlayStation tournament. Prefer an event that is currently open/upcoming.
$featuredPortalEvent = null;
try {
    $featuredPortalStmt = $pdo->query(
        "SELECT *
         FROM programme_events
         WHERE status IN ('open', 'closed', 'completed')
         ORDER BY
             CASE
                 WHEN status = 'open' AND application_deadline >= NOW() THEN 0
                 WHEN status = 'open' THEN 1
                 WHEN status = 'closed' THEN 2
                 ELSE 3
             END,
             CASE WHEN start_at IS NULL THEN 1 ELSE 0 END,
             start_at ASC,
             application_deadline ASC,
             created_at DESC
         LIMIT 1"
    );
    $featuredPortalEvent = $featuredPortalStmt->fetch() ?: null;
} catch (Throwable $e) {
    // Keep the home page available even before the Events & Competitions
    // database migration has been installed.
    $featuredPortalEvent = null;
}

function homePortalEventStatusLabel(array $event): string
{
    $status = (string) ($event['status'] ?? 'draft');
    $deadline = trim((string) ($event['application_deadline'] ?? ''));

    if ($status === 'open' && $deadline !== '' && strtotime($deadline) < time()) {
        return 'Deadline Closed';
    }

    return match ($status) {
        'open' => 'Open',
        'closed' => 'Closed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Draft',
    };
}

function homePortalEventStatusBadge(array $event): string
{
    $label = homePortalEventStatusLabel($event);

    return match ($label) {
        'Open' => 'success',
        'Completed' => 'primary',
        'Cancelled' => 'danger',
        'Draft' => 'warning',
        default => 'secondary',
    };
}

function homePortalEventDateLabel(?string $startAt, ?string $endAt): string
{
    $startAt = trim((string) $startAt);
    $endAt = trim((string) $endAt);

    if ($startAt === '' && $endAt === '') {
        return 'Date to be announced';
    }

    try {
        $start = $startAt !== '' ? new DateTimeImmutable($startAt) : null;
        $end = $endAt !== '' ? new DateTimeImmutable($endAt) : null;

        if ($start && $end) {
            if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                return $start->format('d M Y, g:i A') . ' – ' . $end->format('g:i A');
            }

            return $start->format('d M Y') . ' – ' . $end->format('d M Y');
        }

        return ($start ?? $end)->format('d M Y, g:i A');
    } catch (Throwable $e) {
        return $startAt !== '' ? $startAt : $endAt;
    }
}

require 'includes/header.php';
?>

<style>
    .hero-section {
        position: relative;
        overflow: hidden;
    }

    .hero-image-column {
        align-self: stretch;
        display: flex;
        align-items: flex-end;
        justify-content: center;
        margin-bottom: -3rem;
    }

    .hero-image {
        display: block;
        width: 100%;
        max-width: 430px;
        max-height: 435px;
        object-fit: contain;
        object-position: center bottom;
    }

    @media (max-width: 991.98px) {
        /* Use the original artwork as a decorative background at 20% opacity.
           Isolate the image layer so text, links and buttons remain opaque. */
        .hero-section {
            isolation: isolate;
        }
        .hero-section::before {
            content: "";
            position: absolute;
            inset: 0;
            background: url('/assets/images/hero.png') center center / contain no-repeat;
            opacity: 0.2;
            pointer-events: none;
            z-index: -1;
        }
        .hero-image-column {
            display: none;
        }
    }
    @media print {
        .hero-section::before { display: none; }
    }
</style>

<section class="hero-section p-4 p-md-5 mb-4 shadow-sm">
    <div class="row align-items-center g-4">
        <div class="col-lg-7">
            <span class="badge text-bg-light text-primary mb-3">
                STEM, TVET &amp; Digital Talent Programme
            </span>

            <h1 class="display-6 fw-bold">
                Transforming Students from Technology Users into Technology Creators.
            </h1>

            <p class="lead mb-4 opacity-75">
                A structured school programme combining STEM, TVET, artificial intelligence,
                simulation, digital innovation, learning modules and esports to build future-ready skills.
            </p>

            <div class="d-flex flex-wrap gap-2">
                <a href="about-programme.php" class="btn btn-outline-light btn-lg">
                    <i class="bi bi-info-circle me-2"></i>Explore the Programme
                </a>

                <a
                    href="<?= e($whatsappGroupUrl) ?>"
                    class="btn btn-success btn-lg"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Open programme support group on WhatsApp"
                >
                    <i class="bi bi-whatsapp me-2"></i>Support
                </a>
            </div>
        </div>

        <div class="col-lg-5 hero-image-column" aria-hidden="true">
            <img
                src="assets/images/hero.png"
                alt=""
                class="hero-image"
                loading="eager"
                decoding="async"
            >
        </div>
    </div>
</section>

<?php
portalRenderVideoGallery(
    portalVideosRandom($pdo),
    'Student & Teacher Videos',
    '',
    'home-videos'
);
?>

<section class="mt-4 mb-4" aria-labelledby="featured-tournaments-events-title">
    <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-3 mb-3">
        <div>
            <span class="badge text-bg-primary mb-2">Tournaments &amp; Events</span>
            <h2 class="h3 mb-1" id="featured-tournaments-events-title">Featured Tournaments &amp; Events</h2>
            <p class="text-secondary mb-0">
                One featured PlayStation tournament and one activity from Events &amp; Competitions.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="tournaments.php" class="btn btn-outline-primary">
                PlayStation Tournaments <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="events-competitions.php" class="btn btn-primary">
                Events &amp; Competitions <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card content-card h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div class="icon-box bg-primary-subtle text-primary">
                            <i class="bi bi-controller"></i>
                        </div>
                        <span class="badge text-bg-primary">PlayStation Tournament</span>
                    </div>

                    <?php if ($featuredPlayStationTournament): ?>
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                            <h3 class="h5 mb-0"><?= e((string) $featuredPlayStationTournament['name']) ?></h3>
                            <span class="badge text-bg-<?= e(tournamentStatusBadge((string) $featuredPlayStationTournament['status'])) ?>">
                                <?= e(tournamentStatusLabel((string) $featuredPlayStationTournament['status'])) ?>
                            </span>
                        </div>

                        <div class="text-secondary mb-3">
                            <?= e((string) $featuredPlayStationTournament['game']) ?>
                            · <?= e((string) $featuredPlayStationTournament['category']) ?>
                        </div>

                        <div class="small mb-3">
                            <div class="text-secondary">Schedule</div>
                            <div class="fw-semibold"><?= e(tournamentDateLabel($featuredPlayStationTournament)) ?></div>
                        </div>

                        <a
                            href="<?= e((string) $featuredPlayStationTournament['official_url']) ?>"
                            class="btn btn-sm btn-primary"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Open Official Page <i class="bi bi-box-arrow-up-right ms-1"></i>
                        </a>
                    <?php else: ?>
                        <h3 class="h5 mb-2">PlayStation Tournament</h3>
                        <p class="text-secondary mb-3">
                            No PlayStation tournament is currently available from the tournament feed.
                        </p>
                        <a href="tournaments.php" class="btn btn-sm btn-outline-primary">
                            View PlayStation Tournaments
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card content-card h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div class="icon-box bg-warning-subtle text-warning">
                            <i class="bi bi-trophy"></i>
                        </div>
                        <span class="badge text-bg-warning">Events &amp; Competitions</span>
                    </div>

                    <?php if ($featuredPortalEvent): ?>
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                            <h3 class="h5 mb-0"><?= e((string) $featuredPortalEvent['title']) ?></h3>
                            <span class="badge text-bg-<?= e(homePortalEventStatusBadge($featuredPortalEvent)) ?>">
                                <?= e(homePortalEventStatusLabel($featuredPortalEvent)) ?>
                            </span>
                        </div>

                        <div class="text-secondary mb-3">
                            <?= e(ucfirst((string) $featuredPortalEvent['event_type'])) ?>

                            <?php if (!empty($featuredPortalEvent['event_code'])): ?>
                                · <?= e((string) $featuredPortalEvent['event_code']) ?>
                            <?php endif; ?>

                            <?php if (!empty($featuredPortalEvent['venue'])): ?>
                                · <?= e((string) $featuredPortalEvent['venue']) ?>
                            <?php endif; ?>
                        </div>

                        <div class="small mb-3">
                            <div class="text-secondary">Schedule</div>
                            <div class="fw-semibold">
                                <?= e(homePortalEventDateLabel(
                                    $featuredPortalEvent['start_at'] ?? null,
                                    $featuredPortalEvent['end_at'] ?? null
                                )) ?>
                            </div>
                        </div>

                        <?php if (!empty($featuredPortalEvent['description'])): ?>
                            <p class="small text-secondary mb-3">
                                <?= e(mb_strimwidth(
                                    strip_tags((string) $featuredPortalEvent['description']),
                                    0,
                                    180,
                                    '…'
                                )) ?>
                            </p>
                        <?php endif; ?>

                        <a
                            href="events-competitions.php?apply=<?= (int) $featuredPortalEvent['id'] ?>"
                            class="btn btn-sm btn-warning"
                        >
                            View / Join <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    <?php else: ?>
                        <h3 class="h5 mb-2">Events &amp; Competitions</h3>
                        <p class="text-secondary mb-3">
                            No Events &amp; Competitions item is currently available.
                        </p>
                        <a href="events-competitions.php" class="btn btn-sm btn-outline-warning">
                            View Events &amp; Competitions
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($tournamentFeed['error'] !== null): ?>
        <div class="small text-secondary mt-2">
            <i class="bi bi-info-circle me-1"></i><?= e((string) $tournamentFeed['error']) ?>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
