<?php
require_once 'config.php';
$pageTitle = 'QR Attendance Scanner';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h2 mb-1">QR Attendance Scanner</h1>
        <p class="text-secondary mb-0">Scan once to check in and scan again to check out.</p>
    </div>
    <a href="attendance.php" class="btn btn-outline-primary"><i class="bi bi-clock-history me-2"></i>Attendance Report</a>
</div>

<div class="alert alert-info">
    <i class="bi bi-shield-lock me-2"></i>Camera access requires HTTPS on a live website. Allow camera permission when prompted.
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card content-card h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><i class="bi bi-camera me-2"></i>Open Camera</h2>
                <div id="reader"></div>
                <div class="small text-secondary mt-3">Use the rear camera on a phone or tablet for faster scanning.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card mb-4">
            <div class="card-body p-4 scanner-result" id="scan-result">
                <div class="text-center text-secondary py-3">
                    <i class="bi bi-qr-code-scan display-4 d-block mb-2"></i>
                    Waiting for a student QR code.
                </div>
            </div>
        </div>

        <div class="card content-card">
            <div class="card-body p-4">
                <h2 class="h6">Manual QR Token</h2>
                <p class="small text-secondary">Use this only when the camera is unavailable.</p>
                <form id="manual-form" class="d-flex gap-2">
                    <input id="manual-token" class="form-control" autocomplete="off" placeholder="Paste QR token" required>
                    <button class="btn btn-primary" type="submit">Submit</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
const resultBox = document.getElementById('scan-result');
let processing = false;
let scanner;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"`=\/]/g, function (character) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;','`':'&#96;','=':'&#61;','/':'&#47;'}[character];
    });
}

function minutesLabel(minutes) {
    const value = Number(minutes || 0);
    const hours = Math.floor(value / 60);
    const mins = value % 60;
    if (hours && mins) return `${hours}h ${mins}m`;
    if (hours) return `${hours}h`;
    return `${mins}m`;
}

function showResult(data) {
    const success = Boolean(data.success);
    const duplicate = data.result_type === 'duplicate';
    const alertClass = duplicate ? 'warning' : (success ? 'success' : 'danger');
    const icon = duplicate ? 'bi-exclamation-circle' : (success ? 'bi-check-circle' : 'bi-x-circle');
    const student = data.student || {};
    const action = data.action === 'checked_in' ? 'CHECKED IN' : (data.action === 'checked_out' ? 'CHECKED OUT' : 'NOT RECORDED');
    const usage = data.used_minutes !== undefined
        ? `<div class="mt-3"><div class="small text-secondary">Monthly usage</div><div class="fw-semibold">${minutesLabel(data.used_minutes)} used · ${minutesLabel(data.remaining_minutes)} remaining</div></div>`
        : '';
    const duration = data.actual_minutes !== undefined
        ? `<div class="small mt-2">Session: ${minutesLabel(data.actual_minutes)} (${minutesLabel(data.credited_minutes)} credited)</div>`
        : '';

    resultBox.innerHTML = `
        <div class="alert alert-${alertClass} mb-0">
            <div class="d-flex gap-3 align-items-start">
                <i class="bi ${icon} fs-2"></i>
                <div>
                    <div class="small fw-semibold">${action}</div>
                    <div class="h5 mb-1">${escapeHtml(student.full_name || 'Attendance result')}</div>
                    ${student.school_name ? `<div class="small">${escapeHtml(student.school_name)}</div>` : ''}
                    <div class="mt-2">${escapeHtml(data.message)}</div>
                    ${duration}${usage}
                    ${data.time ? `<div class="small mt-2 opacity-75">${escapeHtml(data.time)}</div>` : ''}
                </div>
            </div>
        </div>`;
}

async function submitScan(qrValue, scanMethod = 'qr_camera') {
    if (processing) return;
    processing = true;
    try {
        if (scanner && typeof scanner.pause === 'function') {
            try { scanner.pause(true); } catch (e) {}
        }
        const response = await fetch('attendance_scan.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({qr_value: qrValue, scan_method: scanMethod, csrf_token: csrfToken})
        });
        const data = await response.json();
        showResult(data);
    } catch (error) {
        showResult({success: false, message: 'Unable to contact the attendance server.'});
    } finally {
        setTimeout(() => {
            processing = false;
            if (scanner && typeof scanner.resume === 'function') {
                try { scanner.resume(); } catch (e) {}
            }
        }, 3000);
    }
}

function onScanSuccess(decodedText) {
    submitScan(decodedText, 'qr_camera');
}

scanner = new Html5QrcodeScanner('reader', {
    fps: 10,
    qrbox: {width: 260, height: 260},
    rememberLastUsedCamera: true,
    supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
}, false);
scanner.render(onScanSuccess, () => {});

document.getElementById('manual-form').addEventListener('submit', function (event) {
    event.preventDefault();
    const input = document.getElementById('manual-token');
    submitScan(input.value.trim(), 'manual');
    input.value = '';
});
</script>
<?php require 'includes/footer.php'; ?>
