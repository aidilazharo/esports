<?php
declare(strict_types=1);

require_once __DIR__ . '/stripe_helpers.php';
require_once __DIR__ . '/events-competitions-helpers.php';

header('Content-Type: application/json; charset=utf-8');

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$programmeWebhookSecret = trim((string) (getenv('PROGRAMME_STRIPE_WEBHOOK_SECRET') ?: ''));

if ($programmeWebhookSecret === '') {
    $programmeWebhookSecret = defined('STRIPE_WEBHOOK_SECRET')
        ? (string) STRIPE_WEBHOOK_SECRET
        : '';
}

if ($programmeWebhookSecret === '' || str_contains($programmeWebhookSecret, 'replace_me')) {
    http_response_code(500);
    echo json_encode(['error' => 'Events & Competitions Stripe webhook secret is not configured.']);
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
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $signature,
        $programmeWebhookSecret
    );
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Stripe webhook request.']);
    exit;
}

$object = $event->data->object->toArray();
$stage = (string) ($object['metadata']['payment_stage'] ?? '');

if ($stage !== 'programme_entry') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$applicationId = (int) (
    $object['metadata']['programme_application_id']
    ?? $object['client_reference_id']
    ?? 0
);

if ($applicationId <= 0) {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

try {
    switch ($event->type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            if (($object['payment_status'] ?? '') === 'paid') {
                $paymentIntent = $object['payment_intent'] ?? null;
                if (is_array($paymentIntent)) {
                    $paymentIntent = $paymentIntent['id'] ?? null;
                }

                $stmt = $pdo->prepare(
                    "UPDATE programme_applications
                     SET payment_status = 'paid',
                         fee_paid_minor = ?,
                         stripe_checkout_session_id = ?,
                         stripe_payment_intent_id = ?,
                         paid_at = COALESCE(paid_at, NOW()),
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([
                    (int) ($object['amount_total'] ?? 0),
                    (string) ($object['id'] ?? ''),
                    is_string($paymentIntent) ? $paymentIntent : null,
                    $applicationId,
                ]);
            }
            break;

        case 'checkout.session.async_payment_failed':
            $stmt = $pdo->prepare(
                "UPDATE programme_applications
                 SET payment_status = 'unpaid',
                     updated_at = NOW()
                 WHERE id = ?
                   AND payment_status <> 'paid'"
            );
            $stmt->execute([$applicationId]);
            break;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    error_log('Events & Competitions Stripe webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Events & Competitions webhook processing failed.']);
}
