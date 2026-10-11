@props(['client', 'tint' => 'bg-brand-100 text-brand-700'])

{{-- Client photo, or initials on a tinted tile when there is none. Size and radius come from the caller's class. --}}
@if ($url = $client->avatarUrl())
    <img src="{{ $url }}" alt="{{ $client->name }}" {{ $attributes->class('shrink-0 object-cover') }}>
@else
    <span {{ $attributes->class([$tint, 'flex shrink-0 items-center justify-center font-semibold']) }}>{{ $client->initials() }}</span>
@endif
