<?php
/**
 * Public four-video galleries using ONLY YouTube links entered by students or
 * teachers in their learning-module progress records. No hardcoded videos,
 * unrelated video feeds, API keys, or database migrations are required.
 *
 * A public page displays links supplied by portal members. Before making
 * student videos public, verify that publication has appropriate consent.
 */

if (!function_exists('portalVideoYoutubeId')) {
    function portalVideoYoutubeId(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $id = '';

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $id = explode('/', $path)[0] ?? '';
        } elseif (in_array($host, [
            'youtube.com', 'www.youtube.com', 'm.youtube.com',
            'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ], true)) {
            if ($path === 'watch') {
                $query = [];
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : '';
            } elseif (preg_match('~^(?:embed|shorts|live)/([a-zA-Z0-9_-]{11})(?:/|$)~', $path, $matches)) {
                $id = $matches[1];
            }
        }
        return preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id) ? $id : null;
    }
}

if (!function_exists('portalVideoSubmittedPool')) {
    /**
     * Randomly read links saved in student and teacher module progress.
     * For school pages, schoolId restricts records to that school only.
     * No student/teacher names or other personal details are selected.
     *
     * LIMIT is per member type, not a limit on the total video library.
     * Each database query draws a fresh random candidate sample.
     */
    function portalVideoSubmittedPool(PDO $pdo, ?int $schoolId = null): array
    {
        $videos = [];
        $sources = [
            ['student_module_progress', 'students', 'student_id', 'Student'],
            ['teacher_module_progress', 'teachers', 'teacher_id', 'Teacher'],
        ];

        foreach ($sources as [$progressTable, $memberTable, $memberKey, $role]) {
            $whereSchool = $schoolId === null ? '' : ' AND member.school_id = ?';
            $sql = "SELECT progress.youtube_url, lm.module_name, lm.game_title
                    FROM {$progressTable} progress
                    INNER JOIN {$memberTable} member ON member.id = progress.{$memberKey}
                    LEFT JOIN learning_modules lm ON lm.id = progress.module_id
                    WHERE progress.youtube_url IS NOT NULL
                      AND TRIM(progress.youtube_url) <> ''{$whereSchool}
                    ORDER BY RAND()
                    LIMIT 1000";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($schoolId === null ? [] : [$schoolId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $module = trim((string) ($row['module_name'] ?? ''));
                    $game = trim((string) ($row['game_title'] ?? ''));
                    $videos[] = [
                        'url' => trim((string) ($row['youtube_url'] ?? '')),
                        'title' => $module !== '' ? $module : ($game !== '' ? $game : 'Learning module video'),
                        'source' => $schoolId === null ? $role . ' submission' : 'This school · ' . $role . ' submission',
                    ];
                }
            } catch (PDOException $e) {
                // A missing optional table/column on older installs must not
                // break the home page or public school-profile pages.
                error_log('Video gallery submission query failed: ' . $e->getMessage());
            }
        }

        shuffle($videos);  // Mix teacher and student results, not grouped by role.
        return $videos;
    }
}

if (!function_exists('portalVideoSelectUpToFour')) {
    /**
     * Preserve preferred school links first, then add random portal links.
     * Reject invalid YouTube URLs and repeated video IDs. If fewer than four
     * valid member submissions exist, show only those available; NEVER use
     * external, invented, or duplicated links to fill remaining slots.
     */
    function portalVideoSelectUpToFour(array $preferred, array $fallback = []): array
    {
        $selected = [];
        $seen = [];
        foreach ([$preferred, $fallback] as $pool) {
            foreach ($pool as $video) {
                $id = portalVideoYoutubeId((string) ($video['url'] ?? ''));
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $selected[] = [
                    'video_id' => $id,
                    'url' => 'https://www.youtube.com/watch?v=' . $id,
                    'title' => trim((string) ($video['title'] ?? '')) ?: 'Learning module video',
                    'source' => trim((string) ($video['source'] ?? '')) ?: 'Student or teacher submission',
                ];
                if (count($selected) === 4) {
                    return $selected;
                }
            }
        }
        return $selected;
    }
}

if (!function_exists('portalVideosRandom')) {
    /** All portal video galleries are drawn afresh on each page view. */
    function portalVideosRandom(PDO $pdo): array
    {
        return portalVideoSelectUpToFour(portalVideoSubmittedPool($pdo));
    }
}

if (!function_exists('portalVideosForSchool')) {
    /** Own-school submissions take priority; other schools fill any gaps. */
    function portalVideosForSchool(PDO $pdo, int $schoolId): array
    {
        if ($schoolId <= 0) {
            return portalVideosRandom($pdo);
        }
        $schoolPool = portalVideoSubmittedPool($pdo, $schoolId);
        $allSchoolsPool = portalVideoSubmittedPool($pdo);
        $selected = portalVideoSelectUpToFour($schoolPool, $allSchoolsPool);

        // A global fallback item is not necessarily from this school. Label
        // accordingly so other schools' submissions are never misattributed.
        return $selected;
    }
}

if (!function_exists('portalRenderVideoGallery')) {
    function portalRenderVideoGallery(array $videos, string $heading, string $subtitle, string $sectionId): void
    {
        $sectionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sectionId) ?: 'featured-videos';
        ?>
        <section class="mb-4 portal-video-gallery" aria-labelledby="<?= e($sectionId) ?>-title">
            <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-2 mb-3">
                <div>
                    <div class="small text-uppercase fw-semibold text-primary mb-1">Videos</div>
                    <h2 class="h3 mb-1" id="<?= e($sectionId) ?>-title"><?= e($heading) ?></h2>
                    <?php if (trim($subtitle) !== ''): ?>
                        <p class="small text-secondary mb-0"><?= e($subtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row g-3">
                <?php foreach (array_slice($videos, 0, 4) as $video): ?>
                    <?php
                    $id = (string) ($video['video_id'] ?? '');
                    if (!preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id)) {
                        continue;
                    }
                    $watchUrl = 'https://www.youtube.com/watch?v=' . rawurlencode($id);
                    $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
                    $title = (string) ($video['title'] ?? 'Learning module video');
                    ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm h-100 overflow-hidden">
                            <div class="ratio ratio-16x9 bg-dark">
                                <iframe
                                    src="<?= e($embedUrl) ?>"
                                    title="<?= e($title) ?>"
                                    loading="lazy"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    allowfullscreen
                                ></iframe>
                            </div>
                            <div class="card-body d-flex flex-column p-3">
                                <div class="small text-secondary mb-1"><?= e((string) ($video['source'] ?? 'Video submission')) ?></div>
                                <h3 class="h6 fw-semibold mb-3"><?= e($title) ?></h3>
                                <a href="<?= e($watchUrl) ?>" target="_blank" rel="noopener noreferrer"
                                   class="btn btn-sm btn-outline-danger mt-auto align-self-start"
                                   aria-label="Watch <?= e($title) ?> on YouTube">
                                    <i class="bi bi-youtube me-1" aria-hidden="true"></i>Watch on YouTube
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php for ($emptySlot = count($videos); $emptySlot < 4; $emptySlot++): ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border shadow-sm h-100 overflow-hidden" aria-label="Awaiting video submission">
                            <div class="ratio ratio-16x9 bg-body-tertiary text-secondary">
                                <div class="d-flex flex-column align-items-center justify-content-center">
                                    <i class="bi bi-camera-video fs-2" aria-hidden="true"></i>
                                    <span class="small mt-2">Awaiting video submission</span>
                                </div>
                            </div>
                            <div class="card-body p-3">
                                <p class="small text-secondary mb-0">A teacher or student YouTube link will appear here when available.</p>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
            <?php if (count($videos) < 4): ?>
                <p class="small text-secondary mt-2 mb-0" role="status">
                    <?= count($videos) ?> of 4 slots contain submitted videos. No external or repeated videos are used.
                </p>
            <?php endif; ?>
        </section>
        <?php
    }
}
