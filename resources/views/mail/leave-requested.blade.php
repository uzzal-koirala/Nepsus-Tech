@php
    $r = $request;
    $days = count($r->workingDays());
    $range = \App\Support\NepaliDate::format($r->from_date).($r->to_date->isSameDay($r->from_date) ? '' : ' – '.\App\Support\NepaliDate::format($r->to_date));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Leave request') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f3f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
            <tr><td style="background:#0ea5e9;background-image:linear-gradient(135deg,#0ea5e9,#3b5bfd);border-radius:14px 14px 0 0;padding:26px 28px;color:#ffffff;">
                <p style="margin:0;font-size:13px;opacity:.8;">{{ \App\Models\Setting::get('business_name') }}</p>
                <p style="margin:4px 0 0;font-size:22px;font-weight:700;letter-spacing:-.01em;">{{ __(':name asked for leave', ['name' => $r->employee->name]) }}</p>
                <p style="margin:4px 0 0;font-size:14px;opacity:.85;">{{ $range }} · {{ trans_choice(':count working day|:count working days', $days) }}</p>
            </td></tr>
            <tr><td style="background:#ffffff;border-radius:0 0 14px 14px;padding:24px 28px;">
                <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#0284c7;">{{ __('Reason') }}</p>
                <p style="margin:0;background:#f8f9fc;border-left:3px solid #0ea5e9;border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.5;">{{ $r->reason }}</p>
                <p style="margin:26px 0 0;text-align:center;">
                    <a href="{{ route('employees.index') }}" style="display:inline-block;background:#3b5bfd;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:9px;">{{ __('Approve or reject') }}</a>
                </p>
            </td></tr>
            <tr><td style="padding:16px;text-align:center;font-size:11px;color:#a1a1aa;">
                {{ __('Change who gets these emails in Settings → Email.') }}
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
