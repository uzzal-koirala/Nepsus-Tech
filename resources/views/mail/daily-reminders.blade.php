<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Reminders') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f3f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f9;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
            {{-- Header --}}
            <tr><td style="background:#4f46e5;background-image:linear-gradient(135deg,#3b5bfd,#7c3aed);border-radius:14px 14px 0 0;padding:26px 28px;color:#ffffff;">
                <p style="margin:0;font-size:13px;opacity:.8;">{{ \App\Models\Setting::get('business_name') }}</p>
                <p style="margin:4px 0 0;font-size:24px;font-weight:700;letter-spacing:-.01em;">{{ __('Good morning') }}</p>
                <p style="margin:4px 0 0;font-size:14px;opacity:.85;">{{ today()->toNepali() }} · {{ today()->format('l, j F Y') }}</p>
            </td></tr>

            <tr><td style="background:#ffffff;border-radius:0 0 14px 14px;padding:8px 28px 24px;">
                @forelse ($sections as $section)
                    <p style="margin:22px 0 8px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $section['color'] }};">
                        {{ $section['title'] }} · {{ count($section['items']) }}
                    </p>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        @foreach ($section['items'] as $item)
                            <tr><td style="padding:3px 0;">
                                <a href="{{ $item['url'] }}" style="display:block;text-decoration:none;color:#18181b;background:#f8f9fc;border-left:3px solid {{ $section['color'] }};border-radius:8px;padding:10px 14px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                                        <td style="font-size:14px;">
                                            <span style="font-weight:600;">{{ $item['title'] }}</span><br>
                                            <span style="font-size:12px;color:#71717a;">{{ $item['meta'] }}</span>
                                        </td>
                                        <td align="right" style="font-size:12px;font-weight:600;white-space:nowrap;color:{{ str_contains($item['when'], 'late') ? '#e11d48' : '#52525b' }};">{{ $item['when'] }}</td>
                                    </tr></table>
                                </a>
                            </td></tr>
                        @endforeach
                    </table>
                @empty
                    <p style="margin:24px 0 4px;font-size:15px;font-weight:600;">{{ __('Nothing due today or tomorrow.') }}</p>
                    <p style="margin:0;font-size:13px;color:#71717a;">{{ __('Enjoy a calm day.') }}</p>
                @endforelse

                <p style="margin:26px 0 0;text-align:center;">
                    <a href="{{ route('dashboard') }}" style="display:inline-block;background:#3b5bfd;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:9px;">{{ __('Open the dashboard') }}</a>
                </p>
            </td></tr>

            <tr><td style="padding:16px;text-align:center;font-size:11px;color:#a1a1aa;">
                {{ __('Sent every morning. Change who gets it in Settings → Email.') }}
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
