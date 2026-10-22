<?php

use App\Models\CompanyDocument;
use App\Models\DocumentFolder;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Documents')] class extends Component {
    use WithFileUploads;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $folder = '';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var array<int, string> The name each uploaded file is saved under. */
    public array $names = [];

    public string $uploadFolder = 'other';

    public string $newFolder = '';

    public ?string $deletingFolder = null;

    public ?int $editingId = null;

    public string $editName = '';

    public string $editFolder = 'other';

    public string $editNote = '';

    public ?int $deletingId = null;

    public ?int $previewId = null;

    public function mount(): void
    {
        $this->folder = array_key_exists($this->folder, $this->folders) ? $this->folder : '';
        $this->view = $this->view === 'list' ? 'list' : 'grid';
    }

    #[Computed]
    public function folders(): array
    {
        return CompanyDocument::folders();
    }

    #[Computed]
    public function all()
    {
        return CompanyDocument::with('uploader')->latest()->get();
    }

    #[Computed]
    public function visible()
    {
        $q = mb_strtolower(trim($this->search));

        return $this->all
            ->when($this->folder !== '', fn ($c) => $c->where('folder', $this->folder))
            ->when($q !== '', fn ($c) => $c->filter(fn ($d) => str_contains(mb_strtolower($d->name.' '.$d->extension.' '.$d->note), $q)))
            ->values();
    }

    #[Computed]
    public function preview(): ?CompanyDocument
    {
        return $this->previewId ? CompanyDocument::find($this->previewId) : null;
    }

    public function openUpload(): void
    {
        $this->reset('uploads', 'names', 'newFolder');
        $this->uploadFolder = $this->folder ?: DocumentFolder::FALLBACK;
        $this->resetErrorBag();
        Flux::modal('upload')->show();
    }

    /** New files start with their file name, which can be changed before saving. */
    public function updatedUploads(): void
    {
        foreach ($this->uploads as $i => $file) {
            $this->names[$i] ??= Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Document', 200, '');
        }
        $this->names = array_slice($this->names, 0, count($this->uploads), true);
    }

    public function dropFile(int $i): void
    {
        unset($this->uploads[$i], $this->names[$i]);
        $this->uploads = array_values($this->uploads);
        $this->names = array_values($this->names);
    }

    public function addFolder(string $target): void
    {
        abort_unless(in_array($target, ['uploadFolder', 'editFolder'], true), 422);
        $name = trim($this->validate(['newFolder' => ['required', 'string', 'max:40']], attributes: ['newFolder' => __('folder name')])['newFolder']);
        $folder = DocumentFolder::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? DocumentFolder::add($name);
        $this->{$target} = $folder->slug;
        $this->reset('newFolder');
        unset($this->folders);
    }

    public function confirmDeleteFolder(string $slug): void
    {
        abort_if($slug === DocumentFolder::FALLBACK, 422);
        $this->deletingFolder = DocumentFolder::where('slug', $slug)->firstOrFail()->slug;
        Flux::modal('delete-folder')->show();
    }

    public function deleteFolder(): void
    {
        $folder = DocumentFolder::where('slug', $this->deletingFolder)->where('slug', '!=', DocumentFolder::FALLBACK)->firstOrFail();
        $moved = $folder->deleteMovingFiles();
        foreach (['folder' => '', 'uploadFolder' => DocumentFolder::FALLBACK, 'editFolder' => DocumentFolder::FALLBACK] as $prop => $fallback) {
            if ($this->{$prop} === $folder->slug) {
                $this->{$prop} = $fallback;
            }
        }
        $this->deletingFolder = null;
        unset($this->folders, $this->all, $this->visible);
        Flux::modal('delete-folder')->close();
        Flux::toast(text: $moved ? trans_choice('Folder deleted. :count document moved to Other.|Folder deleted. :count documents moved to Other.', $moved) : __('Folder deleted.'));
    }

    /** Not called upload: $wire.upload and $wire.removeUpload are Livewire's own file-upload helpers. */
    public function saveUploads(): void
    {
        $this->validate([
            'uploads' => ['required', 'array', 'min:1', 'max:20'],
            'uploads.*' => ['file', 'max:20480', 'mimes:'.implode(',', CompanyDocument::EXTENSIONS)],
            'names.*' => ['nullable', 'string', 'max:200'],
            'uploadFolder' => ['required', Rule::exists('document_folders', 'slug')],
        ], ['uploads.*.max' => __('Each file can be up to 20 MB.'), 'uploads.*.mimes' => __('This kind of file is not allowed.')], ['uploads' => __('files'), 'names.*' => __('name')]);

        foreach ($this->uploads as $i => $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            CompanyDocument::create([
                'name' => trim($this->names[$i] ?? '') ?: Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Document', 200, ''),
                'folder' => $this->uploadFolder,
                'path' => $file->store('company-documents', 'local'),
                'extension' => $ext,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }

        $count = count($this->uploads);
        $this->reset('uploads', 'names');
        unset($this->all, $this->visible);
        Flux::modal('upload')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count document saved.|:count documents saved.', $count));
    }

    public function edit(int $id): void
    {
        $d = CompanyDocument::findOrFail($id);
        [$this->editingId, $this->editName, $this->editFolder, $this->editNote] = [$d->id, $d->name, $d->folder, (string) $d->note];
        $this->reset('newFolder');
        $this->resetErrorBag();
        Flux::modal('edit')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'editName' => ['required', 'string', 'max:200'],
            'editFolder' => ['required', Rule::exists('document_folders', 'slug')],
            'editNote' => ['nullable', 'string', 'max:255'],
        ], attributes: ['editName' => __('name')]);
        CompanyDocument::findOrFail($this->editingId)->update(['name' => trim($data['editName']), 'folder' => $data['editFolder'], 'note' => $data['editNote'] ?: null]);
        unset($this->all, $this->visible);
        Flux::modal('edit')->close();
        Flux::toast(variant: 'success', text: __('Document saved.'));
    }

    public function open(int $id): void
    {
        $this->previewId = CompanyDocument::findOrFail($id)->id;
        unset($this->preview);
        Flux::modal('preview')->show();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = CompanyDocument::findOrFail($id)->id;
        Flux::modal('preview')->close();
        Flux::modal('delete')->show();
    }

    public function delete(): void
    {
        CompanyDocument::whereKey($this->deletingId)->first()?->delete();
        $this->deletingId = null;
        unset($this->all, $this->visible);
        Flux::modal('delete')->close();
        Flux::toast(text: __('Document deleted.'));
    }
}; ?>

@php
    $panel = 'rounded-xl bg-white ring-1 ring-zinc-200/70 shadow-[0_1px_2px_rgb(15_23_42/0.04)] dark:bg-zinc-950 dark:ring-white/10';
    $folders = $this->folders;
    $all = $this->all;
    $docs = $this->visible;
    $counts = $all->countBy('folder');
    $kinds = [
        'pdf' => ['icon' => 'document-text', 'tile' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300', 'badge' => 'bg-rose-500'],
        'word' => ['icon' => 'document-text', 'tile' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300', 'badge' => 'bg-sky-500'],
        'sheet' => ['icon' => 'table-cells', 'tile' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300', 'badge' => 'bg-emerald-500'],
        'slides' => ['icon' => 'presentation-chart-bar', 'tile' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300', 'badge' => 'bg-orange-500'],
        'image' => ['icon' => 'photo', 'tile' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300', 'badge' => 'bg-violet-500'],
        'zip' => ['icon' => 'archive-box', 'tile' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300', 'badge' => 'bg-amber-500'],
        'text' => ['icon' => 'document', 'tile' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300', 'badge' => 'bg-zinc-500'],
        'file' => ['icon' => 'document', 'tile' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300', 'badge' => 'bg-zinc-500'],
    ];
    $kindOf = fn ($d) => $kinds[$d->kind()];
@endphp

<div class="stagger flex w-full flex-col gap-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.02em] text-zinc-900 sm:text-3xl dark:text-white">{{ __('Documents') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Keep the company\'s papers in one safe place. Only signed-in people can open them.') }}</p>
        </div>
        <flux:button variant="primary" icon="arrow-up-tray" wire:click="openUpload" class="shadow-lg shadow-brand-500/25" data-test="upload">{{ __('Upload') }}</flux:button>
    </header>

    <div class="grid items-start gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
        {{-- Folders --}}
        <aside class="flex flex-col gap-4">
            <nav class="{{ $panel }} flex gap-1 overflow-x-auto p-2 lg:flex-col [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach (['' => ['label' => __('All documents'), 'icon' => 'squares-2x2']] + $folders as $key => $f)
                    @php $on = $folder === $key; @endphp
                    <button type="button" wire:click="$set('folder', '{{ $key }}')" @class([
                        'flex shrink-0 items-center gap-3 rounded-lg px-3 py-2 text-start text-sm font-medium transition',
                        'bg-brand-500 text-white shadow-md shadow-brand-500/25' => $on,
                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/5' => ! $on,
                    ])>
                        <flux:icon :icon="$f['icon']" variant="mini" @class(['size-4.5', 'text-white' => $on, 'text-zinc-400' => ! $on]) />
                        <span class="flex-1 whitespace-nowrap">{{ __($f['label']) }}</span>
                        <span @class(['rounded-md px-1.5 text-xs tabular-nums', 'bg-white/20' => $on, 'text-zinc-400' => ! $on])>{{ $key === '' ? $all->count() : ($counts[$key] ?? 0) }}</span>
                    </button>
                @endforeach
            </nav>
            <div class="{{ $panel }} hidden p-4 lg:block">
                <p class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white"><flux:icon.circle-stack variant="mini" class="size-4.5 text-brand-500" />{{ __('Storage used') }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ CompanyDocument::humanSize($all->sum('size')) }}</p>
                <p class="text-xs text-zinc-500">{{ trans_choice(':count file|:count files', $all->count()) }}</p>
            </div>
        </aside>

        <section class="flex min-w-0 flex-col gap-4">
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="min-w-56 flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search documents')" />
                </div>
                <nav class="flex gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200/70 dark:bg-zinc-950 dark:ring-white/10">
                    @foreach (['grid' => 'squares-2x2', 'list' => 'list-bullet'] as $key => $icon)
                        <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['rounded-lg p-2 transition', 'bg-brand-500 text-white shadow-md shadow-brand-500/30' => $view === $key, 'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5' => $view !== $key]) aria-label="{{ $key === 'grid' ? __('Grid') : __('List') }}"><flux:icon :icon="$icon" variant="micro" class="size-4" /></button>
                    @endforeach
                </nav>
            </div>

            @if ($docs->isEmpty())
                <div class="{{ $panel }} flex flex-col items-center gap-4 px-6 py-16 text-center">
                    <span class="flex size-16 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-500/30"><flux:icon.folder-open class="size-8" /></span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $all->isEmpty() ? __('No documents yet') : __('Nothing here') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ $all->isEmpty() ? __('Upload contracts, company papers, templates and more.') : __('Try another folder or search.') }}</p>
                    </div>
                    <flux:button variant="primary" icon="arrow-up-tray" wire:click="openUpload">{{ __('Upload documents') }}</flux:button>
                </div>
            @elseif ($view === 'grid')
                <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(min(100%,12.5rem),1fr))]">
                    @foreach ($docs as $d)
                        @php $k = $kindOf($d); @endphp
                        <article wire:key="doc-{{ $d->id }}" class="group {{ $panel }} flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-lg" data-test="doc-{{ $d->id }}">
                            <button type="button" wire:click="open({{ $d->id }})" class="relative flex h-36 items-center justify-center overflow-hidden bg-zinc-50 dark:bg-white/[0.03]">
                                @if ($d->kind() === 'image')
                                    <img src="{{ route('library.view', $d) }}" alt="" loading="lazy" class="size-full object-cover transition duration-300 group-hover:scale-105">
                                @else
                                    <span class="{{ $k['tile'] }} flex size-16 items-center justify-center rounded-2xl transition duration-300 group-hover:scale-110"><flux:icon :icon="$k['icon']" class="size-8" /></span>
                                @endif
                                <span class="{{ $k['badge'] }} absolute top-2.5 left-2.5 rounded-md px-1.5 py-0.5 text-[0.625rem] font-bold tracking-wide text-white uppercase shadow-sm">{{ $d->extension }}</span>
                                <span class="absolute inset-0 flex items-center justify-center bg-zinc-900/40 opacity-0 backdrop-blur-[1px] transition group-hover:opacity-100">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-zinc-900 shadow-lg"><flux:icon.eye variant="micro" class="size-4" />{{ __('Open') }}</span>
                                </span>
                            </button>
                            <div class="flex items-start gap-2 p-3.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white" title="{{ $d->name }}">{{ $d->name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-zinc-500">{{ __($folders[$d->folder]['label'] ?? 'Other') }} · {{ $d->readableSize() }}</p>
                                </div>
                                <flux:dropdown align="end">
                                    <button type="button" class="-me-1 rounded-lg p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10" aria-label="{{ __('Actions') }}"><flux:icon.ellipsis-vertical variant="mini" class="size-4.5" /></button>
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="open({{ $d->id }})">{{ __('Open') }}</flux:menu.item>
                                        <flux:menu.item icon="arrow-down-tray" :href="route('library.download', $d)">{{ __('Download') }}</flux:menu.item>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $d->id }})">{{ __('Rename or move') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $d->id }})">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="{{ $panel }} overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-sm">
                            <thead class="bg-zinc-50/80 dark:bg-white/[0.02]">
                                <tr class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">
                                    <th class="px-3 py-2.5 ps-5 text-start">{{ __('Name') }}</th>
                                    <th class="px-3 py-2.5 text-start">{{ __('Folder') }}</th>
                                    <th class="px-3 py-2.5 text-start">{{ __('Size') }}</th>
                                    <th class="px-3 py-2.5 text-start">{{ __('Added') }}</th>
                                    <th class="w-28 px-3 py-2.5 pe-5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach ($docs as $d)
                                    @php $k = $kindOf($d); @endphp
                                    <tr wire:key="row-{{ $d->id }}" class="group transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                        <td class="max-w-md px-3 py-2.5 ps-5">
                                            <button type="button" wire:click="open({{ $d->id }})" class="flex min-w-0 items-center gap-3 text-start">
                                                <span class="{{ $k['tile'] }} flex size-9 shrink-0 items-center justify-center rounded-lg"><flux:icon :icon="$k['icon']" variant="mini" class="size-4.5" /></span>
                                                <span class="min-w-0">
                                                    <span class="block truncate font-semibold text-zinc-900 group-hover:text-brand-600 dark:text-white">{{ $d->name }}</span>
                                                    <span class="block text-xs text-zinc-400 uppercase">{{ $d->extension }}</span>
                                                </span>
                                            </button>
                                        </td>
                                        <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ __($folders[$d->folder]['label'] ?? 'Other') }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-300">{{ $d->readableSize() }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $d->created_at->toNepali() }}</td>
                                        <td class="px-3 py-2.5 pe-5 text-end whitespace-nowrap">
                                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('library.download', $d)" :aria-label="__('Download')" />
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $d->id }})" :aria-label="__('Rename or move')" />
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $d->id }})" :aria-label="__('Delete')" class="text-rose-600!" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    </div>

    {{-- Upload --}}
    <flux:modal name="upload" class="w-full max-w-lg overflow-visible!">
        <form wire:submit="saveUploads" class="flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30"><flux:icon.arrow-up-tray variant="mini" class="size-5" /></span>
                <div>
                    <flux:heading size="lg">{{ __('Upload documents') }}</flux:heading>
                    <flux:text>{{ __('Up to 20 files at once, 20 MB each.') }}</flux:text>
                </div>
            </div>

            <label x-data="{ over: false }" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="over = false; $refs.files.files = $event.dataTransfer.files; $refs.files.dispatchEvent(new Event('change'))"
                :class="over && 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10'"
                class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-zinc-200 px-6 py-8 text-center transition hover:border-brand-300 hover:bg-brand-50/30 dark:border-white/10">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><flux:icon.cloud-arrow-up class="size-6" /></span>
                <span class="text-sm font-semibold text-zinc-800 dark:text-white">{{ __('Drop files here or click to choose') }}</span>
                <span class="text-xs text-zinc-500">{{ __('PDF, Word, Excel, PowerPoint, images, text or ZIP') }}</span>
                <input x-ref="files" type="file" wire:model="uploads" multiple class="sr-only" accept="{{ collect(CompanyDocument::EXTENSIONS)->map(fn ($e) => '.'.$e)->implode(',') }}" data-test="files">
            </label>
            <div wire:loading wire:target="uploads" class="flex items-center gap-2 text-xs text-zinc-500"><flux:icon.arrow-path variant="micro" class="size-4 animate-spin" />{{ __('Uploading…') }}</div>

            @if ($uploads)
                <ul class="flex max-h-60 flex-col gap-1.5 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    @foreach ($uploads as $i => $file)
                        <li wire:key="up-{{ $i }}" class="flex items-center gap-3 rounded-lg bg-zinc-50 py-1.5 ps-3 pe-2 dark:bg-white/5">
                            <flux:icon.document variant="mini" class="size-4.5 shrink-0 text-zinc-400" />
                            <div class="min-w-0 flex-1">
                                <input type="text" wire:model="names.{{ $i }}" maxlength="200" placeholder="{{ __('Document name') }}" aria-label="{{ __('Document name') }}"
                                    class="h-8 w-full rounded-md border-0 bg-white px-2 text-sm font-medium text-zinc-900 ring-1 ring-zinc-200 focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-white dark:ring-white/10" data-test="name-{{ $i }}">
                                <p class="mt-0.5 truncate px-0.5 text-[0.6875rem] text-zinc-400">{{ $file->getClientOriginalName() }} · {{ CompanyDocument::humanSize($file->getSize()) }}</p>
                            </div>
                            <button type="button" wire:click="dropFile({{ $i }})" class="rounded p-1 text-zinc-400 hover:text-rose-600" aria-label="{{ __('Remove') }}"><flux:icon.x-mark variant="micro" class="size-4" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <flux:error name="uploads" />
            @foreach ($errors->get('uploads.*') as $messages)<p class="text-sm text-rose-600">{{ $messages[0] }}</p>@break @endforeach

            @include('partials.folder-picker', ['model' => 'uploadFolder'])

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="uploads,saveUploads" data-test="save-upload">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Rename / move --}}
    <flux:modal name="edit" class="w-full max-w-md overflow-visible!">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ __('Rename or move') }}</flux:heading>
            <flux:input wire:model="editName" :label="__('Name')" required />
            @include('partials.folder-picker', ['model' => 'editFolder'])
            <flux:input wire:model="editNote" :label="__('Note (optional)')" :placeholder="__('e.g. Signed copy, valid till 2084')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Preview --}}
    <flux:modal name="preview" class="w-full max-w-4xl p-0!">
        @if ($p = $this->preview)
            @php $k = $kindOf($p); @endphp
            <div class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4 pe-14 dark:border-white/5">
                <span class="{{ $k['tile'] }} flex size-10 shrink-0 items-center justify-center rounded-xl"><flux:icon :icon="$k['icon']" variant="mini" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $p->name }}.{{ $p->extension }}</p>
                    <p class="truncate text-xs text-zinc-500">{{ __($folders[$p->folder]['label'] ?? 'Other') }} · {{ $p->readableSize() }} · {{ __('added :date', ['date' => $p->created_at->toNepali()]) }}@if ($p->uploader) {{ __('by :name', ['name' => $p->uploader->name]) }}@endif</p>
                </div>
            </div>
            <div class="flex h-[65vh] items-center justify-center bg-zinc-100 dark:bg-zinc-900">
                @if ($p->kind() === 'image')
                    <img src="{{ route('library.view', $p) }}" alt="{{ $p->name }}" class="max-h-full max-w-full object-contain">
                @elseif ($p->previewable())
                    <iframe src="{{ route('library.view', $p) }}" class="size-full bg-white" title="{{ $p->name }}"></iframe>
                @else
                    <div class="flex flex-col items-center gap-3 text-center">
                        <span class="{{ $k['tile'] }} flex size-20 items-center justify-center rounded-3xl"><flux:icon :icon="$k['icon']" class="size-10" /></span>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ __('No preview for this kind of file') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Download it to open it on your computer.') }}</p>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2 px-5 py-4">
                @if ($p->note)<p class="flex min-w-0 flex-1 items-center gap-1.5 truncate text-sm text-zinc-600 dark:text-zinc-300"><flux:icon.chat-bubble-bottom-center-text variant="micro" class="size-4 shrink-0 text-zinc-400" />{{ $p->note }}</p>@else<span class="flex-1"></span>@endif
                <flux:button variant="ghost" icon="trash" class="text-rose-600!" wire:click="confirmDelete({{ $p->id }})">{{ __('Delete') }}</flux:button>
                <flux:button variant="ghost" icon="pencil-square" wire:click="edit({{ $p->id }})" x-on:click="$flux.modal('preview').close()">{{ __('Rename') }}</flux:button>
                @if ($p->previewable())<flux:button icon="arrow-top-right-on-square" :href="route('library.view', $p)" target="_blank">{{ __('New tab') }}</flux:button>@endif
                <flux:button variant="primary" icon="arrow-down-tray" :href="route('library.download', $p)">{{ __('Download') }}</flux:button>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="delete-folder" class="w-full max-w-sm">
        @php $df = $deletingFolder ? ($folders[$deletingFolder] ?? null) : null; $inIt = $deletingFolder ? ($counts[$deletingFolder] ?? 0) : 0; @endphp
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.folder-minus class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Delete the folder “:name”?', ['name' => $df['label'] ?? '']) }}</flux:heading>
                <flux:text class="mt-1">{{ $inIt ? trans_choice('Its :count document moves to Other. No files are deleted.|Its :count documents move to Other. No files are deleted.', $inIt) : __('It is empty.') }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Keep it') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteFolder" class="flex-1" data-test="confirm-delete-folder">{{ __('Delete folder') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete" class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-4 text-center">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 dark:bg-rose-500/10"><flux:icon.trash class="size-7" /></span>
            <div>
                <flux:heading size="lg">{{ __('Delete this document?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The file is removed for good.') }}</flux:text>
            </div>
            <div class="flex w-full gap-2">
                <flux:modal.close class="flex-1"><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete" class="flex-1" data-test="confirm-delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
