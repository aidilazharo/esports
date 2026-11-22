<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
 * Simplified student membership:
 *   RM100 one-time payment = 1 year membership.
 *
 * Old RM10 registration + RM100 activation + RM30 monthly billing is retired
 * for new Checkout sessions. Compatibility wrappers are kept so older pages
 * fail gracefully or route to the annual membership flow.
 */

if (!defined('CURRENCY')) {
    define('CURRENCY', 'myr');
}
if (!defined('ANNUAL_MEMBERSHIP_FEE')) {
    define('ANNUAL_MEMBERSHIP_FEE', 10000); // RM100.00
}
if (!defined('ANNUAL_MEMBERSHIP_YEARS')) {
    define('ANNUAL_MEMBERSHIP_YEARS', 1);
}

/*
 * Legacy constants are retained only to avoid undefined-constant failures in
 * older pages that may still be deployed. New payment code must use
 * ANNUAL_MEMBERSHIP_FEE.
 */
if (!defined('REGISTRATION_FEE')) {
    define('REGISTRATION_FEE', 0);
}
if (!defined('MEMBERSHIP_ACTIVATION_FEE')) {
    define('MEMBERSHIP_ACTIVATION_FEE', ANNUAL_MEMBERSHIP_FEE);
}
if (!defined('JERSEY_CARD_FEE')) {
    define('JERSEY_CARD_FEE', 0);
}
if (!defined('MONTHLY_MEMBERSHIP_FEE')) {
    define('MONTHLY_MEMBERSHIP_FEE', 0);
}
if (!defined('MEMBERSHIP_INITIAL_DAYS')) {
    define('MEMBERSHIP_INITIAL_DAYS', 365);
}

function stripeClient(): \Stripe\StripeClient
{
    static $client = null;

    if ($client instanceof \Stripe\StripeClient) {
        return $client;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Stripe PHP is not installed. Run: composer install');
    }

    require_once $autoload;

    if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '' || str_contains(STRIPE_SECRET_KEY, 'replace_me')) {
        throw new RuntimeException('Set STRIPE_SECRET_KEY before accepting payments.');
    }

    $client = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    return $client;
}

// config.php in the existing portal already provides moneyFromMinor().
// Keep this fallback only for installations where it is missing, avoiding
// a fatal "Cannot redeclare moneyFromMinor()" HTTP 500 error.
if (!function_exists('moneyFromMinor')) {
    function moneyFromMinor(int $amount): string
    {
        return 'RM' . number_format($amount / 100, 2);
    }
}

function priceData(string $name, int $amount): array
{
    return [
        'currency' => CURRENCY,
        'unit_amount' => $amount,
        'product_data' => ['name' => $name],
    ];
}

/**
 * MYR one-time Checkout methods.
 * FPX and GrabPay must also be enabled in the Stripe Dashboard.
 */
function malaysiaOneTimePaymentMethods(): array
{
    return ['card', 'fpx', 'grabpay'];
}

function addStudentCustomerToCheckout(array &$parameters, array $student, bool $createCustomer = false): void
{
    if (!empty($student['stripe_customer_id'])) {
        $parameters['customer'] = $student['stripe_customer_id'];
        return;
    }

    if (!empty($student['email'])) {
        $parameters['customer_email'] = $student['email'];
    }

    if ($createCustomer) {
        $parameters['customer_creation'] = 'always';
    }
}

function saveCheckoutSession(PDO $pdo, int $studentId, string $sessionId, ?string $status = null): void
{
    if ($status === null) {
        $stmt = $pdo->prepare(
            'UPDATE students
             SET stripe_checkout_session_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$sessionId, $studentId]);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE students
         SET stripe_checkout_session_id = ?, membership_status = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->execute([$sessionId, $status, $studentId]);
}

function annualMembershipExpiry(?string $currentExpiry, int $paidTimestamp): string
{
    $paidDate = (new DateTimeImmutable('@' . $paidTimestamp))
        ->setTimezone(new DateTimeZone('UTC'));

    $baseDate = $paidDate;

    if ($currentExpiry) {
        $existing = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $currentExpiry,
            new DateTimeZone('UTC')
        );

        // Early renewal extends from the current valid expiry date.
        if ($existing instanceof DateTimeImmutable && $existing > $paidDate) {
            $baseDate = $existing;
        }
    }

    return $baseDate
        ->modify('+' . (int) ANNUAL_MEMBERSHIP_YEARS . ' year')
        ->format('Y-m-d H:i:s');
}

function createStudentAnnualMembershipCheckoutSession(PDO $pdo, array $student): string
{
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId <= 0) {
        throw new RuntimeException('Invalid student selected.');
    }

    $parameters = [
        'mode' => 'payment',
        'payment_method_types' => malaysiaOneTimePaymentMethods(),
        'line_items' => [[
            'price_data' => priceData(
                'Student Membership - 1 Year',
                ANNUAL_MEMBERSHIP_FEE
            ),
            'quantity' => 1,
        ]],
        'client_reference_id' => (string) $studentId,
        'metadata' => [
            'student_id' => (string) $studentId,
            'payment_stage' => 'annual_membership',
            'membership_years' => (string) ANNUAL_MEMBERSHIP_YEARS,
        ],
        'payment_intent_data' => [
            'metadata' => [
                'student_id' => (string) $studentId,
                'payment_stage' => 'annual_membership',
                'membership_years' => (string) ANNUAL_MEMBERSHIP_YEARS,
            ],
        ],
        'success_url' => APP_URL . '/payment_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => APP_URL . '/student_payment.php?student_id=' . $studentId,
    ];

    addStudentCustomerToCheckout($parameters, $student, true);

    $session = stripeClient()->checkout->sessions->create(
        $parameters,
        [
            'idempotency_key' => sprintf(
                'student-%d-annual-membership-%s',
                $studentId,
                gmdate('YmdHi')
            ),
        ]
    );

    $currentlyActive = strtolower((string) ($student['membership_status'] ?? '')) === 'active'
        && !empty($student['membership_next_due_at'])
        && strtotime((string) $student['membership_next_due_at'] . ' UTC') > time();

    // Do not temporarily deactivate a currently-paid member during early renewal.
    saveCheckoutSession(
        $pdo,
        $studentId,
        (string) $session->id,
        $currentlyActive ? null : 'pending_activation'
    );

    return (string) $session->url;
}

/* Backward-compatible entry points. */
function createStudentCheckoutSession(PDO $pdo, array $student): string
{
    return createStudentAnnualMembershipCheckoutSession($pdo, $student);
}

function createStudentRegistrationCheckoutSession(PDO $pdo, array $student): string
{
    return createStudentAnnualMembershipCheckoutSession($pdo, $student);
}

function createStudentActivationCheckoutSession(PDO $pdo, array $student): string
{
    return createStudentAnnualMembershipCheckoutSession($pdo, $student);
}

function createStudentRestartCheckoutSession(PDO $pdo, array $student): string
{
    return createStudentAnnualMembershipCheckoutSession($pdo, $student);
}

function createStudentMonthlySubscriptionCheckoutSession(PDO $pdo, array $student): string
{
    throw new RuntimeException('Monthly membership has been retired. Membership is now RM100 for 1 year.');
}

function createStudentManualMonthlyCheckoutSession(PDO $pdo, array $student): string
{
    throw new RuntimeException('Monthly membership has been retired. Membership is now RM100 for 1 year.');
}

function studentHasManageableSubscription(array $student): bool
{
    return false;
}

function createBillingPortalSession(array $student): string
{
    throw new RuntimeException('Monthly subscription billing is no longer used for student membership.');
}

function stripeDateTime(?int $timestamp): ?string
{
    return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
}
