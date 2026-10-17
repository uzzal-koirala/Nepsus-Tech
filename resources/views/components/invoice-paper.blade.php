@props(['doc'])

{{-- One A4 page (794 x 1123 px) of an invoice or quotation in the chosen template. $doc comes from Invoice::paper(). --}}
@php
    $a = $doc['accent_hex'];
    $navy = '#232b3a';
    $t = $doc['totals'];
    $b = $doc['business'];
    $money = fn ($v) => \App\Models\Client::money($v);
    $date = fn ($d) => $d ? $d->toNepali() : '—';
    $items = $doc['items'] ?? [];
    $tpl = $doc['template'] ?? 'classic';
    $contacts = array_filter([['phone', $b['phone']], ['envelope', $b['email']], ['map-pin', $b['address']]], fn ($c) => $c[1] !== '');
    $isQuote = ($doc['type'] ?? 'invoice') === 'quotation';
    // Quotations are addressed to the business only (falls back to the person when there is no business name).
    $billTitle = $isQuote ? (($doc['bill_company'] ?? null) ?: ($doc['bill_name'] ?? '')) : ($doc['bill_name'] ?? '');
    $billLines = array_filter([$isQuote ? null : ($doc['bill_company'] ?? null), $doc['bill_address'] ?? null]);
@endphp

<div {{ $attributes->class('invoice-paper relative flex flex-col overflow-hidden bg-white text-[13px] leading-relaxed text-zinc-700') }} style="width: 794px; min-height: 1123px; font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif;">

@if ($tpl === 'classic')
    {{-- Header: dark block with angled accent stripes --}}
    <div class="relative h-[150px]">
        <div class="absolute inset-y-0 left-0 w-[58%]" style="background: {{ $navy }}; clip-path: polygon(0 0, 100% 0, 82% 100%, 0 100%);"></div>
        <div class="absolute inset-y-0 left-[49%] w-[9%]" style="background: {{ $a }}; clip-path: polygon(55% 0, 100% 0, 45% 100%, 0 100%);"></div>
        <div class="absolute inset-y-0 left-[54%] w-[6%] opacity-70" style="background: {{ $a }}; clip-path: polygon(70% 0, 100% 0, 30% 75%, 0 75%);"></div>
        <div class="absolute top-0 left-0 flex h-full items-center gap-3 ps-10">
            <img src="{{ $b['logo'] }}" alt="" class="size-12 rounded-lg bg-white object-contain p-1">
            <div>
                <p class="text-[22px] leading-tight font-bold tracking-wide text-white uppercase">{{ $b['name'] }}</p>
                @if ($b['tagline'])<p class="text-[10px] tracking-[0.2em] text-white/70 uppercase">{{ $b['tagline'] }}</p>@endif
            </div>
        </div>
        <div class="absolute top-9 right-10 text-right">
            <p class="text-[40px] leading-none font-extrabold tracking-tight" style="color: {{ $a }}">{{ $doc['title'] }}</p>
            <div class="mt-3 grid grid-cols-[auto_auto] gap-x-6 text-[11px]">
                <span class="font-semibold text-zinc-900">{{ $doc['title'] === 'INVOICE' ? 'Invoice' : 'Quote' }} no.</span><span>{{ $doc['number'] }}</span>
                <span class="font-semibold text-zinc-900">Date</span><span>{{ $date($doc['issue']) }}</span>
                @if ($doc['due'])<span class="font-semibold text-zinc-900">{{ $doc['due_label'] }}</span><span>{{ $date($doc['due']) }}</span>@endif
            </div>
        </div>
    </div>

    {{-- To / From --}}
    <div class="flex justify-between px-10 pt-9">
        <div>
            <p class="text-[12px] font-semibold" style="color: {{ $a }}">{{ $doc['to_label'] }}:</p>
            <p class="mt-1 text-[20px] font-bold text-zinc-900">{{ $billTitle ?: '—' }}</p>
            @foreach ($billLines as $line)<p class="text-[12px]">{{ $line }}</p>@endforeach
            @if ($doc['bill_phone'] ?? null)<p class="text-[12px]"><span class="font-semibold text-zinc-900">Phone:</span> {{ $doc['bill_phone'] }}</p>@endif
            @if ($doc['bill_email'] ?? null)<p class="text-[12px]"><span class="font-semibold text-zinc-900">Email:</span> {{ $doc['bill_email'] }}</p>@endif
        </div>
        <div class="text-right">
            <p class="text-[12px] font-semibold" style="color: {{ $a }}">From:</p>
            <p class="mt-1 text-[20px] font-bold text-zinc-900">{{ $b['name'] }}</p>
            @if ($b['address'])<p class="text-[12px]">{{ $b['address'] }}</p>@endif
            @if ($b['phone'])<p class="text-[12px]"><span class="font-semibold text-zinc-900">Phone:</span> {{ $b['phone'] }}</p>@endif
            @if ($b['email'])<p class="text-[12px]"><span class="font-semibold text-zinc-900">Email:</span> {{ $b['email'] }}</p>@endif
            @if ($b['pan'])<p class="text-[12px]"><span class="font-semibold text-zinc-900">PAN:</span> {{ $b['pan'] }}</p>@endif
        </div>
    </div>

    {{-- Items --}}
    <div class="px-10 pt-8">
        <div class="grid grid-cols-[48px_1fr_100px_70px_110px] gap-1 text-[12px] font-semibold text-white uppercase">
            <div class="py-3 text-center" style="background: {{ $a }}">No.</div>
            <div class="py-3 ps-4" style="background: {{ $a }}">Description</div>
            <div class="py-3 text-center" style="background: {{ $navy }}">Price</div>
            <div class="py-3 text-center" style="background: {{ $navy }}">Qty.</div>
            <div class="py-3 text-center" style="background: {{ $navy }}">Total</div>
        </div>
        @forelse ($items as $i => $item)
            <div @class(['mt-1 grid grid-cols-[48px_1fr_100px_70px_110px] gap-1 text-[12px]'])>
                @php $bg = $i % 2 === 0 ? 'background:#f4f6f8' : ''; @endphp
                <div class="py-3 text-center font-semibold text-zinc-900" style="{{ $bg }}">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
                <div class="py-3 ps-4" style="{{ $bg }}"><p class="font-semibold text-zinc-900">{{ $item['description'] ?: '—' }}</p>@if ($item['details'] ?? null)<p class="text-[11px] text-zinc-500">{{ $item['details'] }}</p>@endif</div>
                <div class="py-3 text-center font-semibold text-zinc-900" style="{{ $bg }}">{{ $money($item['price'] ?? 0) }}</div>
                <div class="py-3 text-center" style="{{ $bg }}">{{ rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2), '0'), '.') }}</div>
                <div class="py-3 text-center font-semibold text-zinc-900" style="{{ $bg }}">{{ $money((float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0)) }}</div>
            </div>
        @empty
            <p class="py-8 text-center text-zinc-400">No items yet</p>
        @endforelse
    </div>

    {{-- Totals, payment, signature --}}
    <div class="flex justify-between gap-8 px-10 pt-7">
        <div class="flex flex-col justify-end gap-5 pb-1">
            @if ($doc['payment_info'] ?? null)
                <div>
                    <p class="text-[12px] font-semibold" style="color: {{ $a }}">Payment method:</p>
                    <p class="mt-1 text-[12px] whitespace-pre-line text-zinc-800">{{ $doc['payment_info'] }}</p>
                </div>
            @endif
        </div>
        <div class="flex items-end gap-10">
            <div class="w-44 text-center">
                <div class="h-10"></div>
                <div class="border-t border-zinc-300 pt-1.5 text-[12px] font-semibold text-zinc-900">Authorised sign</div>
            </div>
            <div class="w-60 text-[12px]">
                <div class="flex justify-between py-1"><span>Subtotal:</span><span class="font-semibold text-zinc-900">{{ $money($t['subtotal']) }}</span></div>
                @if ($t['discount'] > 0)<div class="flex justify-between py-1"><span>Discount:</span><span class="font-semibold text-zinc-900">− {{ $money($t['discount']) }}</span></div>@endif
                @if (($doc['tax_rate'] ?? 0) > 0)<div class="flex justify-between py-1"><span>Tax ({{ rtrim(rtrim(number_format($doc['tax_rate'], 2), '0'), '.') }}%):</span><span class="font-semibold text-zinc-900">{{ $money($t['tax']) }}</span></div>@endif
                <div class="mt-2 flex justify-between px-4 py-3 text-[15px] font-bold text-white" style="background: {{ $a }}"><span>Total:</span><span>{{ $money($t['total']) }}</span></div>
            </div>
        </div>
    </div>

    @if (($doc['notes'] ?? null) || ($doc['terms'] ?? null))
        <div class="grid grid-cols-2 gap-8 px-10 pt-8 text-[12px]">
            @if ($doc['terms'] ?? null)<div><p class="font-semibold" style="color: {{ $a }}">Terms &amp; conditions:</p><p class="mt-1 whitespace-pre-line">{{ $doc['terms'] }}</p></div>@endif
            @if ($doc['notes'] ?? null)<div><p class="font-semibold" style="color: {{ $a }}">Notes:</p><p class="mt-1 whitespace-pre-line">{{ $doc['notes'] }}</p></div>@endif
        </div>
    @endif

    @if ($isQuote)
        {{-- Quotations: a warm thank-you in the free space near the bottom --}}
        <div class="flex flex-1 flex-col items-center justify-center px-12 py-8 text-center">
            <div class="flex items-center gap-3">
                <span class="h-px w-16" style="background: {{ $a }}"></span>
                <flux:icon.heart variant="micro" class="size-4" style="color: {{ $a }}" />
                <span class="h-px w-16" style="background: {{ $a }}"></span>
            </div>
            <p class="mt-3 text-[24px] font-bold text-zinc-900">Thank you for your business!</p>
            <p class="mt-1 max-w-[440px] text-[13px] leading-relaxed text-zinc-500">We truly appreciate the opportunity to work with you and look forward to bringing your ideas to life. Feel free to reach out with any questions about this quotation.</p>
        </div>
    @endif

    <div class="mt-auto flex items-center gap-6 px-10 py-5 text-[11px] text-white" style="background: {{ $navy }}">
        @foreach ($contacts as [$icon, $value])
            <span class="flex items-center gap-2"><span class="flex size-6 rotate-45 items-center justify-center rounded" style="background: {{ $a }}"><flux:icon :icon="$icon" variant="micro" class="size-3.5 -rotate-45" /></span>{{ $value }}</span>
        @endforeach
        <span class="ms-auto text-[14px] font-bold">{{ $isQuote ? 'We look forward to working with you' : 'Thank you for your business' }}</span>
    </div>

@elseif ($tpl === 'minimal')
    <div class="px-12 pt-12">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded text-[20px] font-bold text-white" style="background: {{ $a }}">{{ mb_substr($b['name'], 0, 1) }}</span>
                <div>
                    <p class="text-[16px] leading-tight font-bold tracking-wide text-zinc-900 uppercase">{{ $b['name'] }}</p>
                    <p class="text-[11px] text-zinc-500">{{ $b['tagline'] ?: $b['address'] }}</p>
                </div>
            </div>
            <div class="w-[46%]">
                <p class="text-[38px] leading-none font-extrabold tracking-wide text-zinc-800">{{ $doc['title'] }}</p>
                <p class="mt-1 text-[12px] text-zinc-500">#{{ $doc['number'] }}</p>
            </div>
        </div>

        <div class="mt-12 flex items-start justify-between">
            <div>
                <p class="text-[12px] font-bold tracking-wide text-zinc-900 uppercase">{{ $doc['to_label'] }}:</p>
                <p class="mt-1 text-[13px] font-semibold text-zinc-800">{{ $billTitle ?: '—' }}</p>
                @foreach ($billLines as $line)<p class="text-[11px] text-zinc-500">{{ $line }}</p>@endforeach
                @if ($doc['bill_phone'] ?? null)<p class="text-[11px] text-zinc-500">{{ $doc['bill_phone'] }}</p>@endif
                @if ($doc['bill_email'] ?? null)<p class="text-[11px] text-zinc-500">{{ $doc['bill_email'] }}</p>@endif
            </div>
            <div class="flex w-[46%] bg-zinc-100">
                <span class="w-2 shrink-0" style="background: {{ $a }}"></span>
                <div class="grid flex-1 grid-cols-2 gap-x-4 gap-y-1 px-6 py-4 text-[11px]">
                    <span class="font-semibold text-zinc-900">Number</span><span class="font-semibold text-zinc-900">Date</span>
                    <span class="text-zinc-500">{{ $doc['number'] }}</span><span class="text-zinc-500">{{ $date($doc['issue']) }}</span>
                    @if ($doc['due'])<span class="mt-2 font-semibold text-zinc-900">{{ $doc['due_label'] }}</span><span></span><span class="text-zinc-500">{{ $date($doc['due']) }}</span>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="mt-8 px-12">
        <div class="grid grid-cols-[52px_1fr_100px_60px_110px] px-3 py-2.5 text-[13px] font-bold text-white uppercase" style="background: {{ $a }}">
            <span>No</span><span>Item description</span><span class="text-right">Price</span><span class="text-right">Qty</span><span class="text-right">Total</span>
        </div>
        @forelse ($items as $i => $item)
            <div class="grid grid-cols-[52px_1fr_100px_60px_110px] px-3 py-3.5 text-[12px]" style="background: {{ $i % 2 === 0 ? '#e9ebee' : '#dcdfe3' }}">
                <span>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                <span><span class="block font-semibold text-zinc-900">{{ $item['description'] ?: '—' }}</span>@if ($item['details'] ?? null)<span class="block text-[11px] text-zinc-500">{{ $item['details'] }}</span>@endif</span>
                <span class="text-right">{{ $money($item['price'] ?? 0) }}</span>
                <span class="text-right">{{ rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2), '0'), '.') }}</span>
                <span class="text-right">{{ $money((float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0)) }}</span>
            </div>
        @empty
            <p class="bg-zinc-100 py-8 text-center text-zinc-400">No items yet</p>
        @endforelse
    </div>

    <div class="flex justify-between gap-8 px-12 pt-8">
        <div class="grid w-[50%] grid-cols-2 gap-6 text-[11px]">
            <div>
                <p class="text-[13px] font-bold text-zinc-900">Payment method</p>
                <p class="mt-1 whitespace-pre-line text-zinc-500">{{ $doc['payment_info'] ?: '—' }}</p>
            </div>
            <div>
                <p class="text-[13px] font-bold text-zinc-900">Terms &amp; conditions</p>
                <p class="mt-1 whitespace-pre-line text-zinc-500">{{ $doc['terms'] ?: '—' }}</p>
            </div>
            @if ($doc['notes'] ?? null)<p class="col-span-2 whitespace-pre-line text-zinc-500">{{ $doc['notes'] }}</p>@endif
        </div>
        <div class="w-[44%] text-[12px]">
            <div class="flex justify-between px-4 py-1"><span class="font-semibold text-zinc-900">Sub total:</span><span>{{ $money($t['subtotal']) }}</span></div>
            @if (($doc['tax_rate'] ?? 0) > 0)<div class="flex justify-between px-4 py-1"><span class="font-semibold text-zinc-900">Tax ({{ rtrim(rtrim(number_format($doc['tax_rate'], 2), '0'), '.') }}%):</span><span>{{ $money($t['tax']) }}</span></div>@endif
            @if ($t['discount'] > 0)<div class="flex justify-between px-4 py-1"><span class="font-semibold text-zinc-900">Discount:</span><span>− {{ $money($t['discount']) }}</span></div>@endif
            <div class="mt-3 flex justify-between px-4 py-2.5 text-[14px] font-bold text-white" style="background: {{ $a }}"><span>Grand total:</span><span>{{ $money($t['total']) }}</span></div>
        </div>
    </div>

    @if ($isQuote)
        {{-- Quotations: a warm thank-you in the free space near the bottom --}}
        <div class="flex flex-1 flex-col items-center justify-center px-12 py-8 text-center">
            <div class="flex items-center gap-3">
                <span class="h-px w-16" style="background: {{ $a }}"></span>
                <flux:icon.heart variant="micro" class="size-4" style="color: {{ $a }}" />
                <span class="h-px w-16" style="background: {{ $a }}"></span>
            </div>
            <p class="mt-3 text-[24px] font-bold text-zinc-900">Thank you for your business!</p>
            <p class="mt-1 max-w-[440px] text-[13px] leading-relaxed text-zinc-500">We truly appreciate the opportunity to work with you and look forward to bringing your ideas to life. Feel free to reach out with any questions about this quotation.</p>
        </div>
    @endif

    <div class="mt-auto flex items-end justify-between px-12 pt-10 pb-12">
        <div class="w-44 text-center">
            <div class="h-10"></div>
            <div class="border-t border-zinc-300 pt-1.5"><p class="text-[13px] font-bold text-zinc-900">{{ $b['name'] }}</p><p class="text-[11px] text-zinc-500">Authorised sign</p></div>
        </div>
        <div class="text-right">
            @unless ($isQuote)<p class="text-[20px] font-bold" style="color: {{ $a }}">Thank you for your business!</p>@endunless
            <div class="mt-2 flex justify-end gap-5 text-[11px] text-zinc-500">
                @foreach ($contacts as [$icon, $value])
                    <span class="flex items-center gap-1.5"><flux:icon :icon="$icon" variant="micro" class="size-3.5" />{{ $value }}</span>
                @endforeach
            </div>
        </div>
    </div>

@else
    {{-- Bold: full dark header with accent bands --}}
    <div class="relative h-[190px]" style="background: {{ $navy }}">
        <div class="absolute top-0 left-0 h-[34px] w-[52%]" style="background: {{ $a }}; clip-path: polygon(0 0, 100% 0, 93% 100%, 0 100%);"></div>
        <div class="absolute top-0 left-[49%] h-[34px] w-[6%]" style="background: {{ $a }}; opacity: .55; clip-path: polygon(0 0, 100% 0, 40% 100%, 0 100%);"></div>
        <div class="absolute right-0 bottom-0 h-[26px] w-[48%]" style="background: {{ $a }}; clip-path: polygon(6% 0, 100% 0, 100% 100%, 0 100%);"></div>
        <div class="absolute top-[70px] left-10 flex items-center gap-3">
            <img src="{{ $b['logo'] }}" alt="" class="size-12 rounded-lg bg-white object-contain p-1">
            <div>
                <p class="text-[22px] leading-tight font-bold tracking-wide text-white uppercase">{{ $b['name'] }}</p>
                @if ($b['tagline'])<p class="text-[10px] tracking-[0.2em] text-white/70 uppercase">{{ $b['tagline'] }}</p>@endif
            </div>
        </div>
        <div class="absolute top-[46px] right-10 text-right text-white">
            <p class="text-[40px] leading-none font-extrabold tracking-wide">{{ $doc['title'] }}</p>
            <div class="mt-3 grid grid-cols-[auto_auto] gap-x-6 text-[11px] text-white/85">
                <span class="font-semibold text-white">Number:</span><span>{{ $doc['number'] }}</span>
                <span class="font-semibold text-white">Date:</span><span>{{ $date($doc['issue']) }}</span>
                @if ($doc['due'])<span class="font-semibold text-white">{{ $doc['due_label'] }}:</span><span>{{ $date($doc['due']) }}</span>@endif
            </div>
        </div>
    </div>

    <div class="flex justify-between px-10 pt-8">
        <div>
            <p class="text-[12px] font-semibold tracking-wide uppercase" style="color: {{ $a }}">{{ $doc['to_label'] }}:</p>
            <p class="mt-1 text-[22px] font-bold text-zinc-900">{{ $billTitle ?: '—' }}</p>
            @foreach ($billLines as $line)<p class="text-[12px]">{{ $line }}</p>@endforeach
            @if ($doc['bill_phone'] ?? null)<p class="text-[12px]">Phone: {{ $doc['bill_phone'] }}</p>@endif
            @if ($doc['bill_email'] ?? null)<p class="text-[12px]">Email: {{ $doc['bill_email'] }}</p>@endif
        </div>
        @if ($doc['payment_info'] ?? null)
            <div class="max-w-[45%] text-right">
                <p class="text-[14px] font-semibold" style="color: {{ $a }}">Payment method</p>
                <p class="mt-1 text-[12px] whitespace-pre-line text-zinc-800">{{ $doc['payment_info'] }}</p>
            </div>
        @endif
    </div>

    <div class="px-10 pt-7">
        <div class="grid grid-cols-[1fr_100px_60px_110px] px-4 py-3 text-[12px] font-semibold text-white uppercase" style="background: {{ $a }}">
            <span>Item description</span><span class="text-right">Price</span><span class="text-right">Qty.</span><span class="text-right">Total</span>
        </div>
        @forelse ($items as $i => $item)
            <div class="grid grid-cols-[1fr_100px_60px_110px] px-4 py-3 text-[12px]" style="{{ $i % 2 === 1 ? 'background:#f4f6f8' : '' }}">
                <span><span class="block font-medium text-zinc-900">{{ $item['description'] ?: '—' }}</span>@if ($item['details'] ?? null)<span class="block text-[11px] text-zinc-500">{{ $item['details'] }}</span>@endif</span>
                <span class="text-right">{{ $money($item['price'] ?? 0) }}</span>
                <span class="text-right">{{ rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2), '0'), '.') }}</span>
                <span class="text-right font-semibold text-zinc-900">{{ $money((float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0)) }}</span>
            </div>
        @empty
            <p class="py-8 text-center text-zinc-400">No items yet</p>
        @endforelse
    </div>

    <div class="flex justify-between gap-8 px-10 pt-7">
        <div>
            @if (! $isQuote || $contacts)
                <p class="text-[15px] font-bold text-zinc-900">{{ $isQuote ? 'Get in touch' : 'Thank you for your business' }}</p>
            @endif
            <div class="mt-3 flex flex-col gap-2 text-[12px]">
                @foreach ($contacts as [$icon, $value])
                    <span class="flex items-center gap-2"><span class="flex size-5 items-center justify-center rounded text-white" style="background: {{ $a }}"><flux:icon :icon="$icon" variant="micro" class="size-3" /></span>{{ $value }}</span>
                @endforeach
            </div>
        </div>
        <div class="w-60 text-[12px]">
            <div class="flex justify-between py-1"><span>Subtotal:</span><span class="font-semibold text-zinc-900">{{ $money($t['subtotal']) }}</span></div>
            @if ($t['discount'] > 0)<div class="flex justify-between py-1"><span>Discount:</span><span class="font-semibold text-zinc-900">− {{ $money($t['discount']) }}</span></div>@endif
            @if (($doc['tax_rate'] ?? 0) > 0)<div class="flex justify-between py-1"><span>Tax ({{ rtrim(rtrim(number_format($doc['tax_rate'], 2), '0'), '.') }}%):</span><span class="font-semibold text-zinc-900">{{ $money($t['tax']) }}</span></div>@endif
            <div class="mt-2 flex justify-between px-4 py-3 text-[15px] font-bold text-white" style="background: {{ $a }}"><span>Total:</span><span>{{ $money($t['total']) }}</span></div>
        </div>
    </div>

    <div class="flex items-end justify-between gap-8 px-10 pt-8 pb-10">
        <div class="max-w-[55%] text-[12px]">
            @if ($doc['terms'] ?? null)<p class="text-[14px] font-bold text-zinc-900">Terms &amp; conditions:</p><p class="mt-1 whitespace-pre-line">{{ $doc['terms'] }}</p>@endif
            @if ($doc['notes'] ?? null)<p class="mt-3 whitespace-pre-line text-zinc-500">{{ $doc['notes'] }}</p>@endif
        </div>
        <div class="w-48 text-center">
            <div class="h-10"></div>
            <div class="border-t border-zinc-300 pt-1.5"><p class="text-[12px] font-bold text-zinc-900">{{ $b['name'] }}</p><p class="text-[10px] text-zinc-500">Authorised sign</p></div>
        </div>
    </div>

    @if ($isQuote)
        {{-- Quotations: a warm thank-you in the free space near the bottom --}}
        <div class="flex flex-1 flex-col items-center justify-center px-12 py-8 text-center">
            <div class="flex items-center gap-3">
                <span class="h-px w-16" style="background: {{ $a }}"></span>
                <flux:icon.heart variant="micro" class="size-4" style="color: {{ $a }}" />
                <span class="h-px w-16" style="background: {{ $a }}"></span>
            </div>
            <p class="mt-3 text-[24px] font-bold text-zinc-900">Thank you for your business!</p>
            <p class="mt-1 max-w-[440px] text-[13px] leading-relaxed text-zinc-500">We truly appreciate the opportunity to work with you and look forward to bringing your ideas to life. Feel free to reach out with any questions about this quotation.</p>
        </div>
    @endif

    <div class="relative mt-auto h-[30px]" style="background: {{ $navy }}">
        <div class="absolute inset-y-0 left-0 w-[50%]" style="background: {{ $a }}; clip-path: polygon(0 0, 92% 0, 100% 100%, 0 100%);"></div>
    </div>
@endif
</div>
