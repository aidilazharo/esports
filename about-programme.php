<?php
require_once 'config.php';
require_once __DIR__ . '/includes/video_gallery.php';



function aboutProgrammeSafeFaqAnswer(?string $html): string
{
    $html = trim((string) $html);
    return strip_tags(
        $html,
        '<p><br><strong><b><em><i><ul><ol><li><div><span><table><thead><tbody><tfoot><tr><th><td><hr><small>'
    );
}


$schoolStmt = $pdo->query(
    "SELECT
        s.id,
        s.school_code,
        s.school_name,
        s.school_type,
        s.state,
        s.district,
        s.school_logo,
        s.school_image_1,
        COUNT(DISTINCT t.id) AS teacher_count,
        COUNT(DISTINCT st.id) AS student_count,
        (
            COUNT(DISTINCT t.id)
            + COUNT(DISTINCT st.id)
        ) AS total_members
     FROM schools s
     LEFT JOIN teachers t ON t.school_id = s.id
     LEFT JOIN students st ON st.school_id = s.id
     GROUP BY s.id
     HAVING teacher_count >= 1
     ORDER BY total_members DESC, s.school_name ASC"
);
$programmeSchools = $schoolStmt->fetchAll();


/*
 * About Programme FAQ uses the current programme rules displayed
 * on this page so outdated FAQ records do not contradict the latest
 * participation, fee, equipment and certificate information.
 */
$programmeFaqs = [
    [
        'id' => 1,
        'sort_order' => 1,
        'question_en' => 'What is the programme?',
        'answer_en' => '<p>The programme is a school-based STEM, TVET, AI, simulation, digital innovation and esports talent development initiative combining structured learning, activities, attendance, certificates and competitions.</p>',
    ],
    [
        'id' => 2,
        'sort_order' => 2,
        'question_en' => 'How much is the student participation fee?',
        'answer_en' => '<p>The student participation fee is <strong>RM100 one time only</strong>.</p>',
    ],
    [
        'id' => 3,
        'sort_order' => 3,
        'question_en' => 'When will the school receive equipment?',
        'answer_en' => '<p>After the school reaches <strong>10 registered participants</strong>, programme equipment and accessories will be sent to the school.</p>',
    ],
    [
        'id' => 4,
        'sort_order' => 4,
        'question_en' => 'When will the school receive the PlayStation 5 and account activation?',
        'answer_en' => '<p>The <strong>PlayStation 5 and account activation details</strong> will be sent only after the school reaches <strong>30 active participants</strong>.</p>',
    ],
    [
        'id' => 5,
        'sort_order' => 5,
        'question_en' => 'How do students access their certificates?',
        'answer_en' => '<p>Eligible students and teachers can sign in through the programme login page and access available certificates from their portal.</p>',
    ],
    [
        'id' => 6,
        'sort_order' => 6,
        'question_en' => 'What learning and activities are included?',
        'answer_en' => '<p>The programme includes structured learning in digital literacy, AI, coding, STEM, TVET, simulation, innovation and esports, together with attendance tracking, events and competitions.</p>',
    ],
    [
        'id' => 7,
        'sort_order' => 7,
        'question_en' => 'Can students join tournaments?',
        'answer_en' => '<p>Yes. Eligible students can participate in programme competitions and tournaments published through the portal.</p>',
    ],
    [
        'id' => 8,
        'sort_order' => 8,
        'question_en' => 'Who coordinates the programme at school?',
        'answer_en' => '<p>Each participating school registers a School Team Teacher who coordinates programme participation, student learning and related activities.</p>',
    ],
];


$pageTitle = 'About Programme';
require 'includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css">

<style>
    .programme-hero {
        background:
            linear-gradient(135deg, rgba(13, 110, 253, .10), rgba(25, 135, 84, .08));
        border: 1px solid rgba(13, 110, 253, .10);
    }

    .programme-icon {
        width: 52px;
        height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: var(--bs-primary-bg-subtle);
        color: var(--bs-primary);
        font-size: 1.35rem;
        flex: 0 0 auto;
    }

    .focus-card,
    .programme-section-card {
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .focus-card:hover,
    .programme-section-card:hover {
        transform: translateY(-2px);
    }

    .programme-step {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--bs-primary);
        color: #fff;
        font-weight: 700;
        flex: 0 0 auto;
    }

    .programme-table-thumb {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid #dee2e6;
        background: #fff;
        flex: 0 0 48px;
    }

    .programme-school-photo {
        width: 74px;
        height: 50px;
        border-radius: 7px;
        object-fit: cover;
        border: 1px solid #dee2e6;
        background: #fff;
    }

    .programme-action-btn {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }

    .programme-table-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--bs-primary-bg-subtle);
        color: var(--bs-primary);
        font-size: 1.15rem;
        flex: 0 0 48px;
    }

    .game-poster-thumb {
        width: 110px;
        height: 145px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid #dee2e6;
        background: #fff;
        box-shadow: 0 .125rem .375rem rgba(0,0,0,.08);
    }

    @media (max-width: 575.98px) {
        .game-poster-thumb {
            width: 95px;
            height: 126px;
        }
    }
</style>

<section class="programme-hero rounded-4 p-4 p-lg-5 mb-4">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <span class="badge text-bg-primary mb-3">About the Programme</span>
            <h1 class="display-6 fw-bold mb-3">
                STEM, TVET, AI, Simulation, Digital Innovation &amp; Esports Talent Development Programme
            </h1>
            <p class="lead text-secondary mb-4">
                A school-based talent development programme designed to give students practical exposure
                to digital technology, artificial intelligence, innovation, simulation, STEM, TVET and
                electronic sports through structured learning, activities and competitions.
            </p>

        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Programme at a Glance</h2>

                    <div class="d-flex gap-3 mb-3">
                        <span class="programme-icon"><i class="bi bi-mortarboard"></i></span>
                        <div>
                            <div class="fw-semibold">School-based learning</div>
                            <div class="small text-secondary">Structured activities that can support student development outside normal classroom learning.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-3 mb-3">
                        <span class="programme-icon"><i class="bi bi-cpu"></i></span>
                        <div>
                            <div class="fw-semibold">Technology exposure</div>
                            <div class="small text-secondary">STEM, TVET, AI, simulation, digital innovation and esports.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <span class="programme-icon"><i class="bi bi-trophy"></i></span>
                        <div>
                            <div class="fw-semibold">Learning + competition</div>
                            <div class="small text-secondary">Weekly modules, attendance tracking, events and competitions.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
portalRenderVideoGallery(
    portalVideosRandom($pdo),
    'Student & Teacher Programme Videos',
    'Random videos from teacher and student learning-module submissions across the programme.',
    'programme-videos'
);
?>

<section class="mb-5">
    <div class="mb-4">
        <div class="small text-uppercase fw-semibold text-primary mb-1">Programme Objectives</div>
        <h2 class="h3 mb-2">What the programme aims to develop</h2>
        <p class="text-secondary mb-0">
            The programme focuses on practical exposure, student participation and long-term digital talent development.
        </p>
    </div>

    <div class="row g-4">
        <?php
        $objectives = [
            ['bi-lightbulb', 'Digital Innovation', 'Encourage students to explore ideas, problem-solving, prototyping and innovation using digital technology.'],
            ['bi-robot', 'Artificial Intelligence', 'Introduce students to AI concepts, responsible use of AI tools and practical AI-assisted learning activities.'],
            ['bi-tools', 'STEM & TVET Skills', 'Build interest in applied science, technology, engineering and technical-vocational pathways through hands-on activities.'],
            ['bi-controller', 'Esports Development', 'Use structured esports activities to develop teamwork, communication, discipline, strategy and digital participation.'],
            ['bi-boxes', 'Simulation & Applied Learning', 'Expose students to simulation-based learning and technology-assisted practice in a safe and structured environment.'],
            ['bi-people', 'Talent & Leadership', 'Create opportunities for students, teachers and schools to participate in activities, events and inter-school collaboration.'],
        ];
        ?>

        <?php foreach ($objectives as [$icon, $title, $description]): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card focus-card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <span class="programme-icon mb-3"><i class="bi <?= e($icon) ?>"></i></span>
                        <h3 class="h5"><?= e($title) ?></h3>
                        <p class="text-secondary mb-0"><?= e($description) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-5">
    <div class="mb-4">
        <div class="small text-uppercase fw-semibold text-primary mb-1">Games &amp; Competitive Titles</div>
        <h2 class="h3 mb-2">Games used for esports activities and competitions</h2>
        <p class="text-secondary mb-0">
            The programme will focus on six popular competitive titles that have existing PlayStation tournament pathways.
        </p>
    </div>

    <?php
    $programmeGames = [
        [
            'title' => 'EA SPORTS FC 26',
            'poster' => 'assets/images/games/fc26.jpg',
        ],
        [
            'title' => 'TEKKEN 8',
            'poster' => 'assets/images/games/tekken-8.jpg',
        ],
        [
            'title' => 'NBA 2K26',
            'poster' => 'assets/images/games/nba2k26.jpg',
        ],
        [
            'title' => 'Mortal Kombat 1',
            'poster' => 'assets/images/games/mortal-kombat-1.jpg',
        ],
        [
            'title' => 'Gran Turismo 7',
            'poster' => 'assets/images/games/gran-turismo-7.jpg',
        ],
        [
            'title' => 'Fortnite',
            'poster' => 'assets/images/games/fortnite.jpg',
        ],
    ];
    ?>

    <div class="row g-4">
        <?php foreach ($programmeGames as $game): ?>
            <?php
            $posterPath = $game['poster'];
            $posterFile = __DIR__ . '/' . ltrim($posterPath, '/');

            if (is_file($posterFile)) {
                $posterSrc = $posterPath;
            } else {
                $posterTitle = rawurlencode($game['title']);
                $posterSrc = "https://placehold.co/360x480/png?text={$posterTitle}";
            }
            ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-3 text-center">
                        <img
                            src="<?= e($posterSrc) ?>"
                            alt="<?= e($game['title']) ?> poster"
                            class="game-poster-thumb mb-2"
                            loading="lazy"
                        >
                        <h3 class="small fw-semibold mb-0"><?= e($game['title']) ?></h3>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</section>

<section class="mb-5">
    <div class="mb-4">
        <div class="small text-uppercase fw-semibold text-primary mb-1">Getting Started</div>
        <h2 class="h3 mb-2">Programme Journey</h2>
    </div>

    <div class="row g-3">
        <?php
        $programmeStartSteps = [
            [
                'icon' => 'bi-building-add',
                'title' => 'Register School',
                'description' => 'Create the participating school profile in the programme portal.',
                'buttons' => [
                    ['register_school', 'Register School', 'btn-primary'],
                ],
            ],
            [
                'icon' => 'bi-person-badge',
                'title' => 'Register Teacher',
                'description' => 'Register the school team teacher who will coordinate the programme and learning modules.',
                'buttons' => [
                    ['register_teacher', 'Register Teacher', 'btn-outline-primary'],
                ],
            ],
            [
                'icon' => 'bi-people',
                'title' => 'Register 10 Students & Receive Equipment',
                'description' => 'After 10 participants are registered, programme equipment and accessories will be sent to the school. The PlayStation 5 and account activation details will be sent only after the school reaches 30 active participants.',
                'buttons' => [
                    ['register_student', 'Register Student', 'btn-outline-primary'],
                ],
            ],
            [
                'icon' => 'bi-journal-check',
                'title' => 'Modules Learning Start',
                'description' => 'The school team teacher can begin delivering the structured programme learning modules.',
                'buttons' => [
                    ['learning_modules', 'View Learning Modules', 'btn-outline-info'],
                ],
            ],
            [
                'icon' => 'bi-award',
                'title' => 'Receive Certificate',
                'description' => 'Eligible students and teachers can check and access programme certificates.',
                'buttons' => [
                    ['login.php', 'Check Certificate', 'btn-outline-warning'],
                ],
            ],
            [
                'icon' => 'bi-trophy',
                'title' => 'Join Local & International Tournament',
                'description' => 'Participate in eligible local and international programme competitions and tournaments.',
                'buttons' => [
                    ['events-competitions', 'View Competitions', 'btn-outline-danger'],
                ],
            ],
        ];
        ?>

        <?php foreach ($programmeStartSteps as $index => $step): ?>
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="programme-icon">
                                <i class="bi <?= e($step['icon']) ?>"></i>
                            </span>
                            <span class="badge text-bg-primary rounded-pill">
                                Step <?= $index + 1 ?>
                            </span>
                        </div>

                        <h3 class="h5"><?= e($step['title']) ?></h3>
                        <p class="small text-secondary mb-3"><?= e($step['description']) ?></p>

                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <?php foreach ($step['buttons'] as [$url, $buttonLabel, $buttonClass]): ?>
                                <a
                                    href="<?= e($url) ?>"
                                    class="btn btn-sm <?= e($buttonClass) ?> flex-grow-1"
                                >
                                    <?= e($buttonLabel) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>


<section class="mb-5" id="programme-schools">
    <div class="mb-3">
        <h2 class="h3 mb-1">Participating Schools</h2>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="programmeSchoolsTable" class="table table-hover align-middle w-100 mb-0">
                    <thead>
                        <tr>
                            <th>School</th>
                            <th class="text-center" title="School Image"><i class="bi bi-camera"></i></th>
                            <th>State / District</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Teachers</th>
                            <th class="text-center">Students</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programmeSchools as $item): ?>
                            <?php
                            $teacherCount = (int) ($item['teacher_count'] ?? 0);
                            $studentCount = (int) ($item['student_count'] ?? 0);
                            $totalMembers = $teacherCount + $studentCount;

                            $schoolLogo = trim((string) ($item['school_logo'] ?? ''));
                            $schoolLogoUrl = $schoolLogo !== '' ? '/' . ltrim($schoolLogo, '/') : '';

                            $schoolImage = trim((string) ($item['school_image_1'] ?? ''));
                            $schoolImageUrl = $schoolImage !== '' ? '/' . ltrim($schoolImage, '/') : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if ($schoolLogoUrl !== ''): ?>
                                            <img
                                                src="<?= e($schoolLogoUrl) ?>"
                                                alt="<?= e($item['school_name']) ?> logo"
                                                class="programme-table-thumb"
                                                width="48"
                                                height="48"
                                                loading="lazy"
                                                style="object-fit:contain;padding:3px;"
                                            >
                                        <?php else: ?>
                                            <span class="programme-table-icon" title="School">
                                                <i class="bi bi-building"></i>
                                            </span>
                                        <?php endif; ?>

                                        <div>
                                            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                                <span class="badge text-bg-primary"><?= e($item['school_code']) ?></span>
                                                <span class="badge text-bg-success">Active</span>
                                            </div>
                                            <div class="fw-semibold"><?= e($item['school_name']) ?></div>
                                            <small class="text-secondary"><?= e($item['school_type']) ?></small>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-center">
                                    <?php if ($schoolImageUrl !== ''): ?>
                                        <button
                                            type="button"
                                            class="btn p-0 border-0 bg-transparent"
                                            data-bs-toggle="modal"
                                            data-bs-target="#programmeSchoolImageModal"
                                            data-image-url="<?= e($schoolImageUrl) ?>"
                                            data-school-name="<?= e($item['school_name']) ?>"
                                            title="View School Image"
                                            aria-label="View School Image"
                                        >
                                            <img
                                                src="<?= e($schoolImageUrl) ?>"
                                                alt="<?= e($item['school_name']) ?>"
                                                class="programme-school-photo"
                                                width="74"
                                                height="50"
                                                loading="lazy"
                                            >
                                        </button>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e($item['state']) ?><br>
                                    <small class="text-secondary"><?= e($item['district']) ?></small>
                                </td>
                                <td class="text-center fw-bold" data-order="<?= $totalMembers ?>"><?= $totalMembers ?></td>
                                <td class="text-center"><?= $teacherCount ?></td>
                                <td class="text-center" data-order="<?= $studentCount ?>"><?= $studentCount ?></td>
                                <td class="text-end text-nowrap">
                                    <a
                                        class="btn btn-sm btn-outline-primary programme-action-btn"
                                        href="school_profile.php?school_id=<?= (int) $item['id'] ?>"
                                        title="School Profile"
                                        aria-label="School Profile"
                                    >
                                        <i class="bi bi-person-vcard"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>


<div class="modal fade" id="programmeSchoolImageModal" tabindex="-1" aria-labelledby="programmeSchoolImageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="programmeSchoolImageModalLabel">School Image</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-2 p-md-3">
                <img
                    src=""
                    alt=""
                    id="programmeSchoolImageModalImage"
                    class="img-fluid rounded"
                    style="max-height:75vh;object-fit:contain;"
                >
            </div>
        </div>
    </div>
</div>


<section class="mb-5">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card programme-section-card h-100 border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="small text-uppercase fw-semibold text-primary mb-1">How It Works</div>
                    <h2 class="h3 mb-4">Student participation journey</h2>

                    <div class="d-flex gap-3 mb-4">
                        <span class="programme-step">1</span>
                        <div>
                            <h3 class="h6 mb-1">School Registration</h3>
                            <p class="text-secondary mb-0">
                                The school registers in the portal and appoints a school team teacher as the main coordinator.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex gap-3 mb-4">
                        <span class="programme-step">2</span>
                        <div>
                            <h3 class="h6 mb-1">Student Membership</h3>
                            <p class="text-secondary mb-0">
                                Students register under their school and pay a one-time RM100 participation fee.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex gap-3 mb-4">
                        <span class="programme-step">3</span>
                        <div>
                            <h3 class="h6 mb-1">Learning &amp; Attendance</h3>
                            <p class="text-secondary mb-0">
                                Active students can participate in weekly learning modules, school activities and attendance-based programme sessions.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <span class="programme-step">4</span>
                        <div>
                            <h3 class="h6 mb-1">Events &amp; Competitions</h3>
                            <p class="text-secondary mb-0">
                                Students and schools can join eligible events, competitions and tournaments through the programme portal.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card programme-section-card h-100 border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="small text-uppercase fw-semibold text-primary mb-1">Membership</div>
                    <h2 class="h3 mb-3">RM100</h2>
                    <p class="text-secondary">
                        The student participation fee is RM100, payable one time only.
                    </p>

                    <ul class="list-group list-group-flush mb-4">
                        <li class="list-group-item px-0 d-flex gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>RM100 one-time participation fee</span>
                        </li>
                        <li class="list-group-item px-0 d-flex gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>Access to programme learning modules</span>
                        </li>
                        <li class="list-group-item px-0 d-flex gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>Attendance and participation tracking</span>
                        </li>
                        <li class="list-group-item px-0 d-flex gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>Eligibility to join selected events and competitions</span>
                        </li>
                        <li class="list-group-item px-0 d-flex gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>Student STEM &amp; Esports certificate access</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>


<section class="mb-5">
    <div class="mb-4">
        <div class="small text-uppercase fw-semibold text-primary mb-1">Learning Modules</div>
        <h2 class="h3 mb-2">Structured weekly digital learning</h2>
        <p class="text-secondary mb-0">
            Active students can follow structured learning modules that combine digital knowledge,
            practical activities, responsible technology use and competition-related skills.
        </p>
    </div>

    <div class="row g-4">
        <?php
        $learningModules = [
            ['bi-stars', 'Digital Literacy & Responsible Technology', 'Digital citizenship, online safety, responsible technology use and basic digital productivity.'],
            ['bi-robot', 'Artificial Intelligence Fundamentals', 'Introduction to AI, prompting, AI-assisted learning, responsible AI use and practical school-level applications.'],
            ['bi-code-slash', 'Coding & Computational Thinking', 'Problem-solving, logic, basic coding concepts and computational thinking through guided activities.'],
            ['bi-cpu', 'STEM, TVET & Simulation', 'Applied STEM, technical concepts, simulation-based learning and exposure to TVET pathways.'],
            ['bi-lightbulb', 'Innovation & Project Development', 'Idea generation, problem definition, prototyping, teamwork and presentation of student projects.'],
            ['bi-controller', 'Esports Skills & Performance', 'Game strategy, teamwork, sportsmanship, tournament preparation, gameplay review and performance improvement.'],
        ];
        ?>

        <?php foreach ($learningModules as [$icon, $title, $description]): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <span class="programme-icon mb-3"><i class="bi <?= e($icon) ?>"></i></span>
                        <h3 class="h5"><?= e($title) ?></h3>
                        <p class="text-secondary mb-0"><?= e($description) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4">
        <a href="/learning_modules.php" class="btn btn-outline-primary">
            <i class="bi bi-journal-check me-2"></i>View Learning Modules
        </a>
    </div>
</section>

<section class="mb-5">
    <div class="mb-4">
        <div class="small text-uppercase fw-semibold text-primary mb-1">School Development Support</div>
        <h2 class="h3 mb-2">Equipment eligibility</h2>
        <p class="text-secondary mb-0">
            Participating schools receive programme equipment in stages as student participation grows,
            with PlayStation 5 and account activation released after the active-participant requirement is achieved.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100 border-success-subtle shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="programme-icon"><i class="bi bi-controller"></i></span>
                        <div>
                            <div class="small text-uppercase fw-semibold text-secondary">Equipment Eligibility</div>
                            <h3 class="h5 mb-0">10 registered participants</h3>
                        </div>
                    </div>

                    <p class="text-secondary">
                        Programme equipment is released in stages based on student participation recorded in the portal.
                    </p>

                    <div class="alert alert-success mb-3">
                        <strong>After 10 participants:</strong><br>
                        Programme equipment and accessories will be sent to the school.
                    </div>

                    <div class="alert alert-primary mb-3">
                        <strong>After 30 active participants:</strong><br>
                        The PlayStation 5 and account activation details will be sent to the school.
                    </div>

                    <div class="alert alert-info mb-0">
                        <strong>High-end workstation:</strong><br>
                        Schools receive 1 workstation for every 100 active students
                        for STEM, AI, simulation and digital innovation activities.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 border-primary-subtle shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="programme-icon"><i class="bi bi-diagram-3"></i></span>
                        <div>
                            <div class="small text-uppercase fw-semibold text-secondary">School Reference Eligibility</div>
                            <h3 class="h5 mb-0">Grow the programme network</h3>
                        </div>
                    </div>

                    <p class="text-secondary mb-2">
                        Reference eligibility requires:
                    </p>

                    <ul class="mb-3">
                        <li>The referring school has at least 5 registered students.</li>
                        <li>At least 2 schools use it as their referrer.</li>
                        <li>At least 2 referred schools each have 30 active students.</li>
                    </ul>

                    <div class="alert alert-primary mb-0">
                        <strong>1 set PlayStation 5</strong><br>
                        1 console, 2 controllers, 1 PS Camera and 43-inch digital TV.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<section class="mb-5">
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-7">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="small text-uppercase fw-semibold text-primary mb-1">Student &amp; Teacher Recognition</div>
                    <h2 class="h3 mb-3">STEM &amp; Esports Certificates</h2>
                    <p class="text-secondary">
                        Students and school team teachers can receive programme certificates recognising
                        participation, learning, coordination and development across STEM, digital learning
                        and esports activities.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="fw-semibold">Student STEM &amp; Esports Certificate</div>
                                <div class="small text-secondary">
                                    Recognition of learning module participation, STEM and digital activities,
                                    esports participation, teamwork and sportsmanship.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="fw-semibold">Teacher STEM &amp; Esports Certificate</div>
                                <div class="small text-secondary">
                                    Recognition of programme coordination, student supervision, learning support,
                                    school activities and events participation.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100 border-primary-subtle shadow-sm">
                <div class="card-body p-4 p-lg-5 d-flex flex-column">
                    <span class="programme-icon mb-3"><i class="bi bi-award"></i></span>
                    <h3 class="h4">Digital Certificate Access</h3>
                    <p class="text-secondary">
                        Eligible students and teachers can sign in to the portal to access available programme certificates.
                    </p>
                    <div class="mt-auto">
                        <a href="login.php" class="btn btn-outline-primary">
                            <i class="bi bi-award me-2"></i>View Certificates
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="mb-5" id="programme-faq">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3">
        <div>
            <h2 class="h3 mb-1">Frequently Asked Questions</h2>
            <div class="small text-secondary">Updated with the latest programme participation, fee, equipment and certificate information.</div>
        </div>
        <a href="#programme-faq" class="btn btn-sm btn-outline-primary">Browse FAQs</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="programmeFaqTable" class="table table-hover align-middle w-100 mb-0">
                    <thead>
                        <tr>
                            <th style="width:34%">Question</th>
                            <th>Answer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programmeFaqs as $faq): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($faq['question_en']) ?></td>
                                <td><?= aboutProgrammeSafeFaqAnswer((string) $faq['answer_en']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>


<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!(window.jQuery && jQuery.fn && jQuery.fn.DataTable)) {
        console.error('DataTables failed to load.');
        return;
    }

    const tables = [
        {
            selector: '#programmeSchoolsTable',
            search: 'Search schools:',
            order: [[3, 'desc'], [0, 'asc']],
            columnDefs: [
                { orderable: false, searchable: false, targets: [1, 6] }
            ]
        },
        {
            selector: '#programmeFaqTable',
            search: 'Search FAQ:',
            order: [[0, 'asc']],
            columnDefs: []
        }
    ];

    const schoolImageModal = document.getElementById('programmeSchoolImageModal');
    const schoolImageModalImage = document.getElementById('programmeSchoolImageModalImage');
    const schoolImageModalLabel = document.getElementById('programmeSchoolImageModalLabel');

    if (schoolImageModal && schoolImageModalImage && schoolImageModalLabel) {
        schoolImageModal.addEventListener('show.bs.modal', function (event) {
            const trigger = event.relatedTarget;
            if (!trigger) return;

            const imageUrl = trigger.getAttribute('data-image-url') || '';
            const schoolName = trigger.getAttribute('data-school-name') || 'School';

            schoolImageModalImage.src = imageUrl;
            schoolImageModalImage.alt = schoolName + ' School Image';
            schoolImageModalLabel.textContent = schoolName + ' — School Image';
        });

        schoolImageModal.addEventListener('hidden.bs.modal', function () {
            schoolImageModalImage.src = '';
            schoolImageModalImage.alt = '';
            schoolImageModalLabel.textContent = 'School Image';
        });
    }

    tables.forEach(function (item) {
        if (!document.querySelector(item.selector)) return;

        jQuery(item.selector).DataTable({
            pageLength: 5,
            lengthChange: false,
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            autoWidth: false,
            order: item.order,
            columnDefs: item.columnDefs,
            language: {
                search: item.search,
                info: 'Showing _START_–_END_ of _TOTAL_ records',
                infoEmpty: 'Showing 0 records',
                zeroRecords: 'No matching records found',
                paginate: { previous: 'Previous', next: 'Next' }
            }
        });
    });
});
</script>

<?php require 'includes/footer.php'; ?>
