<?php
/**
 * unsubscribe.php
 * Clean URL example:
 * /unsubscribe?code=CEA4088&token=...
 */

declare(strict_types=1);

function loadInviteEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadInviteEnv(__DIR__ . '/.env');
require_once __DIR__ . '/config.php';

function renderPage(string $title, string $message, string $type = 'success'): never
{
    $pageTitle = $title;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center py-5">
        <div class="col-lg-7 col-xl-6">
            <div class="card content-card shadow-sm">
                <div class="card-body p-4 p-md-5 text-center">
                    <h1 class="h3 mb-3"><?= e($title) ?></h1>
                    <div class="alert alert-<?= e($type) ?> mb-0"><?= e($message) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$schoolCode = strtoupper(trim((string) ($_GET['code'] ?? '')));
$token = trim((string) ($_GET['token'] ?? ''));
$isTest = strtolower(trim((string) ($_GET['mode'] ?? ''))) === 'test';
$secret = trim((string) (getenv('INVITE_UNSUBSCRIBE_SECRET') ?: ''));

if ($secret === '') {
    renderPage('Ralat Sistem', 'Tetapan berhenti menerima e-mel belum dikonfigurasi.', 'danger');
}

if ($schoolCode === '' || !preg_match('/^[A-Z0-9_-]{2,30}$/', $schoolCode)) {
    renderPage('Pautan Tidak Sah', 'Kod sekolah tidak sah.', 'danger');
}

$expected = hash_hmac('sha256', $schoolCode, $secret);
if ($token === '' || !hash_equals($expected, $token)) {
    renderPage('Pautan Tidak Sah', 'Pautan berhenti menerima e-mel tidak sah atau telah diubah.', 'danger');
}

if ($isTest) {
    renderPage('Mod Ujian', 'Pautan berhenti menerima e-mel berfungsi. Tiada rekod sekolah diubah kerana ini ialah mod ujian.', 'info');
}

$stmt = $pdo->prepare('SELECT id, school_name FROM schools WHERE school_code = ? LIMIT 1');
$stmt->execute([$schoolCode]);
$school = $stmt->fetch();

if (!$school) {
    renderPage('Sekolah Tidak Ditemui', 'Rekod sekolah tidak dijumpai.', 'warning');
}

$update = $pdo->prepare('UPDATE schools SET unsubscribed = 1 WHERE id = ?');
$update->execute([(int) $school['id']]);

renderPage(
    'Berhenti Menerima E-mel',
    'Permintaan telah direkodkan. ' . (string) $school['school_name'] . ' tidak akan menerima e-mel jemputan program ini lagi.'
);
