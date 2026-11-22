<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/psn_vault.php';
psnNoCacheHeaders();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false]);
    exit;
}
$scope = psnCredentialScope($pdo);
$schoolId = $scope['school_id'];
verifyCsrf();
if (!psnCredentialUnlocked($scope) || !psnVaultSchemaReady($pdo)) {
    http_response_code(403);
    echo json_encode(['ok'=>false]);
    exit;
}
try {
    $stmt = $pdo->prepare("SELECT id,totp_secret_encrypted FROM school_psn_accounts WHERE school_id=? AND account_status='active' AND totp_secret_encrypted IS NOT NULL");
    $stmt->execute([$schoolId]);
    $now = time();
    $codes = new stdClass();
    $available = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        try {
            $secret = psnVaultDecrypt((string)$account['totp_secret_encrypted']);
            if ($secret === null) continue;
            $available[(string)$account['id']] = psnTotpCode($secret, $now);
        } catch (Throwable $e) {
            // Never send malformed secrets or decryption errors to the client.
        }
    }
    echo json_encode(['ok'=>true,'server_time'=>$now,'codes'=>$available], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false]);
}
