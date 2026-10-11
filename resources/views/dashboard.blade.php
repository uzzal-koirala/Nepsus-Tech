<x-layouts::app :title="__('Dashboard')">
    <div class="stagger mx-auto flex w-full max-w-6xl flex-col gap-6">
        <header class="flex flex-col gap-1">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ now()->translatedFormat('l, j F Y') }}</p>
            <h1 class="text-2xl font-semibold tracking-normal text-zinc-900 uppercase sm:text-3xl dark:text-white">
                {{ __('Welcome back, :name', ['name' => str(auth()->user()->name)->before(' ')]) }}
            </h1>
        </header>

        {{-- Brand banner --}}
        <section class="grain relative isolate overflow-hidden rounded-3xl bg-brand-500 p-6 text-white sm:p-8 lg:min-h-64 lg:p-10">
            <div class="absolute inset-0 -z-10 opacity-20 [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:24px_24px] [mask-image:linear-gradient(to_right,black,transparent_60%)]"></div>
            <div class="absolute -right-10 -bottom-24 -z-10 hidden size-72 rounded-full bg-sun sm:block lg:right-16 lg:size-80"></div>
            <img src="{{ asset('images/auth-crew.webp') }}" alt="" class="absolute -bottom-6 right-0 -z-10 hidden h-[125%] w-auto sm:block lg:right-14">

            <div class="max-w-md sm:max-w-sm lg:max-w-md">
                <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/20 backdrop-blur">
                    <span class="size-1.5 rounded-full bg-sun"></span>
                    {{ __('Nepsus Tech workspace') }}
                </p>
                <h2 class="mt-4 text-2xl leading-tight font-semibold tracking-[-0.02em] text-balance sm:text-3xl">
                    {{ __('Every client. Every task.') }}<br><span class="text-sun">{{ __('One place.') }}</span>
                </h2>
                <p class="mt-3 text-sm leading-relaxed text-brand-100">
                    {{ __('Your workspace is ready. Clients, projects and tasks are being built next, and will show up in the sidebar as they go live.') }}
                </p>
            </div>
        </section>

        {{-- Setup steps --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Set up your workspace') }}</h2>

            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([
                    ['icon' => 'building-office-2', 'title' => __('Add your clients'), 'text' => __('Keep contact details, notes and history for every client in one profile.')],
                    ['icon' => 'folder', 'title' => __('Plan projects'), 'text' => __('Group work by client, set deadlines and see what is on track.')],
                    ['icon' => 'check-circle', 'title' => __('Assign tasks'), 'text' => __('Give each task an owner and a due date, and follow progress on a board.')],
                ] as $step)
                    <article class="flex flex-col gap-4 rounded-2xl bg-white p-5 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/5">
                        <div class="flex items-center justify-between">
                            <span class="flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-300">
                                <flux:icon :icon="$step['icon']" class="size-5" />
                            </span>
                            <span class="text-xs font-medium text-zinc-400 tabular-nums">{{ __('Step :n', ['n' => $loop->iteration]) }}</span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <h3 class="font-medium text-zinc-900 dark:text-white">{{ $step['title'] }}</h3>
                            <p class="text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $step['text'] }}</p>
                        </div>
                        <span class="mt-auto inline-flex w-fit items-center gap-1.5 rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                            <flux:icon.clock variant="micro" class="size-3.5" />
                            {{ __('Coming soon') }}
                        </span>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts::app>
