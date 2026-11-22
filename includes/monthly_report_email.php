<?php
declare(strict_types=1);

function monthlyReportEsc(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function monthlyReportBaseUrl(): string
{
    if (defined('APP_URL')) {
        return rtrim((string)APP_URL, '/');
    }

    $url = trim(
        (string)(
            getenv('REPORT_BASE_URL')
            ?: 'https://esports.metadata.technology'
        )
    );

    return rtrim($url, '/');
}

function monthlyReportLogoUrl(?string $override = null): string
{
    if ($override !== null && trim($override) !== '') {
        return trim($override);
    }

    static $embeddedLogo = null;

    if ($embeddedLogo !== null) {
        return $embeddedLogo;
    }

    $logoPath = dirname(__DIR__)
        . '/assets/images/logo-email.png';

    if (!is_file($logoPath) || !is_readable($logoPath)) {
        $embeddedLogo = monthlyReportBaseUrl()
            . '/assets/images/logo-email.png';

        return $embeddedLogo;
    }

    $logoBytes = file_get_contents($logoPath);

    if ($logoBytes === false || $logoBytes === '') {
        $embeddedLogo = monthlyReportBaseUrl()
            . '/assets/images/logo-email.png';

        return $embeddedLogo;
    }

    $embeddedLogo =
        'data:image/png;base64,'
        . base64_encode($logoBytes);

    return $embeddedLogo;
}

function monthlyReportIconUrl(
    string $iconName
): string {
    return monthlyReportBaseUrl()
        . '/assets/images/email-icons/'
        . rawurlencode($iconName)
        . '.png';
}

function monthlyReportWhatsappQr(): string
{
    static $embeddedQr = null;

    if ($embeddedQr !== null) {
        return $embeddedQr;
    }

    $qrPath = dirname(__DIR__)
        . '/assets/images/whatsapp-group-qr.png';

    if (!is_file($qrPath) || !is_readable($qrPath)) {
        return '';
    }

    $qrBytes = file_get_contents($qrPath);

    if ($qrBytes === false || $qrBytes === '') {
        return '';
    }

    $embeddedQr =
        'data:image/png;base64,'
        . base64_encode($qrBytes);

    return $embeddedQr;
}

function monthlyReportMetricIcon(
    string $label
): string {
    $key = strtolower($label);

    $map = [
        'school' => 'school',
        'student' => 'people',
        'active' => 'active',
        'teacher' => 'teacher',
        'parent' => 'family',
        'guardian' => 'family',
        'new ' => 'new',
        'attendance' => 'calendar',
        'module' => 'book',
        'certificate' => 'medal',
        'payment' => 'payment',
        'event' => 'trophy',
        'competition' => 'trophy',
        'tournament' => 'trophy',
        'children' => 'child',
        'scope' => 'map',
    ];

    foreach ($map as $needle => $iconName) {
        if (str_contains($key, $needle)) {
            return $iconName;
        }
    }

    return 'chart';
}

function monthlyReportMetricTable(array $metrics): string
{
    $cells = [];

    foreach ($metrics as $metric) {
        $labelRaw = (string)($metric['label'] ?? '');
        $label = monthlyReportEsc($labelRaw);
        $value = monthlyReportEsc(
            (string)($metric['value'] ?? '0')
        );
        $note = trim(
            (string)($metric['note'] ?? '')
        );
        $iconName = monthlyReportMetricIcon($labelRaw);
        $iconUrl = monthlyReportEsc(
            monthlyReportIconUrl($iconName)
        );

        $pillHtml = $note !== ''
            ? '<div style="margin-top:9px;">'
                . '<span style="display:inline-block;background:#eef4ff;'
                . 'color:#0d6efd;border:1px solid #d8e7ff;'
                . 'border-radius:999px;padding:4px 8px;'
                . 'font-size:10px;font-weight:700;line-height:1.2;">'
                . monthlyReportEsc($note)
                . '</span>'
                . '</div>'
            : '';

        $cells[] =
            '<td width="50%" valign="top" style="padding:6px;">'
            . '<div style="border:1px solid #e5e7eb;border-radius:14px;'
            . 'background:#ffffff;padding:15px;min-height:96px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
            . '<tr>'
            . '<td width="42" valign="top">'
            . '<div style="width:34px;height:34px;text-align:center;">'
            . '<img src="' . $iconUrl . '" width="26" height="26" alt="" '
            . 'style="display:block;width:26px;height:26px;border:0;margin:4px auto 0;">'
            . '</div>'
            . '</td>'
            . '<td valign="top">'
            . '<div style="font-size:11px;line-height:1.35;color:#6b7280;'
            . 'text-transform:uppercase;letter-spacing:.3px;font-weight:700;">'
            . $label
            . '</div>'
            . '<div style="font-size:25px;line-height:1.15;font-weight:700;'
            . 'color:#111827;margin-top:5px;">'
            . $value
            . '</div>'
            . $pillHtml
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</div>'
            . '</td>';
    }

    if (count($cells) % 2 !== 0) {
        $cells[] = '<td width="50%" style="padding:6px;"></td>';
    }

    $rows = '';

    for ($i = 0; $i < count($cells); $i += 2) {
        $rows .= '<tr>'
            . $cells[$i]
            . $cells[$i + 1]
            . '</tr>';
    }

    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
        . $rows
        . '</table>';
}

function monthlyReportDetailsTable(
    string $title,
    array $rows
): string {
    if (!$rows) {
        return '';
    }

    $body = '';

    foreach ($rows as $row) {
        $left = monthlyReportEsc(
            (string)($row['left'] ?? '')
        );

        $right = monthlyReportEsc(
            (string)($row['right'] ?? '')
        );

        $sub = trim(
            (string)($row['sub'] ?? '')
        );

        $subHtml = $sub !== ''
            ? '<div style="font-size:11px;color:#6b7280;margin-top:3px;">'
                . monthlyReportEsc($sub)
                . '</div>'
            : '';

        $body .=
            '<tr>'
            . '<td style="padding:11px 0;border-bottom:1px solid #eef0f3;'
            . 'font-size:13px;color:#111827;">'
            . '<strong>'
            . $left
            . '</strong>'
            . $subHtml
            . '</td>'
            . '<td align="right" style="padding:11px 0;border-bottom:1px solid #eef0f3;">'
            . '<span style="display:inline-block;background:#f3f4f6;color:#374151;'
            . 'border:1px solid #e5e7eb;border-radius:999px;padding:5px 9px;'
            . 'font-size:10px;font-weight:700;white-space:nowrap;">'
            . $right
            . '</span>'
            . '</td>'
            . '</tr>';
    }

    return
        '<div style="margin-top:18px;border:1px solid #e5e7eb;border-radius:14px;'
        . 'background:#ffffff;padding:18px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
        . '<tr><td>'
        . '<div style="font-size:14px;font-weight:700;color:#111827;">'
        . monthlyReportEsc($title)
        . '</div>'
        . '</td><td align="right">'
        . '<span style="display:inline-block;background:#eef6ff;color:#0d6efd;'
        . 'border:1px solid #d7e8ff;border-radius:999px;padding:4px 8px;'
        . 'font-size:10px;font-weight:700;">'
        . count($rows)
        . ' shown'
        . '</span>'
        . '</td></tr>'
        . '</table>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:6px;">'
        . $body
        . '</table>'
        . '</div>';
}

function monthlyReportBuildEmail(
    string $role,
    string $recipientName,
    string $periodLabel,
    array $metrics,
    string $highlightTitle,
    string $highlightHtml,
    string $portalPath,
    array $detailRows = [],
    string $detailTitle = '',
    ?string $logoOverride = null
): array {
    $roleKey = strtolower(trim($role));

    $roleLabels = [
        'school' => 'School',
        'teacher' => 'Teacher',
        'parent' => 'Parent / Guardian',
        'jpn' => 'JPN',
    ];

    $roleLabel = $roleLabels[$roleKey]
        ?? ucfirst($roleKey);

    $roleIcons = [
        'school' => 'school',
        'teacher' => 'teacher',
        'parent' => 'family',
        'jpn' => 'school',
    ];

    $roleIconName = $roleIcons[$roleKey]
        ?? 'chart';

    $roleIconUrl = monthlyReportEsc(
        monthlyReportIconUrl($roleIconName)
    );

    $safeName = monthlyReportEsc(
        $recipientName !== ''
            ? $recipientName
            : $roleLabel
    );

    $safePeriod = monthlyReportEsc($periodLabel);
    $safeRole = monthlyReportEsc($roleLabel);
    $safeHighlightTitle =
        monthlyReportEsc($highlightTitle);

    $calendarIconUrl = monthlyReportEsc(
        monthlyReportIconUrl('calendar')
    );

    $infoIconUrl = monthlyReportEsc(
        monthlyReportIconUrl('info')
    );

    $logo = monthlyReportEsc(
        monthlyReportLogoUrl($logoOverride)
    );

    $brandTagline =
        'Empowering Future Talent Through STEM & Esports';

    $safeBrandTagline =
        monthlyReportEsc($brandTagline);

    $whatsappQr =
        monthlyReportEsc(
            monthlyReportWhatsappQr()
        );

    $portalUrl = monthlyReportBaseUrl()
        . '/'
        . ltrim($portalPath, '/');

    $safePortalUrl =
        monthlyReportEsc($portalUrl);

    $metricsHtml =
        monthlyReportMetricTable($metrics);

    $detailsHtml =
        monthlyReportDetailsTable(
            $detailTitle,
            $detailRows
        );

    $subject =
        'Monthly '
        . $roleLabel
        . ' Dashboard — '
        . $periodLabel;

    $html = <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f5f9;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:680px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">
<tr>
<td style="background:#0d6efd;padding:18px 26px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td valign="middle">
<img src="{$logo}" width="104" height="40" alt="Metadata Berhad" style="display:block;width:104px;height:40px;object-fit:contain;border:0;">
</td>
<td valign="middle" align="right" style="padding-left:18px;">
<div style="font-size:11px;line-height:1.45;font-weight:700;color:#ffffff;letter-spacing:.2px;text-align:right;">
{$safeBrandTagline}
</div>
</td>
</tr>
</table>
</td>
</tr>

<tr>
<td style="padding:26px 26px 10px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td valign="top" style="padding-right:16px;">
<div style="margin-bottom:10px;">
<span style="display:inline-block;background:#e8f1ff;color:#0d6efd;border:1px solid #d6e6ff;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;">
<img src="{$roleIconUrl}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;border:0;vertical-align:-2px;margin-right:5px;">{$safeRole}
</span>
&nbsp;
<span style="display:inline-block;background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;">
<img src="{$calendarIconUrl}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;border:0;vertical-align:-2px;margin-right:5px;">{$safePeriod}
</span>
</div>
<h1 style="font-size:24px;line-height:1.25;margin:8px 0 6px;color:#111827;">
Monthly Dashboard
</h1>
<p style="font-size:14px;line-height:1.6;color:#6b7280;margin:0;">
Hello <strong style="color:#111827;">{$safeName}</strong>. Here is your monthly programme dashboard.
</p>
</td>
<td width="92" valign="top" align="right">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="right">
<tr>
<td align="center" style="border:1px solid #e5e7eb;border-radius:10px;padding:6px;background:#ffffff;">
<img src="{$whatsappQr}" width="72" height="72" alt="WhatsApp Group QR" style="display:block;width:72px;height:72px;border:0;">
</td>
</tr>
<tr>
<td align="center" style="padding-top:5px;font-size:9px;line-height:1.3;color:#6b7280;font-weight:700;">
WhatsApp Group
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>

<tr>
<td style="padding:6px 20px 4px;">
{$metricsHtml}
</td>
</tr>

<tr>
<td style="padding:12px 26px 4px;">
<div style="background:#eef6ff;border:1px solid #d7e8ff;border-radius:14px;padding:16px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td width="42" valign="top">
<div style="width:34px;height:34px;text-align:center;">
<img src="{$infoIconUrl}" width="26" height="26" alt="" style="display:block;width:26px;height:26px;border:0;margin:4px auto 0;">
</div>
</td>
<td valign="top">
<div style="margin-bottom:7px;">
<span style="display:inline-block;background:#ffffff;color:#0d6efd;border:1px solid #d7e8ff;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:700;">HIGHLIGHT</span>
</div>
<div style="font-size:13px;font-weight:700;color:#0d6efd;margin-bottom:5px;">
{$safeHighlightTitle}
</div>
<div style="font-size:13px;line-height:1.65;color:#334155;">
{$highlightHtml}
</div>
</td>
</tr>
</table>
</div>

{$detailsHtml}
</td>
</tr>

<tr>
<td align="center" style="padding:24px 26px 28px;">
<a href="{$safePortalUrl}" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 20px;border-radius:9px;">
Open {$safeRole} Portal
</a>
</td>
</tr>

<tr>
<td style="background:#f8fafc;border-top:1px solid #eef0f3;padding:18px 26px;text-align:center;">
<div style="font-size:11px;line-height:1.6;color:#6b7280;">
This is an automated monthly programme report from Metadata Berhad.<br>
Each account receives this dashboard no more than once per calendar month.
</div>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;

    $textLines = [
        'Metadata Berhad',
        $roleLabel . ' Monthly Report',
        $periodLabel . ' Dashboard',
        '',
        'Hello ' . $recipientName . ',',
        '',
    ];

    foreach ($metrics as $metric) {
        $textLines[] =
            (string)($metric['label'] ?? '')
            . ': '
            . (string)($metric['value'] ?? '0');
    }

    $textLines[] = '';
    $textLines[] =
        $highlightTitle
        . ': '
        . trim(
            preg_replace(
                '/\s+/',
                ' ',
                strip_tags($highlightHtml)
            )
        );

    if ($detailRows) {
        $textLines[] = '';
        $textLines[] = $detailTitle;

        foreach ($detailRows as $row) {
            $textLines[] =
                '- '
                . (string)($row['left'] ?? '')
                . ': '
                . (string)($row['right'] ?? '');
        }
    }

    $textLines[] = '';
    $textLines[] = 'Open portal: ' . $portalUrl;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => implode("\n", $textLines),
    ];
}
