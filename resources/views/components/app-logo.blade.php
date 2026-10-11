@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex size-8 items-center justify-center">
            <img src="{{ asset('images/nepsus-logo.png') }}" alt="" class="size-8 rounded-md object-cover">
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex size-8 items-center justify-center">
            <img src="{{ asset('images/nepsus-logo.png') }}" alt="" class="size-8 rounded-md object-cover">
        </x-slot>
    </flux:brand>
@endif
