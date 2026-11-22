<?php
require_once __DIR__ . '/stripe_helpers.php';
require_once __DIR__ . '/events-competitions-helpers.php';

if (!pmProgrammeParticipationSchemaReady($pdo)) {
    http_response_code(500);
    exit('Events & Competitions database upgrade is required.');
}

$applicationCode = trim((string) ($_GET['application'] ?? ''));
$withdrawToken = trim((string) ($_GET['token'] ?? ''));

if ($applicationCode === '' || $withdrawToken === '') {
    http_response_code(400);
    exit('Invalid Events & Competitions payment link.');
}

$stmt = $pdo->prepare(
    'SELECT
        a.*,
        e.title,
        e.event_code,
        e.entry_fee_minor,
        e.currency,
        s.contact_email AS school_email,
        st.email AS student_email
     FROM programme_applications a
     JOIN programme_events e ON e.id = a.event_id
     LEFT JOIN schools s ON s.id = a.school_id
     LEFT JOIN students st ON st.id = a.student_id
     WHERE a.application_code = ?
       AND a.withdraw_token = ?
     LIMIT 1'
);
$stmt->execute([$applicationCode, $withdrawToken]);
$application = $stmt->fetch();

if (!$application) {
    http_response_code(404);
    exit('Event / competition application was not found.');
}

if ($application['status'] === 'withdrawn') {
    http_response_code(409);
    exit('This application has been withdrawn.');
}

if ($application['payment_status'] === 'paid') {
    redirect(
        'events-competitions.php?application=' . rawurlencode($applicationCode)
        . '&token=' . rawurlencode($withdrawToken)
        . '&joined=1'
    );
}

$entryFeeMinor = (int) $application['entry_fee_minor'];

if (($application['applicant_type'] ?? '') === 'school' && !empty($application['school_id'])) {
    $entryFeeMinor = pmSchoolApplicationFeeMinor(
        $pdo,
        $application,
        (int) $application['school_id']
    );
}

if ($entryFeeMinor <= 0) {
    $stmt = $pdo->prepare(
        "UPDATE programme_applications
         SET payment_status = 'waived',
             fee_paid_minor = 0,
             paid_at = NULL,
             updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([(int) $application['id']]);

    redirect(
        'events-competitions.php?application=' . rawurlencode($applicationCode)
        . '&token=' . rawurlencode($withdrawToken)
        . '&joined=1'
    );
}

try {
    // Reuse an existing open Checkout session where possible.
    if (!empty($application['stripe_checkout_session_id'])) {
        try {
            $existingSession = stripeClient()->checkout->sessions->retrieve(
                (string) $application['stripe_checkout_session_id'],
                []
            );

            if (
                ($existingSession->status ?? '') === 'open'
                && !empty($existingSession->url)
            ) {
                header('Location: ' . $existingSession->url, true, 303);
                exit;
            }
        } catch (Throwable $e) {
            // Create a new Checkout session below.
        }
    }

    $currency = strtolower((string) ($application['currency'] ?: 'MYR'));
    $appUrl = rtrim((string) APP_URL, '/');

    $parameters = [
        'mode' => 'payment',
        'line_items' => [[
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $entryFeeMinor,
                'product_data' => [
                    'name' => mb_substr(
                        (string) $application['title'] . ' - Entry Fee',
                        0,
                        120
                    ),
                ],
            ],
            'quantity' => 1,
        ]],
        'client_reference_id' => (string) $application['id'],
        'metadata' => [
            'payment_stage' => 'programme_entry',
            'programme_application_id' => (string) $application['id'],
            'programme_event_id' => (string) $application['event_id'],
            'application_code' => (string) $application['application_code'],
        ],
        'payment_intent_data' => [
            'metadata' => [
                'payment_stage' => 'programme_entry',
                'programme_application_id' => (string) $application['id'],
                'programme_event_id' => (string) $application['event_id'],
                'application_code' => (string) $application['application_code'],
            ],
        ],
        'success_url' => $appUrl . '/events-competitions-payment-success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $appUrl
            . '/events-competitions.php?application=' . rawurlencode($applicationCode)
            . '&token=' . rawurlencode($withdrawToken)
            . '&payment_cancelled=1',
    ];

    $email = trim((string) ($application['student_email'] ?: $application['school_email'] ?: ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $parameters['customer_email'] = $email;
    }

    $session = stripeClient()->checkout->sessions->create(
        $parameters,
        [
            'idempotency_key' => sprintf(
                'programme-entry-%d-%d-%s',
                (int) $application['id'],
                $entryFeeMinor,
                gmdate('YmdH')
            ),
        ]
    );

    $stmt = $pdo->prepare(
        'UPDATE programme_applications
         SET stripe_checkout_session_id = ?,
             updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        (string) $session->id,
        (int) $application['id'],
    ]);

    header('Location: ' . $session->url, true, 303);
    exit;
} catch (Throwable $e) {
    error_log('Events & Competitions Stripe Checkout error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to start Events & Competitions payment at this time.');
}
