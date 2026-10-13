{{-- Vehicle fields, bound to $form. Shared by the add (vehicles page) and edit (vehicle profile) popups. --}}
<div class="flex flex-col gap-5">
    {{-- Type: picture tiles --}}
    <div>
        <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Type') }}</p>
        <div class="grid grid-cols-4 gap-2 sm:grid-cols-7" role="radiogroup">
            @foreach (\App\Models\Vehicle::TYPES as $key => $t)
                <button type="button" role="radio" wire:click="$set('form.type', '{{ $key }}')" aria-checked="{{ ($form['type'] ?? '') === $key ? 'true' : 'false' }}"
                    @class([
                        'group flex flex-col items-center gap-1 rounded-xl px-1 pt-2 pb-1.5 text-center ring-1 transition',
                        'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => ($form['type'] ?? '') === $key,
                        'bg-white ring-zinc-200 hover:ring-zinc-300 dark:bg-white/5 dark:ring-white/10' => ($form['type'] ?? '') !== $key,
                    ])>
                    <img src="{{ asset('images/vehicles/'.$t['image'].'.png') }}" alt="" class="size-10 transition group-hover:-translate-y-0.5 group-hover:scale-105">
                    <span class="w-full truncate text-[0.6875rem] font-medium text-zinc-700 dark:text-zinc-200">{{ __($t['label']) }}</span>
                </button>
            @endforeach
        </div>
        <flux:error name="form.type" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input wire:model="form.name" :label="__('Name')" :placeholder="__('e.g. Office scooter')" required />
        <div class="grid gap-2">
            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Number plate') }}</span>
            <div class="flex h-10 items-center overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-xs focus-within:ring-2 focus-within:ring-brand-500 dark:border-white/10 dark:bg-white/5">
                <span class="flex h-full items-center bg-red-600 px-2 text-[0.625rem] font-bold tracking-wider text-white">NP</span>
                <input wire:model="form.plate_no" type="text" maxlength="40" placeholder="{{ __('e.g. Ba 85 Pa 1234') }}" class="h-full w-full border-0 bg-transparent px-3 text-sm font-semibold tracking-wide text-zinc-900 uppercase placeholder:font-normal placeholder:tracking-normal placeholder:normal-case placeholder:text-zinc-400 focus:ring-0 dark:text-white">
            </div>
            <flux:error name="form.plate_no" />
        </div>
        <flux:input wire:model="form.brand" :label="__('Brand')" :placeholder="__('e.g. Honda')" />
        <div class="grid grid-cols-2 gap-3">
            <flux:input wire:model="form.model" :label="__('Model')" :placeholder="__('e.g. Dio')" />
            <flux:input wire:model="form.year" type="number" min="1950" :max="now()->year + 1" :label="__('Year')" placeholder="2022" />
        </div>
        <flux:select wire:model="form.employee_id" :label="__('Used by')">
            <flux:select.option value="">{{ __('Nobody / shared') }}</flux:select.option>
            @foreach (\App\Models\Employee::where('status', '!=', 'inactive')->orderBy('name')->get(['id', 'name']) as $person)
                <flux:select.option :value="$person->id">{{ $person->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="form.notes" :label="__('Notes')" :placeholder="__('Optional')" />
    </div>
</div>
