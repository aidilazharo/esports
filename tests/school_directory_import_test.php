<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/school_directory_import.php';

function importAssert(bool $pass, string $message): void
{
    if (!$pass) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$path = tempnam(sys_get_temp_dir(), 'school-directory-csv-');
if ($path === false) {
    throw new RuntimeException('Temporary test file unavailable.');
}

try {
    $content = "school_code,school_name,school_type,state,district,contact_email\n"
        . "TEST001,SEKOLAH CONTOH A,SK,PAHANG,KUANTAN,private@example.test\n"
        . "TEST002,SEKOLAH CONTOH B,SMK,SELANGOR,KLANG,\n";
    file_put_contents($path, $content);
    $rows = schoolDirectoryParseCsv($path);
    importAssert(count($rows) === 2, 'Two distinct schools parsed.');
    importAssert($rows[0]['school_code'] === 'TEST001', 'School code retained.');
    importAssert(!array_key_exists('contact_email', $rows[0]), 'Contact email not imported.');
    importAssert(!array_key_exists('phone', $rows[0]), 'Contact phone not imported.');

    file_put_contents($path, $content
        . "TEST001,DUPLICATE,SK,PAHANG,KUANTAN,\n");
    $duplicateRejected = false;
    try {
        schoolDirectoryParseCsv($path);
    } catch (RuntimeException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'duplicate code');
    }
    importAssert($duplicateRejected, 'Duplicate official codes rejected.');

    file_put_contents($path, "school_name,state\nA,PAHANG\n");
    $invalidHeaderRejected = false;
    try {
        schoolDirectoryParseCsv($path);
    } catch (RuntimeException $e) {
        $invalidHeaderRejected = str_contains($e->getMessage(), 'school_code');
    }
    importAssert($invalidHeaderRejected, 'Missing required school_code rejected.');

    foreach ([
        'invite_email.php' => ['schoolEmailIsSuppressed', 'schoolEmailSuppressionWhere'],
        'cron/monthly_role_report.php' => ['schoolEmailSuppressionWhere'],
        'login.php' => ['schoolEmailIsSuppressed'],
    ] as $file => $checks) {
        $source = file_get_contents(dirname(__DIR__) . '/' . $file);
        importAssert(is_string($source), 'Email source readable: ' . $file);
        foreach ($checks as $check) {
            importAssert(str_contains($source, $check), 'School-email suppression present in ' . $file);
        }
    }
    $importSource = file_get_contents(dirname(__DIR__) . '/scripts/import-school-directory.php');
    importAssert(
        is_string($importSource) && str_contains($importSource, "'suppress_school_email' => 1"),
        'New directory schools are always email-suppressed.'
    );
    echo "School directory CSV parsing and email suppression checks passed.\n";
} finally {
    @unlink($path);
}
