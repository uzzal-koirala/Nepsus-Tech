{{-- The A4 paper, scaled down to fit the space it is in. Needs $doc (Invoice::paper()). --}}
<div x-data="{ s: 1, h: 1123 }"
    x-init="const fit = () => { s = Math.min(1, $el.clientWidth / 794); h = Math.ceil($refs.paper.offsetHeight * s) }; fit(); new ResizeObserver(fit).observe($el); new ResizeObserver(fit).observe($refs.paper)"
    class="w-full" :style="`height: ${h}px`">
    <div x-ref="paper" class="origin-top-left shadow-[0_30px_80px_-30px_rgb(15_23_42/0.45)] ring-1 ring-zinc-200/80 dark:ring-white/10" :style="`transform: scale(${s}); width: 794px`">
        <x-invoice-paper :doc="$doc" />
    </div>
</div>
