<?php
declare(strict_types=1);

$disableStaticHtmlGeneration = true;
$seoRobots = 'noindex,nofollow,noarchive';

function jpnLetterLoadEnv(string $file): void
{
    if (
        !is_file($file)
        || !is_readable($file)
    ) {
        return;
    }

    $lines = file(
        $file,
        FILE_IGNORE_NEW_LINES
        | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if (
            $line === ''
            || str_starts_with($line, '#')
            || !str_contains($line, '=')
        ) {
            continue;
        }

        [$name, $value] =
            explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if (
            $name === ''
            || getenv($name) !== false
        ) {
            continue;
        }

        if (
            strlen($value) >= 2
            && (
                (
                    $value[0] === '"'
                    && $value[
                        strlen($value) - 1
                    ] === '"'
                )
                || (
                    $value[0] === "'"
                    && $value[
                        strlen($value) - 1
                    ] === "'"
                )
            )
        ) {
            $value =
                substr(
                    $value,
                    1,
                    -1
                );
        }

        putenv(
            $name . '=' . $value
        );

        $_ENV[$name] = $value;
    }
}

jpnLetterLoadEnv(
    __DIR__ . '/.env'
);

require_once __DIR__ . '/config.php';
require_once __DIR__
    . '/includes/jpn_programme_registry.php';

const JPN_LETTER_BREVO_ENDPOINT =
    'https://api.brevo.com/v3/smtp/email';

function jpnLetterSendBrevo(
    array $payload,
    string $apiKey
): void {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL extension is required.'
        );
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException(
            'Unable to prepare the email request.'
        );
    }

    $ch = curl_init(
        JPN_LETTER_BREVO_ENDPOINT
    );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
        ]
    );

    $response = curl_exec($ch);

    if ($response === false) {
        $error =
            curl_error($ch);

        curl_close($ch);

        throw new RuntimeException(
            'Unable to send the email: '
            . $error
        );
    }

    $status =
        (int)curl_getinfo(
            $ch,
            CURLINFO_RESPONSE_CODE
        );

    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            'The email service returned HTTP '
            . $status
            . '.'
        );
    }
}

function jpnLetterResolvePdf(
    ?string $relativePath
): ?string {
    $relativePath = trim(
        (string)$relativePath
    );

    if ($relativePath === '') {
        return null;
    }

    $relativePath =
        str_replace(
            '\\',
            '/',
            $relativePath
        );

    if (
        str_starts_with(
            $relativePath,
            '/'
        )
        || str_contains(
            $relativePath,
            '..'
        )
    ) {
        return null;
    }

    $candidate =
        __DIR__
        . '/'
        . ltrim(
            $relativePath,
            '/'
        );

    $real =
        realpath($candidate);

    if (
        $real === false
        || !is_file($real)
        || !is_readable($real)
    ) {
        return null;
    }

    $projectRoot =
        realpath(__DIR__);

    if (
        $projectRoot === false
        || !str_starts_with(
            $real,
            $projectRoot
            . DIRECTORY_SEPARATOR
        )
    ) {
        return null;
    }

    return $real;
}

$jpnCode = strtoupper(
    trim(
        (string)(
            $_GET['jpn']
            ?? $_POST['jpn']
            ?? ''
        )
    )
);

$jpn =
    jpnProgrammeFind(
        $pdo,
        $jpnCode
    );

if (!$jpn) {
    http_response_code(404);
    $pageTitle = 'JPN Approval Letter';
    require 'includes/header.php';

    echo '<div class="alert alert-danger">'
        . 'JPN record not found.'
        . '</div>';

    require 'includes/footer.php';
    exit;
}

$statusMeta =
    jpnProgrammeStatusMeta(
        (string)($jpn['status'] ?? '')
    );

$pdfPath =
    jpnLetterResolvePdf(
        $jpn['letter_pdf_path']
        ?? null
    );

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $email = strtolower(
            trim(
                (string)(
                    $_POST['email']
                    ?? ''
                )
            )
        );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Enter a valid email address.'
            );
        }

        if ($pdfPath === null) {
            throw new RuntimeException(
                'The approval letter for this JPN is not available yet.'
            );
        }

        if (!isset($_SESSION['jpn_letter_sends'])) {
            $_SESSION['jpn_letter_sends'] = [];
        }

        $now = time();

        $_SESSION['jpn_letter_sends'] =
            array_values(
                array_filter(
                    (array)$_SESSION['jpn_letter_sends'],
                    static fn($timestamp): bool =>
                        is_numeric($timestamp)
                        && $now - (int)$timestamp < 600
                )
            );

        if (
            count(
                $_SESSION['jpn_letter_sends']
            ) >= 5
        ) {
            throw new RuntimeException(
                'Too many requests. Please try again later.'
            );
        }

        $apiKey = trim(
            (string)(
                getenv(
                    'BREVO_API_KEY'
                )
                ?: ''
            )
        );

        $senderEmail = trim(
            (string)(
                getenv(
                    'BREVO_SENDER_EMAIL'
                )
                ?: ''
            )
        );

        $senderName = trim(
            (string)(
                getenv(
                    'BREVO_SENDER_NAME'
                )
                ?: 'Metadata Berhad'
            )
        );

        if (
            $apiKey === ''
            || !filter_var(
                $senderEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Email service is not configured.'
            );
        }

        $size = filesize($pdfPath);

        if (
            $size === false
            || $size > 20 * 1024 * 1024
        ) {
            throw new RuntimeException(
                'The PDF letter is unavailable or too large to email.'
            );
        }

        $content =
            file_get_contents(
                $pdfPath
            );

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read the PDF letter.'
            );
        }

        $jpnName =
            (string)$jpn['jpn_name'];

        $fileName =
            'Surat_Kelulusan_Program_'
            . preg_replace(
                '/[^A-Za-z0-9_-]+/',
                '_',
                (string)$jpn['code']
            )
            . '.pdf';

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [[
                'email' => $email,
            ]],
            'subject' =>
                'Surat Kelulusan Program - '
                . $jpnName,
            'htmlContent' =>
                '<p>Salam sejahtera,</p>'
                . '<p>Surat kelulusan PDF berkaitan program untuk <strong>'
                . htmlspecialchars(
                    $jpnName,
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</strong> dilampirkan bersama e-mel ini.</p>'
                . '<p>Terima kasih.</p>'
                . '<p><strong>Metadata Berhad</strong></p>',
            'textContent' =>
                "Salam sejahtera,\n\n"
                . 'Surat kelulusan PDF berkaitan program untuk '
                . $jpnName
                . " dilampirkan bersama e-mel ini.\n\n"
                . "Terima kasih.\nMetadata Berhad",
            'attachment' => [[
                'content' =>
                    base64_encode(
                        $content
                    ),
                'name' =>
                    $fileName,
            ]],
            'tags' => [
                'jpn-programme-letter',
            ],
        ];

        jpnLetterSendBrevo(
            $payload,
            $apiKey
        );

        $_SESSION['jpn_letter_sends'][] =
            $now;

        $successMessage =
            'The PDF letter has been sent to '
            . $email
            . '.';
    } catch (Throwable $e) {
        error_log(
            'JPN letter request error: '
            . $e->getMessage()
        );

        $errorMessage =
            $e->getMessage();
    }
}

$pageTitle =
    'JPN Approval Letter';

require 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-lg-5">
                <div class="mb-4">
                    <div class="small text-uppercase fw-semibold text-primary mb-1">
                        JPN Approval Letter
                    </div>

                    <h1 class="h3 mb-2">
                        <?= e(
                            (string)$jpn['jpn_name']
                        ) ?>
                    </h1>

                    <span
                        class="badge text-bg-<?= e(
                            (string)$statusMeta['badge']
                        ) ?>"
                    >
                        <?= e(
                            (string)$statusMeta['label']
                        ) ?>
                    </span>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= e($successMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <?= e($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($pdfPath === null): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-file-earmark-x me-2"></i>
                        The PDF approval letter for this JPN is not available yet.
                    </div>
                <?php else: ?>
                    <p class="text-secondary">
                        Enter your email address below. The approval letter will be sent to your email as a PDF attachment.
                    </p>

                    <form method="post">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrfToken()) ?>"
                        >

                        <input
                            type="hidden"
                            name="jpn"
                            value="<?= e(
                                (string)$jpn['code']
                            ) ?>"
                        >

                        <div class="mb-3">
                            <label
                                for="email"
                                class="form-label fw-semibold"
                            >
                                Email Address
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                placeholder="name@example.com"
                                required
                                autocomplete="email"
                            >
                        </div>

                        <div class="d-grid">
                            <button
                                class="btn btn-primary"
                                type="submit"
                            >
                                <i class="bi bi-envelope-arrow-up me-2"></i>
                                Send Approval Letter to My Email
                            </button>
                        </div>
                    </form>

                    <div class="small text-secondary mt-3">
                        The approval letter is sent by email and is not exposed as a direct public download.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-3">
            <a
                href="about-programme.php#programme-jpn"
                class="text-decoration-none"
            >
                <i class="bi bi-arrow-left me-1"></i>
                Back to JPN List
            </a>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
