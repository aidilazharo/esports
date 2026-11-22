<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/directory_snapshot.php';

function directoryTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'esports-directory-test-' . bin2hex(random_bytes(8));
putenv('ESPORTS_DIRECTORY_SNAPSHOT_DIR=' . $dir);
$testSchoolRows = [
    ['id' => 1, 'code' => 'ABC001', 'name' => 'SCHOOL A', 'type' => 'SK',
     'state' => 'PAHANG', 'district' => 'KUANTAN', 'has_coach' => 0],
    ['id' => 2, 'code' => 'DEF002', 'name' => 'SCHOOL B', 'type' => 'SMK',
     'state' => 'SELANGOR', 'district' => 'KLANG', 'has_coach' => 1],
];

try {
    $count = esportsDirectorySnapshotWrite('schools', $testSchoolRows);
    directoryTestAssert($count === 2, 'Two schools exported.');
    $snapshot = esportsDirectorySnapshotRead('schools');
    directoryTestAssert(is_array($snapshot), 'JSON snapshot readable.');
    directoryTestAssert(count($snapshot['rows']) === 2, 'All rows round-trip.');
    directoryTestAssert(
        (fileperms(esportsDirectorySnapshotFile('schools')) & 0077) === 0,
        'Snapshot is private (not group/world readable).'
    );

    $filtered = esportsDirectorySnapshotSearch(
        $snapshot['rows'], 'schools', null, 0, 0,
        'school', 'pahang', 0, 1, 25
    );
    directoryTestAssert($filtered['total'] === 1, 'Guest can search public school state.');

    $students = [
        ['id'=>3, 'school_id'=>1, 'full_name'=>'STUDENT ONE'],
        ['id'=>4, 'school_id'=>2, 'full_name'=>'STUDENT TWO'],
    ];
    esportsDirectorySnapshotWrite('students', $students);
    $private = esportsDirectorySnapshotRead('students');
    directoryTestAssert(is_array($private), 'Private student snapshot readable.');
    directoryTestAssert(
        esportsDirectorySnapshotSearch($private['rows'], 'students', null, 0, 0, '', '', 0, 1, 25)['total'] === 0,
        'Guests cannot access students.'
    );
    $ownSchool = esportsDirectorySnapshotSearch(
        $private['rows'], 'students', 'teacher', 1, 0, '', '', 2, 1, 25
    );
    directoryTestAssert($ownSchool['total'] === 0, 'School ID query cannot expand teacher scope.');
    directoryTestAssert(
        esportsDirectorySnapshotSearch($private['rows'], 'students', 'school', 1, 0, '', '', 0, 1, 25)['total'] === 1,
        'School sees only its student.'
    );

    $parents = [
        ['id' => 9, 'full_name' => 'PARENT A', 'school_ids' => [1]],
        ['id' => 10, 'full_name' => 'PARENT B', 'school_ids' => [2]],
    ];
    $scope = esportsDirectorySnapshotSearch(
        $parents, 'parents', 'school', 1, 0, '', '', 0, 1, 25
    );
    directoryTestAssert($scope['total'] === 1, 'Parents scoped to school.');
    directoryTestAssert(
        !array_key_exists('school_ids', $scope['rows'][0]),
        'Internal school-scope metadata not exposed.'
    );
    directoryTestAssert(
        esportsDirectorySnapshotSearch($parents, 'parents', 'parent', 0, 9, '', '', 0, 1, 25)['total'] === 1,
        'Parent sees their own guardian record only.'
    );
    directoryTestAssert(
        esportsDirectorySnapshotSearch($parents, 'parents', null, 0, 0, '', '', 0, 1, 25)['total'] === 0,
        'Guest cannot access guardian records.'
    );
    esportsDirectorySnapshotInvalidate('students');
    directoryTestAssert(
        esportsDirectorySnapshotRead('students') === null,
        'Invalidation removes private snapshot.'
    );
    echo "Directory JSON snapshots and scoped search tests passed.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    if (is_dir($dir)) {
        @rmdir($dir);
    }
}
