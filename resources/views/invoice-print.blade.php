<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $invoice->number }} - {{ $invoice->bill_company ?: $invoice->bill_name }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css'])
    <style>
        body { background: #e5e7eb; margin: 0; }
        .invoice-paper { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { padding: 0 !important; box-shadow: none !important; }
            .invoice-paper { height: 297mm; min-height: 0 !important; width: 210mm !important; }
        }
    </style>
</head>
<body class="antialiased">
    <div class="no-print sticky top-0 z-10 flex items-center justify-between gap-4 bg-zinc-900 px-6 py-3 text-sm text-white">
        <span><strong>{{ $invoice->number }}</strong> · {{ __('Choose "Save as PDF" as the printer to download it.') }}</span>
        <button type="button" onclick="window.print()" class="rounded-lg bg-white px-4 py-2 font-semibold text-zinc-900 hover:bg-zinc-100">{{ __('Print / Save as PDF') }}</button>
    </div>
    <div class="sheet flex justify-center py-10">
        <div class="shadow-2xl">
            <x-invoice-paper :doc="$invoice->toPaper()" />
        </div>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
