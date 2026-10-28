<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $script->title }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css'])
    <style>
        body { background: #e5e7eb; margin: 0; }
        @page { size: A4; margin: 18mm; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { padding: 0 !important; }
            .paper { box-shadow: none !important; padding: 0 !important; width: auto !important; }
        }
    </style>
</head>
<body class="antialiased">
    {{-- Same as invoices: the browser saves it as a PDF, so Nepali text comes out right. --}}
    <div class="no-print sticky top-0 z-10 flex items-center justify-between gap-4 bg-zinc-900 px-6 py-3 text-sm text-white">
        <span><strong>{{ $script->title }}</strong> · {{ __('Choose "Save as PDF" as the printer to download it.') }}</span>
        <button type="button" onclick="window.print()" class="rounded-lg bg-white px-4 py-2 font-semibold text-zinc-900 hover:bg-zinc-100">{{ __('Download PDF') }}</button>
    </div>
    <div class="sheet flex justify-center px-4 py-10">
        <article class="paper w-[210mm] max-w-full bg-white p-[18mm] text-zinc-900 shadow-2xl">
            <h1 class="text-2xl font-bold tracking-tight">{{ $script->title }}</h1>
            <p class="mt-1 text-sm text-zinc-500">
                {{ $script->client?->displayName() ?? __('Other scripts') }} · {{ __((\App\Models\Script::STATUSES[$script->status] ?? \App\Models\Script::STATUSES['draft'])['label']) }} · {{ \App\Models\Script::speakingTime((string) $script->body) }}
                @if ($script->shoot_date) · {{ __('Shoot :date', ['date' => $script->shoot_date->toNepali()]) }}@endif
            </p>
            <div class="mt-6 border-t border-zinc-200 pt-6 text-[0.9375rem] leading-7 whitespace-pre-line">{{ $script->body ?: __('This script is still empty.') }}</div>
            @if ($script->notes)
                <div class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"><span class="font-semibold">{{ __('Shooting notes:') }}</span> {{ $script->notes }}</div>
            @endif
        </article>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
