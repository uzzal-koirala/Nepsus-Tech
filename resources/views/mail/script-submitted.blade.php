@php
    $s = $script;
    $type = \App\Models\Script::TYPES[$s->type]['label'] ?? $s->type;
    $words = \App\Models\Script::wordCount((string) $s->body);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('New script to approve') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f3f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
            <tr><td style="background:#d97706;background-image:linear-gradient(135deg,#f59e0b,#7c3aed);border-radius:14px 14px 0 0;padding:26px 28px;color:#ffffff;">
                <p style="margin:0;font-size:13px;opacity:.85;">{{ \App\Models\Setting::get('business_name') }} · {{ __('New script to approve') }}</p>
                <p style="margin:4px 0 0;font-size:22px;font-weight:700;letter-spacing:-.01em;">{{ $s->title }}</p>
                <p style="margin:4px 0 0;font-size:14px;opacity:.9;">
                    {{ __('By :name', ['name' => $by->name]) }} · {{ $type }} · {{ $s->client?->displayName() ?? __('Our own content') }}
                    @if ($s->shoot_date) · {{ __('Shoot :date', ['date' => $s->shoot_date->toNepali()]) }}@endif
                </p>
            </td></tr>
            <tr><td style="background:#ffffff;border-radius:0 0 14px 14px;padding:24px 28px;">
                <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#b45309;">{{ __('Script') }} · {{ trans_choice(':count word|:count words', $words) }}</p>
                <div style="margin:0;background:#f8f9fc;border-left:3px solid #f59e0b;border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.6;white-space:pre-line;">{{ \Illuminate\Support\Str::limit((string) $s->body, 900) }}</div>
                <p style="margin:26px 0 0;text-align:center;">
                    <a href="{{ route('scripts.edit', $s) }}" style="display:inline-block;background:#3b5bfd;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:9px;">{{ __('Approve or send back') }}</a>
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
