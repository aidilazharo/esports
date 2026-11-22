<?php
require_once __DIR__ . '/stripe_helpers.php';
require_once __DIR__ . '/events-competitions-helpers.php';

$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$application = null;
$error = null;
$isPaid = false;

if ($sessionId === '') {
    $error = 'Missing Stripe Checkout session.';
} else {
    try {
        $session = stripeClient()->checkout->sessions->retrieve($sessionId, []);
        $stage = (string) ($session->metadata->payment_stage ?? '');
        $applicationId = (int) ($session->metadata->programme_application_id ?? $session->client_reference_id ?? 0);

        if ($stage !== 'programme_entry' || $applicationId <= 0) {
            throw new RuntimeException('This Stripe payment is not an Events & Competitions payment.');
        }

        $stmt = $pdo->prepare(
            'SELECT
                a.*,
                e.title,
                e.event_code,
                e.currency
             FROM programme_applications a
             JOIN programme_events e ON e.id = a.event_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();

        if (!$application) {
            throw new RuntimeException('Event / competition application was not found.');
        }

        $isPaid = ($session->payment_status ?? '') === 'paid';

        if ($isPaid) {
            $amountPaid = (int) ($session->amount_total ?? $application['fee_paid_minor'] ?? 0);
            $paymentIntentId = '';

            if (is_string($session->payment_intent ?? null)) {
                $paymentIntentId = (string) $session->payment_intent;
            } elseif (!empty($session->payment_intent->id)) {
                $paymentIntentId = (string) $session->payment_intent->id;
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
                $amountPaid,
                $sessionId,
                $paymentIntentId ?: null,
                $applicationId,
            ]);

            $application['payment_status'] = 'paid';
            $application['fee_paid_minor'] = $amountPaid;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Events & Competitions Payment';
require 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <h1 class="h4">Payment confirmation error</h1>
                <p class="mb-0"><?= e($error) ?></p>
            </div>
        <?php elseif ($application && $isPaid): ?>
            <div class="card content-card border-success">
                <div class="card-body p-5 text-center">
                    <i class="bi bi-check-circle-fill text-success display-3"></i>
                    <h1 class="h3 mt-3">Payment Successful</h1>
                    <p class="text-secondary">
                        Your Events &amp; Competitions application fee has been received.
                    </p>

                    <div class="border rounded p-3 text-start my-4">
                        <div><strong>Application:</strong> <?= e($application['application_code']) ?></div>
                        <div><strong>Event / Competition:</strong> <?= e($application['title']) ?></div>
                        <div><strong>Event ID:</strong> <?= e($application['event_code']) ?></div>
                        <div><strong>Paid:</strong> <?= e(pmMoney((int) $application['fee_paid_minor'], (string) $application['currency'])) ?></div>
                    </div>

                    <a
                        class="btn btn-primary"
                        href="events-competitions.php?application=<?= rawurlencode((string) $application['application_code']) ?>&token=<?= rawurlencode((string) $application['withdraw_token']) ?>&joined=1"
                    >
                        View Application
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <h1 class="h4">Payment processing</h1>
                <p class="mb-0">
                    Stripe has not marked this Checkout session as paid yet. Payment status will also be updated by the Events &amp; Competitions Stripe webhook.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
