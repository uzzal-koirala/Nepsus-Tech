@props(['employee'])

@php
    $tints = [
        'design' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/25 dark:text-rose-100',
        'development' => 'bg-brand-100 text-brand-700 dark:bg-brand-500/30 dark:text-white',
        'marketing' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/25 dark:text-amber-100',
        'content' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/25 dark:text-emerald-100',
        'sales' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/25 dark:text-sky-100',
        'management' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/25 dark:text-violet-100',
        'hr' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/25 dark:text-teal-100',
        'content-creator' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/25 dark:text-fuchsia-100',
        'video-editor' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/25 dark:text-indigo-100',
        'social-media-manager' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/25 dark:text-sky-100',
    ];
@endphp

@if ($url = $employee->avatarUrl())
<img src="{{ $url }}" alt="{{ $employee->name }}" {{ $attributes->merge(['class' => 'shrink-0 rounded-full object-cover']) }} title="{{ $employee->name }}">
@else
<span {{ $attributes->merge(['class' => ($tints[$employee->department] ?? $tints['development']).' inline-flex shrink-0 items-center justify-center rounded-full font-semibold tracking-tighter']) }} title="{{ $employee->name }}{{ $employee->designation ? ' · '.$employee->designation : '' }}">{{ $employee->initials() }}</span>
@endif
