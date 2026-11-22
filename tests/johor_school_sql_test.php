<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$sql = file_get_contents($root . '/database/imports/johor_schools_with_email.sql');
if (!is_string($sql)) { throw new RuntimeException('Missing Johor SQL'); }
$f = fopen($root . '/database/imports/johor_schools_with_email.csv', 'rb');
if (!$f) { throw new RuntimeException('Missing Johor CSV'); }
$header = fgetcsv($f);
if (!is_array($header) || count($header) !== 8) { throw new RuntimeException('Invalid CSV header'); }
$codes = [];
while (($r = fgetcsv($f)) !== false) {
    if (count($r) !== 8 || !preg_match('/^J[A-Z0-9]{6}$/D', $r[0])) {
        throw new RuntimeException('Invalid Johor row/code');
    }
    if (!filter_var($r[7], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid school email: ' . $r[0]);
    }
    if (isset($codes[$r[0]])) { throw new RuntimeException('Duplicate code'); }
    $codes[$r[0]] = true;
}
fclose($f);
if (count($codes) !== 1192) { throw new RuntimeException('Expected 1192 rows'); }
foreach (['suppress_school_email', 'unsubscribed', 'WHERE NOT EXISTS',
          'START TRANSACTION;', 'COMMIT;', 'CREATE TEMPORARY TABLE',
          '0, 1, 1,'] as $safe) {
    if (!str_contains($sql, $safe)) {
        throw new RuntimeException('SQL safety check failed: ' . $safe);
    }
}
preg_match_all("/^\\('([A-Z0-9]{7})', /m", $sql, $matches);
if (count($matches[1]) !== 1192) {
    throw new RuntimeException('Expected 1192 SQL data tuples, found '.count($matches[1]));
}
foreach ($matches[1] as $code) {
    if (!isset($codes[$code])) { throw new RuntimeException('SQL/CSV school-code mismatch'); }
}
echo "Johor school SQL: 1192 rows, 1192 email strings, no-send safeguards present.\n";
