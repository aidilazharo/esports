<?php

declare(strict_types=1);

require_once __DIR__ . '/stripe_helpers.php';

header('Content-Type: application/json');

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '' || str_contains(STRIPE_WEBHOOK_SECRET, 'replace_me')) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe webhook secret is not configured.']);
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo json_encode(['error' => 'Run composer install first.']);
    exit;
}
require_once $autoload;

try {
    $event = \Stripe\Webhook::constructEvent($payload, $signature, STRIPE_WEBHOOK_SECRET);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook payload.']);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature.']);
    exit;
}

function annualFindStudentId(PDO $pdo, array $object): int
{
    $metadataId = (int) ($object['metadata']['student_id'] ?? 0);
    if ($metadataId > 0) {
        return $metadataId;
    }

    $referenceId = (int) ($object['client_reference_id'] ?? 0);
    if ($referenceId > 0) {
        return $referenceId;
    }

    $customerId = $object['customer'] ?? null;
    if ($customerId) {
        $stmt = $pdo->prepare(
            'SELECT id FROM students
             WHERE stripe_customer_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$customerId]);
        return (int) $stmt->fetchColumn();
    }

    return 0;
}

function annualPaymentIntentId(array $object): ?string
{
    $value = $object['payment_intent'] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

function annualPaymentAlreadyRecorded(PDO $pdo, ?string $paymentIntentId): bool
{
    if (!$paymentIntentId) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM payments
         WHERE stripe_payment_intent_id = ?
           AND payment_type = 'annual_membership'
           AND status = 'paid'
         LIMIT 1"
    );
    $stmt->execute([$paymentIntentId]);

    return (bool) $stmt->fetchColumn();
}

function annualInsertOrUpdatePayment(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO payments
         (student_id, stripe_event_id, stripe_invoice_id, stripe_payment_intent_id,
          payment_type, description, amount_minor, currency, status, paid_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             stripe_event_id = VALUES(stripe_event_id),
             stripe_invoice_id = COALESCE(VALUES(stripe_invoice_id), stripe_invoice_id),
             stripe_payment_intent_id = COALESCE(VALUES(stripe_payment_intent_id), stripe_payment_intent_id),
             payment_type = VALUES(payment_type),
             description = VALUES(description),
             amount_minor = VALUES(amount_minor),
             currency = VALUES(currency),
             status = VALUES(status),
             paid_at = VALUES(paid_at),
             updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute($values);
}

function completeAnnualMembershipPayment(
    PDO $pdo,
    array $object,
    string $eventId,
    int $eventCreated
): void {
    $studentId = annualFindStudentId($pdo, $object);
    if ($studentId <= 0) {
        return;
    }

    $paymentIntentId = annualPaymentIntentId($object);

    // checkout.session.completed and async_payment_succeeded can represent
    // the same PaymentIntent. Do not extend membership twice.
    if (annualPaymentAlreadyRecorded($pdo, $paymentIntentId)) {
        $stmt = $pdo->prepare(
            'UPDATE students
             SET stripe_customer_id = COALESCE(?, stripe_customer_id),
                 stripe_checkout_session_id = COALESCE(?, stripe_checkout_session_id),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([
            $object['customer'] ?? null,
            $object['id'] ?? null,
            $studentId,
        ]);
        return;
    }

    $stateStmt = $pdo->prepare(
        'SELECT membership_next_due_at
         FROM students
         WHERE id = ?
         FOR UPDATE'
    );
    $stateStmt->execute([$studentId]);
    $currentExpiry = $stateStmt->fetchColumn();

    $paidTimestamp = $eventCreated > 0
        ? $eventCreated
        : (int) ($object['created'] ?? time());

    $paidAt = stripeDateTime($paidTimestamp);
    $newExpiry = annualMembershipExpiry(
        is_string($currentExpiry) && $currentExpiry !== '' ? $currentExpiry : null,
        $paidTimestamp
    );

    $stmt = $pdo->prepare(
        "UPDATE students
         SET membership_status = 'active',
             registration_fee_paid_at = COALESCE(registration_fee_paid_at, ?),
             membership_activation_paid_at = COALESCE(membership_activation_paid_at, ?),
             one_time_fees_paid_at = COALESCE(one_time_fees_paid_at, ?),
             membership_next_due_at = ?,
             stripe_customer_id = COALESCE(?, stripe_customer_id),
             stripe_checkout_session_id = COALESCE(?, stripe_checkout_session_id),
             stripe_subscription_id = NULL,
             stripe_subscription_status = 'annual',
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->execute([
        $paidAt,
        $paidAt,
        $paidAt,
        $newExpiry,
        $object['customer'] ?? null,
        $object['id'] ?? null,
        $studentId,
    ]);

    annualInsertOrUpdatePayment($pdo, [
        $studentId,
        $eventId,
        null,
        $paymentIntentId,
        'annual_membership',
        'Student membership - 1 year',
        (int) ($object['amount_total'] ?? ANNUAL_MEMBERSHIP_FEE),
        strtolower((string) ($object['currency'] ?? CURRENCY)),
        'paid',
        $paidAt,
    ]);
}

function recordAnnualMembershipFailure(PDO $pdo, array $object, string $eventId): void
{
    $studentId = annualFindStudentId($pdo, $object);
    if ($studentId <= 0) {
        return;
    }

    $stateStmt = $pdo->prepare(
        'SELECT membership_status, membership_next_due_at
         FROM students
         WHERE id = ?'
    );
    $stateStmt->execute([$studentId]);
    $state = $stateStmt->fetch() ?: [];

    $expiry = !empty($state['membership_next_due_at'])
        ? strtotime((string) $state['membership_next_due_at'] . ' UTC')
        : false;

    $status = ($expiry !== false && $expiry >= time())
        ? 'active'
        : 'pending_activation';

    $stmt = $pdo->prepare(
        'UPDATE students
         SET membership_status = ?,
             stripe_checkout_session_id = COALESCE(?, stripe_checkout_session_id),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->execute([
        $status,
        $object['id'] ?? null,
        $studentId,
    ]);

    annualInsertOrUpdatePayment($pdo, [
        $studentId,
        $eventId,
        null,
        annualPaymentIntentId($object),
        'annual_membership',
        'Student membership - 1 year payment failed',
        (int) ($object['amount_total'] ?? ANNUAL_MEMBERSHIP_FEE),
        strtolower((string) ($object['currency'] ?? CURRENCY)),
        'failed',
        null,
    ]);
}

$object = $event->data->object->toArray();

try {
    $pdo->beginTransaction();

    try {
        $eventStmt = $pdo->prepare(
            'INSERT INTO stripe_events (stripe_event_id, event_type)
             VALUES (?, ?)'
        );
        $eventStmt->execute([$event->id, $event->type]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $pdo->rollBack();
            http_response_code(200);
            echo json_encode(['received' => true, 'duplicate' => true]);
            exit;
        }
        throw $e;
    }

    switch ($event->type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $stage = (string) ($object['metadata']['payment_stage'] ?? '');
            $isPaid = ($object['payment_status'] ?? '') === 'paid';

            if ($stage === 'annual_membership' && $isPaid) {
                completeAnnualMembershipPayment(
                    $pdo,
                    $object,
                    $event->id,
                    (int) $event->created
                );
            }
            break;

        case 'checkout.session.async_payment_failed':
            if ((string) ($object['metadata']['payment_stage'] ?? '') === 'annual_membership') {
                recordAnnualMembershipFailure($pdo, $object, $event->id);
            }
            break;

        /*
         * Legacy monthly invoice/subscription events are intentionally ignored.
         * Existing subscriptions must be cancelled in Stripe separately to stop
         * future charges.
         */
        case 'invoice.paid':
        case 'invoice.payment_failed':
        case 'invoice.payment_action_required':
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            break;
    }

    $pdo->commit();
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Stripe webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Webhook processing failed.']);
}
