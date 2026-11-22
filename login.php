<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/school_auth.php';
require_once __DIR__ . '/includes/school_email_policy.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/jpn_auth.php';
require_once __DIR__ . '/includes/role_auth.php';
require_once __DIR__ . '/includes/pin_auth.php';
require_once __DIR__ . '/includes/remember_auth.php';

if (isset($_GET['logout'])) {
    portalRememberForgetCurrent($pdo);
    portalAuthLogoutAll(false);
    jpnAuthLogout(false);

    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_name'],
        $_SESSION['admin_email'],
        $_SESSION['admin_access_expires_at'],
        $_SESSION['jpn_authenticated'],
        $_SESSION['jpn_official_id'],
        $_SESSION['jpn_name'],
        $_SESSION['jpn_email'],
        $_SESSION['jpn_position'],
        $_SESSION['jpn_department'],
        $_SESSION['jpn_scope_state'],
        $_SESSION['jpn_scope_district'],
        $_SESSION['jpn_access_expires_at'],
        $_SESSION['jpn_pending_email'],
        $_SESSION['admin_pending_email'],
        $_SESSION['parent_pending_email']
    );

    session_regenerate_id(true);

    setFlash(
        'success',
        'You have signed out.'
    );

    redirect('login.php');
}

$requestedRole = strtolower(
    trim(
        (string)(
            $_GET['role']
            ?? $_POST['role']
            ?? 'school'
        )
    )
);

$selectedRole = in_array(
    $requestedRole,
    ['school', 'teacher', 'parent', 'jpn', 'admin'],
    true
)
    ? $requestedRole
    : 'school';

$defaultReturn = match ($selectedRole) {
    'admin' => '/admin/',
    'jpn' => '/jpn/',
    'parent' => '/parent/',
    'teacher' => '/teacher/',
    'school' => '/school/',
    default => '/',
};

$returnUrl = schoolAccessSafeReturn(
    $_GET['return']
    ?? $_POST['return']
    ?? null,
    $defaultReturn
);

/*
 * Restore a remembered login before normal role/session redirects.
 * The cookie stores only a random token, never the PIN/password.
 */
portalRememberRestore($pdo);

if (adminAccessIsAuthenticated()) {
    redirect('/admin/');
}

if (jpnAuthIsAuthenticated()) {
    redirect('/jpn/');
}

$currentPortalRole = portalAuthCurrentRole();

if ($currentPortalRole === 'parent') {
    redirect('/parent/');
}

if (
    in_array(
        $currentPortalRole,
        ['school', 'teacher'],
        true
    )
) {
    redirect(
        $currentPortalRole === 'teacher'
            ? '/teacher/'
            : '/school/'
    );
}

$pageTitle = 'Login';
$error = '';
$success = '';

$schoolSchemaReady = schoolAccessSchemaReady($pdo);
$teacherSchemaReady = teacherAccessSchemaReady($pdo);

$parentSchemaReady =
    portalAuthTableExists(
        $pdo,
        'parent_access_codes'
    );

$jpnSchemaReady =
    portalFixedPinSchemaReady(
        $pdo,
        'jpn'
    );

$adminSchemaReady =
    portalFixedPinSchemaReady(
        $pdo,
        'admin'
    );

$pendingSchoolCode = strtoupper(
    trim(
        (string)(
            $_SESSION['school_pending_code']
            ?? $_POST['school_code']
            ?? $_GET['school_code']
            ?? ''
        )
    )
);

$pendingTeacherEmail = strtolower(
    trim(
        (string)(
            $_SESSION['teacher_pending_email']
            ?? $_POST['teacher_email']
            ?? ''
        )
    )
);

$pendingParentEmail = strtolower(
    trim(
        (string)(
            $_SESSION['parent_pending_email']
            ?? $_POST['parent_email']
            ?? ''
        )
    )
);

$pendingJpnEmail = strtolower(
    trim(
        (string)(
            $_SESSION['jpn_pending_email']
            ?? $_POST['jpn_email']
            ?? ''
        )
    )
);

$pendingAdminEmail = strtolower(
    trim(
        (string)(
            $_SESSION['admin_pending_email']
            ?? $_POST['admin_email']
            ?? ''
        )
    )
);

$rememberRequested = isset($_POST['remember_me'])
    && (string)$_POST['remember_me'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = (string)(
        $_POST['action'] ?? ''
    );

    /*
     * ---------------------------------------------------------------
     * JPN: request a new reusable 6-digit PIN.
     * ---------------------------------------------------------------
     */
    if ($action === 'jpn_request_code') {
        $selectedRole = 'jpn';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['jpn_email'] ?? ''
                )
            )
        );

        $pendingJpnEmail = $email;

        if (!$jpnSchemaReady) {
            $error =
                'JPN PIN login database setup is required.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $error =
                'Enter the registered JPN email address.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    full_name,
                    email
                 FROM jpn_officials
                 WHERE LOWER(email) = LOWER(?)
                   AND is_active = 1
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $account = $stmt->fetch();

            if (!$account) {
                $error =
                    'No active JPN account was found for this email address.';
            } else {
                $cooldownStmt = $pdo->prepare(
                    'SELECT issued_at
                     FROM jpn_access_codes
                     WHERE jpn_official_id = ?
                     LIMIT 1'
                );

                $cooldownStmt->execute([
                    (int)$account['id'],
                ]);

                $issuedAt =
                    $cooldownStmt->fetchColumn();

                $lastIssue = $issuedAt
                    ? strtotime(
                        (string)$issuedAt
                    )
                    : false;

                if (
                    $lastIssue !== false
                    && $lastIssue > time() - 60
                ) {
                    $error =
                        'A JPN PIN was issued recently. '
                        . 'Please wait about 1 minute before requesting another PIN.';
                } else {
                    $code = str_pad(
                        (string)random_int(
                            0,
                            999999
                        ),
                        6,
                        '0',
                        STR_PAD_LEFT
                    );

                    $expiresAt =
                        (new DateTimeImmutable('now'))
                            ->modify('+1 month');

                    $mailResult =
                        portalAuthSendCodeEmail(
                            'JPN',
                            (string)$account['email'],
                            (string)$account['full_name'],
                            $code,
                            $expiresAt,
                            'jpn-portal-access-code'
                        );

                    if (
                        empty(
                            $mailResult['success']
                        )
                    ) {
                        $error =
                            (string)(
                                $mailResult['message']
                                ?? 'Unable to send the new JPN PIN.'
                            );
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO jpn_access_codes
                             (
                                jpn_official_id,
                                code_hash,
                                issued_at,
                                expires_at,
                                last_used_at
                             )
                             VALUES
                             (?, ?, NOW(), ?, NULL)
                             ON DUPLICATE KEY UPDATE
                                code_hash = VALUES(code_hash),
                                issued_at = NOW(),
                                expires_at = VALUES(expires_at),
                                last_used_at = NULL'
                        );

                        $stmt->execute([
                            (int)$account['id'],
                            password_hash(
                                $code,
                                PASSWORD_DEFAULT
                            ),
                            $expiresAt->format(
                                'Y-m-d H:i:s'
                            ),
                        ]);

                        $_SESSION[
                            'jpn_pending_email'
                        ] = $email;

                        $success =
                            'A new 6-digit JPN PIN was sent to '
                            . portalAuthMaskEmail(
                                (string)$account['email']
                            )
                            . '. It is valid for 1 month. '
                            . 'The previous PIN is no longer valid.';
                    }
                }
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * JPN: email + reusable 6-digit PIN.
     * ---------------------------------------------------------------
     */
    if ($action === 'jpn_login') {
        $selectedRole = 'jpn';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['jpn_email'] ?? ''
                )
            )
        );

        $pin = preg_replace(
            '/\D+/',
            '',
            (string)(
                $_POST['jpn_pin'] ?? ''
            )
        ) ?? '';

        $lockedUntil = (int)(
            $_SESSION['jpn_locked_until'] ?? 0
        );

        if (!$jpnSchemaReady) {
            $error =
                'JPN PIN login database setup is required.';
        } elseif ($lockedUntil > time()) {
            $error =
                'Too many failed attempts. Please wait '
                . ($lockedUntil - time())
                . ' seconds and try again.';
        } else {
            $result = portalFixedPinVerify(
                $pdo,
                'jpn',
                $email,
                $pin
            );

            if (!empty($result['success'])) {
                $row = $result['row'];

                session_regenerate_id(true);

                portalAuthLogoutAll(false);

                $_SESSION['jpn_authenticated'] = true;
                $_SESSION['jpn_official_id'] =
                    (int)$row['id'];

                $_SESSION['jpn_name'] =
                    (string)$row['full_name'];

                $_SESSION['jpn_email'] =
                    (string)$row['email'];

                $_SESSION['jpn_position'] =
                    (string)($row['position'] ?? '');

                $_SESSION['jpn_department'] =
                    (string)($row['department'] ?? '');

                $_SESSION['jpn_scope_state'] =
                    (string)($row['scope_state'] ?? '');

                $_SESSION['jpn_scope_district'] =
                    (string)($row['scope_district'] ?? '');

                $_SESSION['jpn_access_expires_at'] =
                    (int)$result['expires_ts'];

                $_SESSION['portal_role'] = 'jpn';

                if ($rememberRequested) {
                    portalRememberIssue(
                        $pdo,
                        'jpn',
                        (int)$row['id']
                    );
                } else {
                    portalRememberForgetCurrent($pdo);
                }

                unset(
                    $_SESSION['jpn_login_attempts'],
                    $_SESSION['jpn_locked_until'],
                    $_SESSION['jpn_pending_email']
                );

                redirect(
                    jpnAuthSafeReturn(
                        $_POST['return'] ?? null,
                        '/jpn/'
                    )
                );
            }

            $attempts =
                (int)(
                    $_SESSION['jpn_login_attempts']
                    ?? 0
                ) + 1;

            $_SESSION['jpn_login_attempts'] =
                $attempts;

            if ($attempts >= 5) {
                $_SESSION['jpn_locked_until'] =
                    time() + 60;

                $_SESSION['jpn_login_attempts'] = 0;

                $error =
                    'Too many failed attempts. '
                    . 'JPN login is locked for 60 seconds.';
            } else {
                $error =
                    (string)(
                        $result['message']
                        ?? 'Unable to sign in.'
                    );
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * ADMIN: request a new reusable 6-digit PIN.
     * ---------------------------------------------------------------
     */
    if ($action === 'admin_request_code') {
        $selectedRole = 'admin';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['admin_email'] ?? ''
                )
            )
        );

        $pendingAdminEmail = $email;

        if (!$adminSchemaReady) {
            $error =
                'Admin PIN login database setup is required.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $error =
                'Enter the registered administrator email address.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    full_name,
                    email
                 FROM admin_users
                 WHERE LOWER(email) = LOWER(?)
                   AND is_active = 1
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $account = $stmt->fetch();

            if (!$account) {
                $error =
                    'No active administrator account was found for this email address.';
            } else {
                $cooldownStmt = $pdo->prepare(
                    'SELECT issued_at
                     FROM admin_access_codes
                     WHERE admin_id = ?
                     LIMIT 1'
                );

                $cooldownStmt->execute([
                    (int)$account['id'],
                ]);

                $issuedAt =
                    $cooldownStmt->fetchColumn();

                $lastIssue = $issuedAt
                    ? strtotime(
                        (string)$issuedAt
                    )
                    : false;

                if (
                    $lastIssue !== false
                    && $lastIssue > time() - 60
                ) {
                    $error =
                        'An Admin PIN was issued recently. '
                        . 'Please wait about 1 minute before requesting another PIN.';
                } else {
                    $code = str_pad(
                        (string)random_int(
                            0,
                            999999
                        ),
                        6,
                        '0',
                        STR_PAD_LEFT
                    );

                    $expiresAt =
                        (new DateTimeImmutable('now'))
                            ->modify('+1 month');

                    $mailResult =
                        portalAuthSendCodeEmail(
                            'Admin',
                            (string)$account['email'],
                            (string)$account['full_name'],
                            $code,
                            $expiresAt,
                            'admin-portal-access-code'
                        );

                    if (
                        empty(
                            $mailResult['success']
                        )
                    ) {
                        $error =
                            (string)(
                                $mailResult['message']
                                ?? 'Unable to send the new Admin PIN.'
                            );
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO admin_access_codes
                             (
                                admin_id,
                                code_hash,
                                issued_at,
                                expires_at,
                                last_used_at
                             )
                             VALUES
                             (?, ?, NOW(), ?, NULL)
                             ON DUPLICATE KEY UPDATE
                                code_hash = VALUES(code_hash),
                                issued_at = NOW(),
                                expires_at = VALUES(expires_at),
                                last_used_at = NULL'
                        );

                        $stmt->execute([
                            (int)$account['id'],
                            password_hash(
                                $code,
                                PASSWORD_DEFAULT
                            ),
                            $expiresAt->format(
                                'Y-m-d H:i:s'
                            ),
                        ]);

                        $_SESSION[
                            'admin_pending_email'
                        ] = $email;

                        $success =
                            'A new 6-digit Admin PIN was sent to '
                            . portalAuthMaskEmail(
                                (string)$account['email']
                            )
                            . '. It is valid for 1 month. '
                            . 'The previous PIN is no longer valid.';
                    }
                }
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * ADMIN: email + reusable 6-digit PIN.
     * ---------------------------------------------------------------
     */
    if ($action === 'admin_login') {
        $selectedRole = 'admin';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['admin_email'] ?? ''
                )
            )
        );

        $pin = preg_replace(
            '/\D+/',
            '',
            (string)(
                $_POST['admin_pin'] ?? ''
            )
        ) ?? '';

        $lockedUntil = (int)(
            $_SESSION['admin_locked_until'] ?? 0
        );

        if (!$adminSchemaReady) {
            $error =
                'Admin PIN login database setup is required.';
        } elseif ($lockedUntil > time()) {
            $error =
                'Too many failed attempts. Please wait '
                . ($lockedUntil - time())
                . ' seconds and try again.';
        } else {
            $result = portalFixedPinVerify(
                $pdo,
                'admin',
                $email,
                $pin
            );

            if (!empty($result['success'])) {
                $row = $result['row'];

                session_regenerate_id(true);

                portalAuthLogoutAll(false);
                jpnAuthLogout(false);

                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_id'] =
                    (int)$row['id'];

                $_SESSION['admin_name'] =
                    (string)$row['full_name'];

                $_SESSION['admin_email'] =
                    (string)$row['email'];

                $_SESSION['admin_access_expires_at'] =
                    (int)$result['expires_ts'];

                $_SESSION['portal_role'] = 'admin';

                if ($rememberRequested) {
                    portalRememberIssue(
                        $pdo,
                        'admin',
                        (int)$row['id']
                    );
                } else {
                    portalRememberForgetCurrent($pdo);
                }

                unset(
                    $_SESSION['admin_login_attempts'],
                    $_SESSION['admin_locked_until'],
                    $_SESSION['admin_pending_email']
                );

                redirect(
                    schoolAccessSafeReturn(
                        $_POST['return'] ?? null,
                        '/admin/'
                    )
                );
            }

            $attempts =
                (int)(
                    $_SESSION['admin_login_attempts']
                    ?? 0
                ) + 1;

            $_SESSION['admin_login_attempts'] =
                $attempts;

            if ($attempts >= 5) {
                $_SESSION['admin_locked_until'] =
                    time() + 60;

                $_SESSION['admin_login_attempts'] = 0;

                $error =
                    'Too many failed attempts. '
                    . 'Admin login is locked for 60 seconds.';
            } else {
                $error =
                    (string)(
                        $result['message']
                        ?? 'Unable to sign in.'
                    );
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * PARENT: request reusable 6-digit PIN by registered email.
     * ---------------------------------------------------------------
     */
    if ($action === 'parent_request_code') {
        $selectedRole = 'parent';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['parent_email'] ?? ''
                )
            )
        );

        $pendingParentEmail = $email;

        if (!$parentSchemaReady) {
            $error =
                'Parent login database upgrade is required.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $error =
                'Enter the email address registered for the parent.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    guardian_number,
                    full_name,
                    email
                 FROM guardians
                 WHERE LOWER(email) = LOWER(?)
                 ORDER BY id DESC'
            );

            $stmt->execute([$email]);
            $matches = $stmt->fetchAll();

            if (!$matches) {
                $error =
                    'No parent account was found for this email address.';
            } elseif (count($matches) > 1) {
                $error =
                    'This email is linked to more than one parent account. '
                    . 'Please ask the administrator to correct the records.';
            } else {
                $parent = $matches[0];

                $cooldownStmt = $pdo->prepare(
                    'SELECT issued_at
                     FROM parent_access_codes
                     WHERE guardian_id = ?'
                );

                $cooldownStmt->execute([
                    (int)$parent['id'],
                ]);

                $issuedAt =
                    $cooldownStmt->fetchColumn();

                $lastIssue = $issuedAt
                    ? strtotime(
                        (string)$issuedAt
                    )
                    : false;

                if (
                    $lastIssue !== false
                    && $lastIssue > time() - 60
                ) {
                    $error =
                        'A PIN was sent recently. '
                        . 'Please wait about 1 minute.';
                } else {
                    $code = str_pad(
                        (string)random_int(
                            0,
                            999999
                        ),
                        6,
                        '0',
                        STR_PAD_LEFT
                    );

                    $expiresAt =
                        (new DateTimeImmutable('now'))
                            ->modify('+1 month');

                    $mailResult =
                        portalAuthSendCodeEmail(
                            'Parent',
                            (string)$parent['email'],
                            (string)$parent['full_name'],
                            $code,
                            $expiresAt,
                            'parent-portal-access-code'
                        );

                    if (
                        empty(
                            $mailResult['success']
                        )
                    ) {
                        $error =
                            (string)(
                                $mailResult['message']
                                ?? 'Unable to send the parent PIN.'
                            );
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO parent_access_codes
                             (
                                guardian_id,
                                code_hash,
                                sent_to_email,
                                issued_at,
                                expires_at,
                                last_used_at,
                                request_ip
                             )
                             VALUES
                             (
                                ?, ?, ?, NOW(), ?, NULL, ?
                             )
                             ON DUPLICATE KEY UPDATE
                                code_hash = VALUES(code_hash),
                                sent_to_email = VALUES(sent_to_email),
                                issued_at = NOW(),
                                expires_at = VALUES(expires_at),
                                last_used_at = NULL,
                                request_ip = VALUES(request_ip)'
                        );

                        $stmt->execute([
                            (int)$parent['id'],
                            password_hash(
                                $code,
                                PASSWORD_DEFAULT
                            ),
                            (string)$parent['email'],
                            $expiresAt->format(
                                'Y-m-d H:i:s'
                            ),
                            substr(
                                (string)(
                                    $_SERVER['REMOTE_ADDR']
                                    ?? ''
                                ),
                                0,
                                45
                            ),
                        ]);

                        $_SESSION[
                            'parent_pending_email'
                        ] = $email;

                        $success =
                            'A 6-digit PIN was sent to '
                            . portalAuthMaskEmail(
                                (string)$parent['email']
                            )
                            . '. It is valid for 1 month.';
                    }
                }
            }
        }
    }

    if ($action === 'parent_verify_code') {
        $selectedRole = 'parent';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['parent_email'] ?? ''
                )
            )
        );

        $pendingParentEmail = $email;

        $pin = preg_replace(
            '/\D+/',
            '',
            (string)(
                $_POST['access_code'] ?? ''
            )
        ) ?? '';

        $lockedUntil = (int)(
            $_SESSION['parent_locked_until'] ?? 0
        );

        if ($lockedUntil > time()) {
            $error =
                'Too many failed attempts. Please wait '
                . ($lockedUntil - time())
                . ' seconds and try again.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
            || !preg_match(
                '/^\d{6}$/',
                $pin
            )
        ) {
            $error =
                'Enter your registered parent email and 6-digit PIN.';
        } elseif (!$parentSchemaReady) {
            $error =
                'Parent login database upgrade is required.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    g.id AS guardian_id,
                    g.guardian_number,
                    g.full_name,
                    g.email,
                    a.code_hash,
                    a.expires_at
                 FROM guardians g
                 JOIN parent_access_codes a
                   ON a.guardian_id = g.id
                 WHERE LOWER(g.email) = LOWER(?)
                 ORDER BY g.id DESC'
            );

            $stmt->execute([$email]);
            $rows = $stmt->fetchAll();

            $matched = null;

            foreach ($rows as $row) {
                $expiresTs = strtotime(
                    (string)$row['expires_at']
                );

                if (
                    $expiresTs !== false
                    && $expiresTs >= time()
                    && password_verify(
                        $pin,
                        (string)$row['code_hash']
                    )
                ) {
                    $row['_expires_ts'] =
                        $expiresTs;

                    $matched = $row;
                    break;
                }
            }

            if ($matched) {
                session_regenerate_id(true);

                portalAuthClearOtherRoles(
                    'parent'
                );
                jpnAuthLogout(false);

                $_SESSION['parent_authenticated'] =
                    true;

                $_SESSION['parent_id'] =
                    (int)$matched['guardian_id'];

                $_SESSION['parent_number'] =
                    (string)(
                        $matched['guardian_number']
                        ?? ''
                    );

                $_SESSION['parent_name'] =
                    (string)$matched['full_name'];

                $_SESSION['parent_email'] =
                    (string)$matched['email'];

                $_SESSION['parent_access_expires_at'] =
                    (int)$matched['_expires_ts'];

                $_SESSION['portal_role'] =
                    'parent';

                if ($rememberRequested) {
                    portalRememberIssue(
                        $pdo,
                        'parent',
                        (int)$matched['guardian_id']
                    );
                } else {
                    portalRememberForgetCurrent($pdo);
                }

                unset(
                    $_SESSION['parent_login_attempts'],
                    $_SESSION['parent_locked_until'],
                    $_SESSION['parent_pending_email']
                );

                $pdo->prepare(
                    'UPDATE parent_access_codes
                     SET last_used_at = NOW()
                     WHERE guardian_id = ?'
                )->execute([
                    (int)$matched['guardian_id'],
                ]);

                redirect(
                    schoolAccessSafeReturn(
                        $_POST['return'] ?? null,
                        '/parent/'
                    )
                );
            }

            $attempts =
                (int)(
                    $_SESSION['parent_login_attempts']
                    ?? 0
                ) + 1;

            $_SESSION['parent_login_attempts'] =
                $attempts;

            $hasExpired = false;

            foreach ($rows as $row) {
                $ts = strtotime(
                    (string)$row['expires_at']
                );

                if (
                    $ts !== false
                    && $ts < time()
                ) {
                    $hasExpired = true;
                    break;
                }
            }

            if ($attempts >= 5) {
                $_SESSION['parent_locked_until'] =
                    time() + 60;

                $_SESSION['parent_login_attempts'] =
                    0;

                $error =
                    'Too many failed attempts. '
                    . 'Parent login is locked for 60 seconds.';
            } elseif ($hasExpired) {
                $error =
                    'This parent PIN has expired. Request a new PIN.';
            } else {
                $error =
                    'Incorrect parent email or 6-digit PIN.';
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * Existing School access flow.
     * ---------------------------------------------------------------
     */
    if ($action === 'school_request_code') {
        $selectedRole = 'school';

        if (!$schoolSchemaReady) {
            $error =
                'School login database upgrade is required.';
        } else {
            $schoolCode = strtoupper(
                trim(
                    (string)(
                        $_POST['school_code'] ?? ''
                    )
                )
            );

            if ($schoolCode === '') {
                $error =
                    'Enter the school code.';
            } else {
                $stmt = $pdo->prepare(
                    'SELECT
                        id,
                        school_code,
                        school_name,
                        contact_name,
                        contact_email
                     FROM schools
                     WHERE UPPER(school_code) = ?
                     LIMIT 1'
                );

                $stmt->execute([$schoolCode]);
                $school = $stmt->fetch();

                if (!$school) {
                    $error =
                        'School code was not found.';
                } elseif (schoolEmailIsSuppressed($pdo, (int) $school['id'])) {
                    $error =
                        'This directory school has not been activated for school login emails.';
                } elseif (
                    !filter_var(
                        (string)(
                            $school['contact_email']
                            ?? ''
                        ),
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $error =
                        'This school does not have a valid contact email.';
                } else {
                    $cooldownStmt = $pdo->prepare(
                        'SELECT issued_at
                         FROM school_access_codes
                         WHERE school_id = ?'
                    );

                    $cooldownStmt->execute([
                        (int)$school['id'],
                    ]);

                    $issuedAt =
                        $cooldownStmt->fetchColumn();

                    $lastIssue = $issuedAt
                        ? strtotime(
                            (string)$issuedAt
                        )
                        : false;

                    if (
                        $lastIssue !== false
                        && $lastIssue > time() - 60
                    ) {
                        $error =
                            'A PIN was sent recently. Please wait about 1 minute.';
                    } else {
                        $code = str_pad(
                            (string)random_int(
                                0,
                                999999
                            ),
                            6,
                            '0',
                            STR_PAD_LEFT
                        );

                        $expiresAt =
                            (new DateTimeImmutable(
                                'now'
                            ))
                            ->modify('+1 month');

                        $mailResult =
                            schoolAccessSendEmail(
                                $school,
                                $code,
                                $expiresAt
                            );

                        if (
                            empty(
                                $mailResult['success']
                            )
                        ) {
                            $error =
                                (string)(
                                    $mailResult['message']
                                    ?? 'Unable to send the school PIN.'
                                );
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO school_access_codes
                                 (
                                    school_id,
                                    code_hash,
                                    sent_to_email,
                                    issued_at,
                                    expires_at,
                                    last_used_at,
                                    request_ip
                                 )
                                 VALUES
                                 (?, ?, ?, NOW(), ?, NULL, ?)
                                 ON DUPLICATE KEY UPDATE
                                    code_hash=VALUES(code_hash),
                                    sent_to_email=VALUES(sent_to_email),
                                    issued_at=NOW(),
                                    expires_at=VALUES(expires_at),
                                    last_used_at=NULL,
                                    request_ip=VALUES(request_ip)'
                            );

                            $stmt->execute([
                                (int)$school['id'],
                                password_hash(
                                    $code,
                                    PASSWORD_DEFAULT
                                ),
                                (string)$school[
                                    'contact_email'
                                ],
                                $expiresAt->format(
                                    'Y-m-d H:i:s'
                                ),
                                substr(
                                    (string)(
                                        $_SERVER[
                                            'REMOTE_ADDR'
                                        ] ?? ''
                                    ),
                                    0,
                                    45
                                ),
                            ]);

                            $_SESSION[
                                'school_pending_code'
                            ] =
                                (string)$school[
                                    'school_code'
                                ];

                            $pendingSchoolCode =
                                (string)$school[
                                    'school_code'
                                ];

                            $success =
                                'A 6-digit PIN was sent to '
                                . schoolAccessMaskEmail(
                                    (string)$school[
                                        'contact_email'
                                    ]
                                )
                                . '. It is valid for 1 month.';
                        }
                    }
                }
            }
        }
    }

    if ($action === 'school_verify_code') {
        $selectedRole = 'school';

        $schoolCode = strtoupper(
            trim(
                (string)(
                    $_POST['school_code'] ?? ''
                )
            )
        );

        $code = preg_replace(
            '/\D+/',
            '',
            (string)(
                $_POST['access_code'] ?? ''
            )
        ) ?? '';

        $lockedUntil = (int)(
            $_SESSION['school_locked_until'] ?? 0
        );

        if ($lockedUntil > time()) {
            $error =
                'Too many failed attempts. Please wait '
                . ($lockedUntil - time())
                . ' seconds and try again.';
        } elseif (
            $schoolCode === ''
            || !preg_match(
                '/^\d{6}$/',
                $code
            )
        ) {
            $error =
                'Enter the school code and 6-digit PIN.';
        } elseif (!$schoolSchemaReady) {
            $error =
                'School login database upgrade is required.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    s.id,
                    s.school_code,
                    s.school_name,
                    a.code_hash,
                    a.expires_at
                 FROM schools s
                 JOIN school_access_codes a
                   ON a.school_id = s.id
                 WHERE UPPER(s.school_code) = ?
                 LIMIT 1'
            );

            $stmt->execute([$schoolCode]);
            $row = $stmt->fetch();

            $expiresTs = $row
                ? strtotime(
                    (string)$row['expires_at']
                )
                : false;

            if (
                $row
                && $expiresTs !== false
                && $expiresTs >= time()
                && password_verify(
                    $code,
                    (string)$row['code_hash']
                )
            ) {
                session_regenerate_id(true);

                portalAuthClearOtherRoles(
                    'school'
                );
                jpnAuthLogout(false);

                $_SESSION['school_authenticated'] =
                    true;

                $_SESSION['school_id'] =
                    (int)$row['id'];

                $_SESSION['school_name'] =
                    (string)$row['school_name'];

                $_SESSION['school_code'] =
                    (string)$row['school_code'];

                $_SESSION['school_access_expires_at'] =
                    $expiresTs;

                $_SESSION['portal_role'] =
                    'school';

                if ($rememberRequested) {
                    portalRememberIssue(
                        $pdo,
                        'school',
                        (int)$row['id']
                    );
                } else {
                    portalRememberForgetCurrent($pdo);
                }

                unset(
                    $_SESSION['school_login_attempts'],
                    $_SESSION['school_locked_until'],
                    $_SESSION['school_pending_code']
                );

                $pdo->prepare(
                    'UPDATE school_access_codes
                     SET last_used_at = NOW()
                     WHERE school_id = ?'
                )->execute([
                    (int)$row['id'],
                ]);

                redirect(
                    schoolAccessSafeReturn(
                        $_POST['return'] ?? null,
                        '/school/'
                    )
                );
            }

            $attempts =
                (int)(
                    $_SESSION['school_login_attempts']
                    ?? 0
                ) + 1;

            $_SESSION['school_login_attempts'] =
                $attempts;

            if ($attempts >= 5) {
                $_SESSION['school_locked_until'] =
                    time() + 60;

                $_SESSION['school_login_attempts'] = 0;

                $error =
                    'Too many failed attempts. '
                    . 'School login is locked for 60 seconds.';
            } elseif (
                $row
                && $expiresTs !== false
                && $expiresTs < time()
            ) {
                $error =
                    'This school PIN has expired. Request a new PIN.';
            } else {
                $error =
                    'Incorrect school code or 6-digit PIN.';
            }
        }
    }

    /*
     * ---------------------------------------------------------------
     * Existing Teacher access flow.
     * ---------------------------------------------------------------
     */
    if ($action === 'teacher_request_code') {
        $selectedRole = 'teacher';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['teacher_email'] ?? ''
                )
            )
        );

        $pendingTeacherEmail = $email;

        if (!$teacherSchemaReady) {
            $error =
                'Teacher login database upgrade is required.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $error =
                'Enter the email address registered for the teacher.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    t.id,
                    t.school_id,
                    t.full_name,
                    t.email,
                    s.school_code,
                    s.school_name
                 FROM teachers t
                 JOIN schools s
                   ON s.id = t.school_id
                 WHERE LOWER(t.email) = LOWER(?)
                 ORDER BY t.id DESC'
            );

            $stmt->execute([$email]);
            $matches = $stmt->fetchAll();

            if (!$matches) {
                $error =
                    'No teacher account was found for this email address.';
            } else {
                $schoolIds = array_values(
                    array_unique(
                        array_map(
                            static fn(array $r): int =>
                                (int)$r['school_id'],
                            $matches
                        )
                    )
                );

                if (count($schoolIds) > 1) {
                    $error =
                        'This email is registered under more than one school.';
                } else {
                    $teacher = $matches[0];

                    $cooldownStmt = $pdo->prepare(
                        'SELECT issued_at
                         FROM teacher_access_codes
                         WHERE teacher_id = ?'
                    );

                    $cooldownStmt->execute([
                        (int)$teacher['id'],
                    ]);

                    $issuedAt =
                        $cooldownStmt->fetchColumn();

                    $lastIssue = $issuedAt
                        ? strtotime(
                            (string)$issuedAt
                        )
                        : false;

                    if (
                        $lastIssue !== false
                        && $lastIssue > time() - 60
                    ) {
                        $error =
                            'A PIN was sent recently. Please wait about 1 minute.';
                    } else {
                        $code = str_pad(
                            (string)random_int(
                                0,
                                999999
                            ),
                            6,
                            '0',
                            STR_PAD_LEFT
                        );

                        $expiresAt =
                            (new DateTimeImmutable(
                                'now'
                            ))
                            ->modify('+1 month');

                        $mailResult =
                            teacherAccessSendEmail(
                                $teacher,
                                $code,
                                $expiresAt
                            );

                        if (
                            empty(
                                $mailResult['success']
                            )
                        ) {
                            $error =
                                (string)(
                                    $mailResult['message']
                                    ?? 'Unable to send the teacher PIN.'
                                );
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO teacher_access_codes
                                 (
                                    teacher_id,
                                    code_hash,
                                    sent_to_email,
                                    issued_at,
                                    expires_at,
                                    last_used_at,
                                    request_ip
                                 )
                                 VALUES
                                 (?, ?, ?, NOW(), ?, NULL, ?)
                                 ON DUPLICATE KEY UPDATE
                                    code_hash=VALUES(code_hash),
                                    sent_to_email=VALUES(sent_to_email),
                                    issued_at=NOW(),
                                    expires_at=VALUES(expires_at),
                                    last_used_at=NULL,
                                    request_ip=VALUES(request_ip)'
                            );

                            $stmt->execute([
                                (int)$teacher['id'],
                                password_hash(
                                    $code,
                                    PASSWORD_DEFAULT
                                ),
                                (string)$teacher['email'],
                                $expiresAt->format(
                                    'Y-m-d H:i:s'
                                ),
                                substr(
                                    (string)(
                                        $_SERVER[
                                            'REMOTE_ADDR'
                                        ] ?? ''
                                    ),
                                    0,
                                    45
                                ),
                            ]);

                            $_SESSION[
                                'teacher_pending_email'
                            ] = $email;

                            $success =
                                'A 6-digit PIN was sent to '
                                . schoolAccessMaskEmail(
                                    (string)$teacher[
                                        'email'
                                    ]
                                )
                                . '. It is valid for 1 month.';
                        }
                    }
                }
            }
        }
    }

    if ($action === 'teacher_verify_code') {
        $selectedRole = 'teacher';

        $email = strtolower(
            trim(
                (string)(
                    $_POST['teacher_email'] ?? ''
                )
            )
        );

        $pendingTeacherEmail = $email;

        $code = preg_replace(
            '/\D+/',
            '',
            (string)(
                $_POST['access_code'] ?? ''
            )
        ) ?? '';

        $lockedUntil = (int)(
            $_SESSION['teacher_locked_until']
            ?? 0
        );

        if ($lockedUntil > time()) {
            $error =
                'Too many failed attempts. Please wait '
                . ($lockedUntil - time())
                . ' seconds and try again.';
        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
            || !preg_match(
                '/^\d{6}$/',
                $code
            )
        ) {
            $error =
                'Enter your registered teacher email and 6-digit PIN.';
        } elseif (!$teacherSchemaReady) {
            $error =
                'Teacher login database upgrade is required.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    t.id AS teacher_id,
                    t.full_name,
                    t.email,
                    t.school_id,
                    s.school_code,
                    s.school_name,
                    a.code_hash,
                    a.expires_at
                 FROM teachers t
                 JOIN schools s
                   ON s.id = t.school_id
                 JOIN teacher_access_codes a
                   ON a.teacher_id = t.id
                 WHERE LOWER(t.email) = LOWER(?)
                 ORDER BY t.id DESC'
            );

            $stmt->execute([$email]);
            $rows = $stmt->fetchAll();

            $matched = null;

            foreach ($rows as $row) {
                $expiresTs = strtotime(
                    (string)$row['expires_at']
                );

                if (
                    $expiresTs !== false
                    && $expiresTs >= time()
                    && password_verify(
                        $code,
                        (string)$row['code_hash']
                    )
                ) {
                    $row['_expires_ts'] =
                        $expiresTs;

                    $matched = $row;
                    break;
                }
            }

            if ($matched) {
                session_regenerate_id(true);

                portalAuthClearOtherRoles(
                    'teacher'
                );
                jpnAuthLogout(false);

                $_SESSION['teacher_authenticated'] =
                    true;

                $_SESSION['teacher_id'] =
                    (int)$matched['teacher_id'];

                $_SESSION['teacher_name'] =
                    (string)$matched['full_name'];

                $_SESSION['teacher_email'] =
                    (string)$matched['email'];

                $_SESSION['teacher_access_expires_at'] =
                    (int)$matched['_expires_ts'];

                $_SESSION['school_id'] =
                    (int)$matched['school_id'];

                $_SESSION['school_name'] =
                    (string)$matched['school_name'];

                $_SESSION['school_code'] =
                    (string)$matched['school_code'];

                $_SESSION['portal_role'] =
                    'teacher';

                if ($rememberRequested) {
                    portalRememberIssue(
                        $pdo,
                        'teacher',
                        (int)$matched['teacher_id']
                    );
                } else {
                    portalRememberForgetCurrent($pdo);
                }

                unset(
                    $_SESSION['teacher_login_attempts'],
                    $_SESSION['teacher_locked_until'],
                    $_SESSION['teacher_pending_email']
                );

                $pdo->prepare(
                    'UPDATE teacher_access_codes
                     SET last_used_at = NOW()
                     WHERE teacher_id = ?'
                )->execute([
                    (int)$matched['teacher_id'],
                ]);

                redirect(
                    schoolAccessSafeReturn(
                        $_POST['return'] ?? null,
                        '/teacher/'
                    )
                );
            }

            $attempts =
                (int)(
                    $_SESSION['teacher_login_attempts']
                    ?? 0
                ) + 1;

            $_SESSION['teacher_login_attempts'] =
                $attempts;

            $hasExpired = false;

            foreach ($rows as $row) {
                $ts = strtotime(
                    (string)$row['expires_at']
                );

                if (
                    $ts !== false
                    && $ts < time()
                ) {
                    $hasExpired = true;
                    break;
                }
            }

            if ($attempts >= 5) {
                $_SESSION['teacher_locked_until'] =
                    time() + 60;

                $_SESSION['teacher_login_attempts'] =
                    0;

                $error =
                    'Too many failed attempts. '
                    . 'Teacher login is locked for 60 seconds.';
            } elseif ($hasExpired) {
                $error =
                    'This teacher PIN has expired. Request a new PIN.';
            } else {
                $error =
                    'Incorrect teacher email or 6-digit PIN.';
            }
        }
    }
}

require 'includes/header.php';
?>

<style>
.portal-login-wrap{max-width:820px;margin:0 auto}
.portal-login-head{margin-bottom:1rem}
.portal-login-card .card-body{padding:1.15rem 1.25rem}
.portal-login-card .form-label{font-size:.86rem;margin-bottom:.3rem}
.portal-login-card .form-text{font-size:.76rem}
.portal-login-card .form-control,
.portal-login-card .form-select{min-height:40px}
.portal-role-card .card-body{padding:.8rem 1rem}
.portal-role-card .form-select{min-height:42px}
.portal-login-title{font-size:1.15rem}
.portal-login-subtitle{font-size:.88rem}
.portal-login-step{
    border:1px solid var(--bs-border-color);
    border-radius:.6rem;
    padding:.9rem;
    height:100%;
}
@media(max-width:767.98px){
    .portal-login-card .card-body{padding:1rem}
}
</style>

<div class="portal-login-wrap py-3">
    <div class="text-center portal-login-head">
        <div class="icon-box bg-primary-subtle text-primary mx-auto mb-2">
            <i class="bi bi-person-lock"></i>
        </div>
        <h1 class="h4 mb-1">Portal Login</h1>
        <p class="text-secondary small mb-0">
            Select your role and sign in.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success py-2">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="card content-card shadow-sm portal-role-card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-sm-3">
                    <label
                        class="form-label fw-semibold mb-sm-0"
                        for="portalRoleSelect"
                    >
                        Login Role
                    </label>
                </div>

                <div class="col-sm-9">
                    <select
                        class="form-select"
                        id="portalRoleSelect"
                    >
                        <option
                            value="school"
                            <?= $selectedRole === 'school' ? 'selected' : '' ?>
                        >
                            School
                        </option>

                        <option
                            value="teacher"
                            <?= $selectedRole === 'teacher' ? 'selected' : '' ?>
                        >
                            Teacher
                        </option>

                        <option
                            value="parent"
                            <?= $selectedRole === 'parent' ? 'selected' : '' ?>
                        >
                            Parent
                        </option>

                        <option
                            value="jpn"
                            <?= $selectedRole === 'jpn' ? 'selected' : '' ?>
                        >
                            JPN
                        </option>

                        <option
                            value="admin"
                            <?= $selectedRole === 'admin' ? 'selected' : '' ?>
                        >
                            Admin
                        </option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php if ($selectedRole === 'school'): ?>
        <div class="card content-card shadow-sm portal-login-card">
            <div class="card-body">
                <h2 class="portal-login-title mb-1">School Login</h2>
                <p class="text-secondary portal-login-subtitle mb-3">
                    The 6-digit PIN is reusable for 1 month.
                </p>

                <?php if (!$schoolSchemaReady): ?>
                    <div class="alert alert-warning py-2">
                        School login database upgrade is required.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Request PIN
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="school">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="school_request_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="request-school-code">
                                    School Code
                                </label>

                                <input
                                    class="form-control text-uppercase"
                                    id="request-school-code"
                                    name="school_code"
                                    value="<?= e($pendingSchoolCode) ?>"
                                    placeholder="CBA4001"
                                    required
                                    <?= !$schoolSchemaReady ? 'disabled' : '' ?>
                                >

                                <button
                                    class="btn btn-outline-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$schoolSchemaReady ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-envelope me-1"></i>
                                    Send PIN
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Sign In
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="school">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="school_verify_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <div class="row g-2">
                                    <div class="col-7">
                                        <label class="form-label" for="login-school-code">
                                            School Code
                                        </label>
                                        <input
                                            class="form-control text-uppercase"
                                            id="login-school-code"
                                            name="school_code"
                                            value="<?= e($pendingSchoolCode) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="col-5">
                                        <label class="form-label" for="school-access-code">
                                            PIN
                                        </label>
                                        <input
                                            type="password"
                                            class="form-control text-center"
                                            id="school-access-code"
                                            name="access_code"
                                            inputmode="numeric"
                                            pattern="[0-9]{6}"
                                            minlength="6"
                                            maxlength="6"
                                            autocomplete="one-time-code"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="remember_me"
                                        id="remember-school"
                                    >
                                    <label class="form-check-label small" for="remember-school">
                                        Remember me for 1 month
                                    </label>
                                </div>

                                <button class="btn btn-primary btn-sm w-100 mt-2" type="submit">
                                    Sign In
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($selectedRole === 'teacher'): ?>
        <div class="card content-card shadow-sm portal-login-card">
            <div class="card-body">
                <h2 class="portal-login-title mb-1">Teacher Login</h2>
                <p class="text-secondary portal-login-subtitle mb-3">
                    The 6-digit PIN is reusable for 1 month.
                </p>

                <?php if (!$teacherSchemaReady): ?>
                    <div class="alert alert-warning py-2">
                        Teacher login database upgrade is required.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Request PIN
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="teacher">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="teacher_request_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="request-teacher-email">
                                    Teacher Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="request-teacher-email"
                                    name="teacher_email"
                                    value="<?= e($pendingTeacherEmail) ?>"
                                    required
                                    <?= !$teacherSchemaReady ? 'disabled' : '' ?>
                                >

                                <button
                                    class="btn btn-outline-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$teacherSchemaReady ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-envelope me-1"></i>
                                    Send PIN
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Sign In
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="teacher">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="teacher_verify_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="login-teacher-email">
                                    Teacher Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="login-teacher-email"
                                    name="teacher_email"
                                    value="<?= e($pendingTeacherEmail) ?>"
                                    required
                                >

                                <label class="form-label mt-2" for="teacher-access-code">
                                    6-Digit PIN
                                </label>

                                <input
                                    type="password"
                                    class="form-control text-center"
                                    id="teacher-access-code"
                                    name="access_code"
                                    inputmode="numeric"
                                    pattern="[0-9]{6}"
                                    minlength="6"
                                    maxlength="6"
                                    autocomplete="one-time-code"
                                    required
                                >

                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="remember_me"
                                        id="remember-teacher"
                                    >
                                    <label class="form-check-label small" for="remember-teacher">
                                        Remember me for 1 month
                                    </label>
                                </div>

                                <button class="btn btn-primary btn-sm w-100 mt-2" type="submit">
                                    Sign In
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($selectedRole === 'parent'): ?>
        <div class="card content-card shadow-sm portal-login-card">
            <div class="card-body">
                <h2 class="portal-login-title mb-1">Parent Login</h2>
                <p class="text-secondary portal-login-subtitle mb-3">
                    Request a reusable 6-digit PIN using the registered parent email.
                </p>

                <?php if (!$parentSchemaReady): ?>
                    <div class="alert alert-warning py-2">
                        Run the PIN access database upgrade before using Parent login.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Request PIN
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="parent">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="parent_request_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="request-parent-email">
                                    Parent Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="request-parent-email"
                                    name="parent_email"
                                    value="<?= e($pendingParentEmail) ?>"
                                    required
                                    <?= !$parentSchemaReady ? 'disabled' : '' ?>
                                >

                                <button
                                    class="btn btn-outline-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$parentSchemaReady ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-envelope me-1"></i>
                                    Send PIN
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Sign In
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="parent">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="parent_verify_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="login-parent-email">
                                    Parent Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="login-parent-email"
                                    name="parent_email"
                                    value="<?= e($pendingParentEmail) ?>"
                                    required
                                >

                                <label class="form-label mt-2" for="parent-access-code">
                                    6-Digit PIN
                                </label>

                                <input
                                    type="password"
                                    class="form-control text-center"
                                    id="parent-access-code"
                                    name="access_code"
                                    inputmode="numeric"
                                    pattern="[0-9]{6}"
                                    minlength="6"
                                    maxlength="6"
                                    autocomplete="one-time-code"
                                    required
                                >

                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="remember_me"
                                        id="remember-parent"
                                    >
                                    <label class="form-check-label small" for="remember-parent">
                                        Remember me for 1 month
                                    </label>
                                </div>

                                <button class="btn btn-primary btn-sm w-100 mt-2" type="submit">
                                    Sign In
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($selectedRole === 'jpn'): ?>
        <div class="card content-card shadow-sm portal-login-card">
            <div class="card-body">
                <h2 class="portal-login-title mb-1">JPN Login</h2>
                <p class="text-secondary portal-login-subtitle mb-3">
                    Use your registered JPN email and reusable 6-digit PIN.
                </p>

                <?php if (!$jpnSchemaReady): ?>
                    <div class="alert alert-warning py-2">
                        Run the PIN access database upgrade before using JPN login.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Request New PIN
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="jpn">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="jpn_request_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="request-jpn-email">
                                    JPN Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="request-jpn-email"
                                    name="jpn_email"
                                    value="<?= e($pendingJpnEmail) ?>"
                                    autocomplete="email"
                                    required
                                    <?= !$jpnSchemaReady ? 'disabled' : '' ?>
                                >

                                <button
                                    class="btn btn-outline-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$jpnSchemaReady ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-envelope me-1"></i>
                                    Request New PIN
                                </button>

                                <div class="form-text mt-2">
                                    A new PIN replaces the previous PIN and is valid for 1 month.
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Sign In
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="jpn">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="jpn_login"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <div class="row g-2">
                                    <div class="col-sm-8">
                                        <label class="form-label" for="jpn-email">
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            class="form-control"
                                            id="jpn-email"
                                            name="jpn_email"
                                            value="<?= e($pendingJpnEmail) ?>"
                                            autocomplete="username"
                                            required
                                            <?= !$jpnSchemaReady ? 'disabled' : '' ?>
                                        >
                                    </div>

                                    <div class="col-sm-4">
                                        <label class="form-label" for="jpn-pin">
                                            6-Digit PIN
                                        </label>

                                        <input
                                            type="password"
                                            class="form-control text-center"
                                            id="jpn-pin"
                                            name="jpn_pin"
                                            inputmode="numeric"
                                            pattern="[0-9]{6}"
                                            minlength="6"
                                            maxlength="6"
                                            autocomplete="one-time-code"
                                            required
                                            <?= !$jpnSchemaReady ? 'disabled' : '' ?>
                                        >
                                    </div>
                                </div>

                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="remember_me"
                                        id="remember-jpn"
                                    >
                                    <label class="form-check-label small" for="remember-jpn">
                                        Remember me for 1 month
                                    </label>
                                </div>

                                <button
                                    class="btn btn-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$jpnSchemaReady ? 'disabled' : '' ?>
                                >
                                    JPN Sign In
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="card content-card shadow-sm portal-login-card">
            <div class="card-body">
                <h2 class="portal-login-title mb-1">Admin Login</h2>
                <p class="text-secondary portal-login-subtitle mb-3">
                    Use your administrator email and reusable 6-digit PIN.
                </p>

                <?php if (!$adminSchemaReady): ?>
                    <div class="alert alert-warning py-2">
                        Run the PIN access database upgrade before using Admin login.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Request New PIN
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="admin">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="admin_request_code"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <label class="form-label" for="request-admin-email">
                                    Admin Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="request-admin-email"
                                    name="admin_email"
                                    value="<?= e($pendingAdminEmail) ?>"
                                    autocomplete="email"
                                    required
                                    <?= !$adminSchemaReady ? 'disabled' : '' ?>
                                >

                                <button
                                    class="btn btn-outline-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$adminSchemaReady ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-envelope me-1"></i>
                                    Request New PIN
                                </button>

                                <div class="form-text mt-2">
                                    A new PIN replaces the previous PIN and is valid for 1 month.
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="portal-login-step">
                            <div class="fw-semibold small mb-2">
                                Sign In
                            </div>

                            <form method="post" autocomplete="off">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >
                                <input type="hidden" name="role" value="admin">
                                <input
                                    type="hidden"
                                    name="action"
                                    value="admin_login"
                                >
                                <input
                                    type="hidden"
                                    name="return"
                                    value="<?= e($returnUrl) ?>"
                                >

                                <div class="row g-2">
                                    <div class="col-sm-8">
                                        <label class="form-label" for="admin-email">
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            class="form-control"
                                            id="admin-email"
                                            name="admin_email"
                                            value="<?= e($pendingAdminEmail) ?>"
                                            autocomplete="username"
                                            required
                                            <?= !$adminSchemaReady ? 'disabled' : '' ?>
                                        >
                                    </div>

                                    <div class="col-sm-4">
                                        <label class="form-label" for="admin-pin">
                                            6-Digit PIN
                                        </label>

                                        <input
                                            type="password"
                                            class="form-control text-center"
                                            id="admin-pin"
                                            name="admin_pin"
                                            inputmode="numeric"
                                            pattern="[0-9]{6}"
                                            minlength="6"
                                            maxlength="6"
                                            autocomplete="one-time-code"
                                            required
                                            <?= !$adminSchemaReady ? 'disabled' : '' ?>
                                        >
                                    </div>
                                </div>

                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="1"
                                        name="remember_me"
                                        id="remember-admin"
                                    >
                                    <label class="form-check-label small" for="remember-admin">
                                        Remember me for 1 month
                                    </label>
                                </div>

                                <button
                                    class="btn btn-primary btn-sm w-100 mt-2"
                                    type="submit"
                                    <?= !$adminSchemaReady ? 'disabled' : '' ?>
                                >
                                    Admin Sign In
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var select =
        document.getElementById('portalRoleSelect');

    if (!select) {
        return;
    }

    select.addEventListener('change', function () {
        var role = select.value || 'school';

        window.location.assign(
            'login.php?role='
            + encodeURIComponent(role)
        );
    });
});
</script>

<?php require 'includes/footer.php'; ?>
