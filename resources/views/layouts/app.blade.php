<x-layouts::app.sidebar :title="$title ?? null">
    {{-- overflow-x-clip: nothing may widen the page past the screen (phones would scroll sideways). --}}
    <flux:main class="min-w-0 overflow-x-clip">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
