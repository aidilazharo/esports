<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$stmt = $pdo->prepare(
    "UPDATE students
     SET membership_status = 'inactive',
         updated_at = CURRENT_TIMESTAMP
     WHERE membership_status = 'active'
       AND membership_next_due_at IS NOT NULL
       AND membership_next_due_at < NOW()"
);
$stmt->execute();

echo 'Expired annual memberships updated: ' . $stmt->rowCount() . PHP_EOL;
