<?php
declare(strict_types=1);

require_once 'config.php';

/**
 * English learning-module content keyed by module code.
 *
 * Keeps the public module catalogue consistent even when older database
 * records still contain earlier titles or descriptions.
 */
function englishLearningModuleContent(string $moduleCode): ?array
{
    $catalogue = [
        'M01' => [
            'week_title' => 'Esports Fundamentals',
            'day_name' => 'Monday',
            'module_name' => 'Introduction to Esports',
            'game_title' => 'EA Sports FC',
            'dreams_activity' => 'Explore scenarios',
            'objective' => 'Understand the concept of competitive gaming',
            'quiz_title' => 'Esports Fundamentals',
            'quiz_question' => 'Definition of esports and its rules',
        ],
        'M02' => [
            'week_title' => 'Esports Fundamentals',
            'day_name' => 'Tuesday',
            'module_name' => 'Controller Basics',
            'game_title' => "Astro's Playroom",
            'dreams_activity' => 'Control objects',
            'objective' => 'Become familiar with using a controller',
            'quiz_title' => 'Controller',
            'quiz_question' => 'Button functions',
        ],
        'M03' => [
            'week_title' => 'Esports Fundamentals',
            'day_name' => 'Wednesday',
            'module_name' => 'Camera and Movement',
            'game_title' => 'Gran Turismo 7',
            'dreams_activity' => 'Camera settings',
            'objective' => 'Control viewing angles and movement',
            'quiz_title' => 'Camera',
            'quiz_question' => 'Camera types',
        ],
        'M04' => [
            'week_title' => 'Esports Fundamentals',
            'day_name' => 'Thursday',
            'module_name' => 'Digital Etiquette',
            'game_title' => 'Fall Guys',
            'dreams_activity' => 'Simulate situations',
            'objective' => 'Understand appropriate behaviour in games',
            'quiz_title' => 'Etiquette',
            'quiz_question' => 'Good gaming manners',
        ],
        'M05' => [
            'week_title' => 'Esports Fundamentals',
            'day_name' => 'Friday',
            'module_name' => 'Game Fundamentals',
            'game_title' => 'Rocket League',
            'dreams_activity' => 'Modify scenarios',
            'objective' => 'Understand teamwork',
            'quiz_title' => 'Teamwork',
            'quiz_question' => 'Player roles',
        ],
        'M06' => [
            'week_title' => 'Strategy and Environment',
            'day_name' => 'Monday',
            'module_name' => 'Game Worlds',
            'game_title' => 'Minecraft',
            'dreams_activity' => 'Place objects',
            'objective' => 'Understand space and the game environment',
            'quiz_title' => 'Maps',
            'quiz_question' => 'Map elements',
        ],
        'M07' => [
            'week_title' => 'Strategy and Environment',
            'day_name' => 'Tuesday',
            'module_name' => 'Movement Skills',
            'game_title' => 'Fortnite',
            'dreams_activity' => 'Movement logic',
            'objective' => 'Control movement effectively',
            'quiz_title' => 'Movement',
            'quiz_question' => 'Movement techniques',
        ],
        'M08' => [
            'week_title' => 'Strategy and Environment',
            'day_name' => 'Wednesday',
            'module_name' => 'Map Awareness',
            'game_title' => 'Call of Duty: Warzone',
            'dreams_activity' => 'Build a map',
            'objective' => 'Improve awareness of the surrounding environment',
            'quiz_title' => 'Awareness',
            'quiz_question' => 'Importance of maps',
        ],
        'M09' => [
            'week_title' => 'Strategy and Environment',
            'day_name' => 'Thursday',
            'module_name' => 'Audio Cues',
            'game_title' => 'Apex Legends',
            'dreams_activity' => 'Sound settings',
            'objective' => 'Use audio to gain an advantage in gameplay',
            'quiz_title' => 'Audio',
            'quiz_question' => 'Functions of audio',
        ],
        'M10' => [
            'week_title' => 'Strategy and Environment',
            'day_name' => 'Friday',
            'module_name' => 'Teamwork',
            'game_title' => 'Overcooked! All You Can Eat',
            'dreams_activity' => 'Multiplayer logic',
            'objective' => 'Improve communication',
            'quiz_title' => 'Communication',
            'quiz_question' => 'Role of communication',
        ],
        'M11' => [
            'week_title' => 'Logic and Systems',
            'day_name' => 'Monday',
            'module_name' => 'Basic Logic',
            'game_title' => 'Portal 2',
            'dreams_activity' => 'Triggers',
            'objective' => 'Understand cause-and-effect relationships',
            'quiz_title' => 'Logic',
            'quiz_question' => 'What is logic?',
        ],
        'M12' => [
            'week_title' => 'Logic and Systems',
            'day_name' => 'Tuesday',
            'module_name' => 'Interaction',
            'game_title' => 'It Takes Two',
            'dreams_activity' => 'Interaction systems',
            'objective' => 'Understand interaction between players',
            'quiz_title' => 'Interaction',
            'quiz_question' => 'Types of interaction',
        ],
        'M13' => [
            'week_title' => 'Logic and Systems',
            'day_name' => 'Wednesday',
            'module_name' => 'Character Control',
            'game_title' => 'Spider-Man: Miles Morales',
            'dreams_activity' => 'Control settings',
            'objective' => 'Control character movement',
            'quiz_title' => 'Characters',
            'quiz_question' => 'Character controls',
        ],
        'M14' => [
            'week_title' => 'Logic and Systems',
            'day_name' => 'Thursday',
            'module_name' => 'Scoring Systems',
            'game_title' => 'NBA 2K',
            'dreams_activity' => 'Scoring system',
            'objective' => 'Understand scoring systems',
            'quiz_title' => 'Scoring',
            'quiz_question' => 'How scoring systems work',
        ],
        'M15' => [
            'week_title' => 'Logic and Systems',
            'day_name' => 'Friday',
            'module_name' => 'Game Structure',
            'game_title' => 'LittleBigPlanet 3',
            'dreams_activity' => 'Level design',
            'objective' => 'Understand game structure',
            'quiz_title' => 'Structure',
            'quiz_question' => 'Game structure',
        ],
        'M16' => [
            'week_title' => 'Projects and Tournaments',
            'day_name' => 'Monday',
            'module_name' => 'Game Ideas',
            'game_title' => 'Minecraft',
            'dreams_activity' => 'Game planning',
            'objective' => 'Generate game-design ideas',
            'quiz_title' => 'Design',
            'quiz_question' => 'Design elements',
        ],
        'M17' => [
            'week_title' => 'Projects and Tournaments',
            'day_name' => 'Tuesday',
            'module_name' => 'Build a Game',
            'game_title' => 'Fortnite',
            'dreams_activity' => 'Build a game',
            'objective' => 'Develop a game',
            'quiz_title' => 'Development',
            'quiz_question' => 'System functions',
        ],
        'M18' => [
            'week_title' => 'Projects and Tournaments',
            'day_name' => 'Wednesday',
            'module_name' => 'Testing and Improvement',
            'game_title' => 'Human: Fall Flat',
            'dreams_activity' => 'Detect errors',
            'objective' => 'Test and improve the game',
            'quiz_title' => 'Debugging',
            'quiz_question' => 'What is a bug?',
        ],
        'M19' => [
            'week_title' => 'Projects and Tournaments',
            'day_name' => 'Thursday',
            'module_name' => 'Tournament',
            'game_title' => 'EA Sports FC',
            'dreams_activity' => 'Play the game',
            'objective' => 'Experience a competitive environment',
            'quiz_title' => 'Tournament',
            'quiz_question' => 'Competition rules',
        ],
        'M20' => [
            'week_title' => 'Projects and Tournaments',
            'day_name' => 'Friday',
            'module_name' => 'Presentation Day',
            'game_title' => 'Rocket League',
            'dreams_activity' => 'Present results',
            'objective' => 'Present the completed project',
            'quiz_title' => 'Reflection',
            'quiz_question' => 'What was learned?',
        ],
    ];

    return $catalogue[strtoupper(trim($moduleCode))] ?? null;
}

function applyEnglishLearningModuleContent(array $module): array
{
    $english = englishLearningModuleContent(
        (string)($module['module_code'] ?? '')
    );

    return $english === null
        ? $module
        : array_replace($module, $english);
}

$stmt = $pdo->query(
    'SELECT *
     FROM learning_modules
     ORDER BY week_number, sequence_no, id'
);

$modules = array_map(
    'applyEnglishLearningModuleContent',
    $stmt->fetchAll()
);

$modulesByWeek = [];

foreach ($modules as $module) {
    $weekNumber = (int)($module['week_number'] ?? 0);

    if ($weekNumber < 1) {
        $weekNumber = 1;
    }

    $modulesByWeek[$weekNumber][] = $module;
}

ksort($modulesByWeek);

$pageTitle = 'Learning Modules';
require 'includes/header.php';
?>

<style>
.learning-page{
    max-width:1280px;
    margin:0 auto;
}
.learning-hero{
    background:
        linear-gradient(
            135deg,
            rgba(13,110,253,.09),
            rgba(25,135,84,.06)
        );
    border:1px solid rgba(13,110,253,.10);
}
.week-card{
    border:0;
    box-shadow:0 .125rem .5rem rgba(0,0,0,.06);
}
.week-heading{
    padding:1rem 1.15rem;
    border-bottom:1px solid var(--bs-border-color);
}
.module-list{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:.75rem;
    padding:1rem;
}
.module-card{
    border:1px solid var(--bs-border-color);
    border-radius:.75rem;
    background:#fff;
    padding:.95rem;
    min-width:0;
    height:100%;
}
.module-code{
    font-size:.75rem;
    font-weight:700;
}
.module-card h3{
    font-size:1rem;
    margin:.45rem 0 .35rem;
}
.module-meta{
    font-size:.8rem;
    color:var(--bs-secondary-color);
}
.module-objective{
    font-size:.86rem;
    line-height:1.4;
    margin:.7rem 0 0;
}
.module-detail{
    display:flex;
    gap:.4rem;
    align-items:flex-start;
    margin-top:.55rem;
    font-size:.8rem;
}
.module-detail i{
    color:var(--bs-primary);
    margin-top:.1rem;
}
@media(max-width:1199.98px){
    .module-list{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}
@media(max-width:767.98px){
    .module-list{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}
@media(max-width:575.98px){
    .module-list{
        grid-template-columns:1fr;
    }
}
</style>

<div class="learning-page">
    <section class="learning-hero rounded-4 p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <span class="badge text-bg-primary mb-2">
                    Learning Modules
                </span>

                <h1 class="h2 mb-2">
                    Weekly Learning Modules
                </h1>

                <p class="text-secondary mb-0">
                    20 structured modules organised across four weeks.
                </p>
            </div>

            <div class="text-lg-end">
                <div class="h3 mb-0">
                    <?= number_format(count($modules)) ?>
                </div>
                <div class="small text-secondary">
                    Modules
                </div>
            </div>
        </div>
    </section>

    <?php if (!$modules): ?>
        <div class="alert alert-light border text-center py-5">
            <i class="bi bi-journal-text fs-1 d-block mb-2"></i>
            No learning modules are currently available.
        </div>
    <?php else: ?>
        <?php foreach ($modulesByWeek as $weekNumber => $weekModules): ?>
            <?php
            $weekTitle = (string)(
                $weekModules[0]['week_title']
                ?? ('Week ' . $weekNumber)
            );
            ?>

            <section class="card week-card mb-3">
                <div class="week-heading d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                    <div>
                        <div class="small text-uppercase fw-semibold text-primary">
                            Week <?= (int)$weekNumber ?>
                        </div>

                        <h2 class="h5 mb-0">
                            <?= e($weekTitle) ?>
                        </h2>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= number_format(count($weekModules)) ?> modules
                    </span>
                </div>

                <div class="module-list">
                    <?php foreach ($weekModules as $module): ?>
                        <article class="module-card">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="badge text-bg-primary module-code">
                                    <?= e((string)$module['module_code']) ?>
                                </span>

                                <span class="module-meta">
                                    <?= e((string)$module['day_name']) ?>
                                </span>
                            </div>

                            <h3>
                                <?= e((string)$module['module_name']) ?>
                            </h3>

                            <div class="module-detail">
                                <i class="bi bi-controller"></i>
                                <span>
                                    <?= e((string)$module['game_title']) ?>
                                </span>
                            </div>

                            <div class="module-detail">
                                <i class="bi bi-stars"></i>
                                <span>
                                    <?= e((string)$module['dreams_activity']) ?>
                                </span>
                            </div>

                            <p class="module-objective">
                                <?= e((string)$module['objective']) ?>
                            </p>

                            <div class="module-detail">
                                <i class="bi bi-question-circle"></i>
                                <span>
                                    <strong>
                                        <?= e((string)$module['quiz_title']) ?>:
                                    </strong>
                                    <?= e((string)$module['quiz_question']) ?>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>
