<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Save It Operational Report</title>
    <style>
        @media only screen and (max-width:600px) {
            .report-gutter { padding:12px !important; }
            .report-card { padding:20px !important; }
            .report-kpi { font-size:24px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f2f5f9; color:#223047; font-family:Arial,Helvetica,sans-serif; -webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f2f5f9" style="width:100%; background:#f2f5f9;">
<tr><td class="report-gutter" align="center" style="padding:28px 16px;">
<!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; max-width:640px; table-layout:fixed;">
    <tr><td class="report-card" bgcolor="#11233f" style="padding:32px; background:#11233f; border-radius:16px; color:#ffffff;">
        <p style="margin:0 0 16px; font-size:14px; font-weight:bold; letter-spacing:2px; color:#a8bcff;">SAVE IT</p>
        <h1 style="margin:0 0 12px; font-size:28px; line-height:36px; font-weight:bold; color:#ffffff;">Operational Report</h1>
        <p style="margin:0 0 16px; color:#d6e0f0; font-size:16px; line-height:24px;">{{ $report['period'] }}</p>
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="{{ $report['warning'] ? '#ffedd5' : '#dcfce7' }}" style="padding:7px 12px; border-radius:20px; font-size:12px; font-weight:bold; color:{{ $report['warning'] ? '#92400e' : '#166534' }};">{{ $report['status'] }}</td></tr></table>
        <p style="margin:18px 0 0; font-size:12px; line-height:20px; color:#c0cee2;">{{ $report['period_start'] }} – {{ $report['period_end'] }}<br>{{ $report['timezone'] }} · Hourly aggregate boundaries<br>Generated {{ $report['generated'] }}</p>
    </td></tr>
    <tr><td height="16" style="font-size:0; line-height:16px;">&nbsp;</td></tr>
    <tr><td class="report-card" bgcolor="#ffffff" style="padding:28px; border:1px solid #e3e9f1; border-radius:16px; background:#ffffff;">
        <h2 style="margin:0 0 8px; font-size:19px; color:#11233f;">Executive summary</h2>
        <p style="margin:0 0 18px; color:#68758a; font-size:13px; line-height:20px;">Recorded application operations, not unique visitors or total HTTP requests. Errors may include invalid input and controlled provider failures; this is not an uptime check.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; table-layout:fixed;">
        @foreach(array_chunk([
            ['Recorded operations', number_format($report['total'])],
            ['Success rate', $report['success_rate']],
            ['Successes', number_format($report['success'])],
            ['Errors', number_format($report['errors_count'])],
            ['Analyses', number_format($report['analyses'])],
            ['Downloads', number_format($report['downloads'])],
            ['Share preparations', number_format($report['share_preparations'])],
            ['Rate-limit hits', number_format($report['rate_limits'])],
        ], 2) as $pair)
        <tr>@foreach($pair as [$label, $value])<td width="50%" valign="top" style="padding:12px 8px; border-bottom:1px solid #edf1f5;"><p style="margin:0 0 6px; color:#68758a; font-size:12px; line-height:18px;">{{ $label }}</p><p class="report-kpi" style="margin:0; color:#11233f; font-size:28px; font-weight:bold; line-height:34px; overflow-wrap:anywhere;">{{ $value }}</p></td>@endforeach</tr>
        @endforeach
        </table>
    </td></tr>
    @foreach([
        ['Provider usage', $report['providers'], 'Share of recorded operations by provider.'],
        ['Activity', $report['activity'], 'Existing hourly aggregates grouped into '.(str_starts_with($report['period'], 'Weekly') ? 'calendar days' : 'six-hour windows').'; edge windows may be partial.'],
        ['Top error codes', $report['errors'], 'Most frequent controlled errors in this period.'],
        ['Top countries', $report['countries'], 'Aggregate country codes; ZZ means the country was unknown.'],
    ] as [$title, $items, $description])
    <tr><td height="16" style="font-size:0; line-height:16px;">&nbsp;</td></tr>
    <tr><td class="report-card" bgcolor="#ffffff" style="padding:28px; border:1px solid #e3e9f1; border-radius:16px; background:#ffffff;">
        <h2 style="margin:0 0 8px; color:#11233f; font-size:19px;">{{ $title }}</h2>
        <p style="margin:0 0 8px; color:#68758a; font-size:13px; line-height:20px;">{{ $description }}</p>
        @if($title === 'Top error codes' && count($items) === 0)
            <p style="margin:16px 0 0; padding:14px; border-radius:8px; background:#effbf3; color:#166534; font-size:14px;">No errors recorded in this period.</p>
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;">@include('mail.partials.report-bars', ['items' => $items, 'barColor' => $title === 'Top error codes' ? '#d38a38' : '#4f6fe8'])</table>
        @endif
    </td></tr>
    @endforeach
    <tr><td height="16" style="font-size:0; line-height:16px;">&nbsp;</td></tr>
    <tr><td class="report-card" bgcolor="#ffffff" style="padding:28px; border:1px solid #e3e9f1; border-radius:16px; background:#ffffff;">
        <h2 style="margin:0 0 8px; color:#11233f; font-size:19px;">System &amp; operations</h2>
        <p style="margin:0 0 16px; color:#68758a; font-size:13px; line-height:20px;">Current snapshot at generation time, independent of the reporting period.</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="width:100%; font-size:14px; border-collapse:collapse;">
            <tr><th scope="row" align="left" style="padding:10px 0; font-weight:normal;">Queue depth</th><td align="right" style="padding:10px 0; font-weight:bold;">{{ number_format($report['queue_depth']) }}</td></tr>
            <tr><th scope="row" align="left" style="padding:10px 0; font-weight:normal;">Failed jobs</th><td align="right" style="padding:10px 0; font-weight:bold; color:{{ $report['failed_jobs'] > 0 ? '#92400e' : '#166534' }};">{{ number_format($report['failed_jobs']) }}</td></tr>
            @foreach($report['storage'] as $item)
            <tr><th scope="row" align="left" style="padding:12px 4px 12px 0; font-weight:normal; border-top:1px solid #edf1f5;">{{ $item['label'] }}</th><td align="right" style="padding:12px 0; border-top:1px solid #edf1f5; white-space:nowrap;">{{ $item['size'] }}<br><span style="font-size:12px; color:#68758a;">{{ number_format($item['count']) }} files</span></td></tr>
            @endforeach
            <tr><th scope="row" align="left" style="padding:12px 0; border-top:1px solid #e3e9f1;">Total runtime storage</th><td align="right" style="padding:12px 0; border-top:1px solid #e3e9f1; font-weight:bold;">{{ $report['total_storage'] }}<br><span style="font-size:12px; font-weight:normal; color:#68758a;">{{ number_format($report['total_files']) }} files</span></td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:24px 16px; color:#68758a; font-size:12px; line-height:20px; text-align:center;">
        <strong style="color:#34425a;">Save It · Privacy-first reporting</strong><br>
        Aggregate metrics only. Analytics do not retain raw visitor IP addresses, submitted URLs, media URLs or tokens. Historical unknown country records stay unknown.
    </td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
