<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_api_cache.php';

try {
    $identity = registrationNormalizeApiIdentity((string)($_GET['nric'] ?? $_GET['identity_number'] ?? ''));
    $requestedRole = strtolower(trim((string)($_GET['role'] ?? '')));

    if ($identity === '' || strlen($identity) < 4) {
        registrationJsonResponse([
            'success' => false,
            'exists' => false,
            'message' => 'Enter a valid NRIC / Passport number.',
        ], 422, 0);
    }

    $cacheKey = 'registration-identity-v2:' . $identity;
    $roles = registrationApiCacheGet($cacheKey, 60);

    if (!is_array($roles)) {
        $roles = [
            'teacher' => false,
            'student' => false,
            'parent' => false,
            'coach' => false,
        ];

        $checks = [
            'teacher' => 'teachers',
            'student' => 'students',
            'parent' => 'guardians',
            'coach' => 'coaches',
        ];

        foreach ($checks as $role => $table) {
            // Fast path for newly-normalized records.
            $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE identity_number=? LIMIT 1");
            $stmt->execute([$identity]);
            $exists = (bool)$stmt->fetchColumn();

            if (!$exists) {
                // Compatibility with legacy rows containing spaces or hyphens.
                $stmt = $pdo->prepare(
                    "SELECT 1
                     FROM `$table`
                     WHERE UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ',''))=?
                     LIMIT 1"
                );
                $stmt->execute([$identity]);
                $exists = (bool)$stmt->fetchColumn();
            }

            $roles[$role] = $exists;
        }

        registrationApiCacheSet($cacheKey, $roles);
    }

    $registeredRoles = [];
    foreach ($roles as $role => $exists) {
        if ($exists) $registeredRoles[] = $role;
    }

    $currentRoleExists = $requestedRole !== ''
        ? (bool)($roles[$requestedRole] ?? false)
        : !empty($registeredRoles);

    $otherRoles = array_values(array_filter(
        $registeredRoles,
        static fn(string $role): bool => $role !== $requestedRole
    ));

    if ($currentRoleExists && $requestedRole !== '') {
        $message = 'THIS NRIC / PASSPORT IS ALREADY REGISTERED AS ' . strtoupper($requestedRole) . '.';
    } elseif ($otherRoles) {
        $message = 'NRIC / PASSPORT FOUND AS ' . strtoupper(implode(', ', $otherRoles))
            . '. YOU MAY STILL REGISTER A DIFFERENT ROLE.';
    } else {
        $message = 'NRIC / PASSPORT IS AVAILABLE FOR THIS ROLE.';
    }

    registrationJsonResponse([
        'success' => true,
        'identity' => $identity,
        'role' => $requestedRole,
        'exists' => $currentRoleExists,
        'roles' => $roles,
        'registered_roles' => $registeredRoles,
        'other_roles' => $otherRoles,
        'message' => $message,
        'cached_for_seconds' => 60,
    ], 200, 30);
} catch (Throwable $e) {
    error_log('Registration identity API error: ' . $e->getMessage());
    registrationJsonResponse([
        'success' => false,
        'exists' => false,
        'message' => 'Unable to check NRIC / Passport at this time.',
    ], 500, 0);
}
