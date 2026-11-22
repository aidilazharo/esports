<?php
/**
 * download.php
 *
 * Expected URL:
 *   https://esports.metadata.technology/download.php?code=CEA4088
 *
 * Uses the existing application config.php / PDO connection.
 * Records download_date in Asia/Kuala_Lumpur time.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/config.php';

$pdfPath = __DIR__ . '/download/metadata_jpn_esports.pdf';
$pdfDownloadName = 'metadata_jpn_esports.pdf';
$registerUrl = 'https://esports.metadata.technology/register_school';

function showSchoolNotRegistered(string $registerUrl, string $schoolCode = ''): void
{
    http_response_code(404);
    $url = $registerUrl;
    if ($schoolCode !== '') {
        $url .= '?code=' . rawurlencode($schoolCode);
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>School Not Registered</title>
        <style>
            body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f6f8;color:#222}
            .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}
            .card{width:100%;max-width:460px;background:#fff;border:1px solid #e4e6eb;border-radius:10px;padding:32px;text-align:center;box-sizing:border-box}
            h1{margin:0 0 12px;font-size:24px}p{margin:0 0 24px;line-height:1.5;color:#555}
            .btn{display:inline-block;padding:12px 24px;border-radius:6px;background:#222;color:#fff;text-decoration:none;font-weight:bold}
        </style>
    </head>
    <body>
        <div class="wrap"><div class="card">
            <h1>School not registered</h1>
            <p>Please register the school before downloading the document.</p>
            <a class="btn" href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>">Register</a>
        </div></div>
    </body>
    </html>
    <?php
    exit;
}

$schoolCode = isset($_GET['code']) ? strtoupper(trim((string) $_GET['code'])) : '';

if ($schoolCode === '' || preg_match('/^[A-Z0-9_-]{2,30}$/', $schoolCode) !== 1) {
    showSchoolNotRegistered($registerUrl, $schoolCode);
}

if (!is_file($pdfPath) || !is_readable($pdfPath)) {
    http_response_code(500);
    error_log('download.php: PDF missing or unreadable: ' . $pdfPath);
    exit('Download file is currently unavailable.');
}

try {
    // config.php in this project provides $pdo.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO connection $pdo was not created by config.php.');
    }

    // Keep this connection aligned to Kuala Lumpur time where the DB allows it.
    // Failure here is non-fatal because the timestamp is generated in PHP below.
    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (Throwable $ignored) {
        // Continue; PHP supplies the Kuala Lumpur timestamp explicitly.
    }

    $select = $pdo->prepare(
        'SELECT id, school_name
         FROM schools
         WHERE UPPER(TRIM(school_code)) = ?
         LIMIT 1'
    );
    $select->execute([$schoolCode]);
    $school = $select->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        showSchoolNotRegistered($registerUrl, $schoolCode);
    }

    $schoolId = (int) $school['id'];
    $downloadedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))
        ->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    $update = $pdo->prepare(
        'UPDATE schools
         SET download_file = COALESCE(download_file, 0) + 1,
             download_date = ?
         WHERE id = ?'
    );
    $update->execute([$downloadedAt, $schoolId]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Download tracking row was not updated.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('download.php error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to record download. Please check the server error log.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $pdfDownloadName . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . (string) filesize($pdfPath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: public');
header('Expires: 0');

readfile($pdfPath);
exit;
