@php
    $canEdit = auth()->user()->can('permissions.update') && $role && ! $role->is_super_admin;
    $actionLabels = [
        'view' => ['Lihat', 'neutral'], 'create' => ['Tambah', 'info'], 'update' => ['Ubah', 'info'],
        'delete' => ['Hapus', 'danger'], 'approve' => ['Setujui', 'success'], 'post' => ['Posting', 'success'],
        'cancel' => ['Batalkan', 'danger'], 'export' => ['Unduh', 'neutral'], 'import' => ['Impor', 'info'],
        'scan' => ['Scan', 'info'],
    ];
    $groups = $permissionGroups->map(fn ($permissions, $group) => [
        'name' => $group,
        'ids' => $permissions->pluck('id')->all(),
    ])->values();
@endphp
<x-app-layout title="Hak Akses">
    <x-ui.page-header title="Hak Akses" icon="key"
                      subtitle="Pilih role di kiri, lalu centang menu dan aksi yang boleh dipakai. Jabatan mengikuti role bawaannya." />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
        {{-- Daftar role --}}
        <aside class="space-y-2 lg:sticky lg:top-4 lg:self-start">
            <p class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">Pilih role</p>
            @foreach ($roles as $item)
                @php($active = $role && $item->is($role))
                <a href="{{ route('admin.permissions.index', ['role' => $item->id]) }}"
                   @class([
                       'block rounded-xl border p-3.5 transition',
                       'border-ink-950 bg-white ring-1 ring-ink-950' => $active,
                       'border-ink-100 bg-white hover:border-ink-300' => ! $active,
                   ])>
                    <span class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-ink-950">{{ $item->name }}</span>
                        @if ($item->is_super_admin)
                            <x-ui.badge variant="purple">Semua akses</x-ui.badge>
                        @else
                            <span class="text-xs text-ink-500">{{ $item->permissions->count() }} akses</span>
                        @endif
                    </span>
                    <span class="mt-1 block text-xs text-ink-500">{{ $item->users_count }} pengguna</span>
                    @if ($item->jobPositions->isNotEmpty())
                        <span class="mt-2 flex flex-wrap gap-1">
                            @foreach ($item->jobPositions as $position)
                                <span class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">{{ $position->name }}</span>
                            @endforeach
                        </span>
                    @endif
                </a>
            @endforeach
            @can('roles.create')
                <a href="{{ route('admin.roles.create') }}" class="flex items-center gap-2 rounded-xl border border-dashed border-ink-200 p-3 text-sm text-ink-600 hover:bg-ink-50">
                    <x-icon name="plus" class="h-4 w-4" /> Tambah role
                </a>
            @endcan
        </aside>

        {{-- Akses role terpilih --}}
        @if (! $role)
            <x-ui.empty-state icon="key" title="Belum ada role" description="Tambahkan role dulu di tab Role." />
        @elseif ($role->is_super_admin)
            <x-ui.card :title="$role->name">
                <div class="flex items-start gap-3 rounded-xl bg-ink-50 p-4 text-sm text-ink-700">
                    <x-icon name="shield" class="mt-0.5 h-5 w-5 shrink-0 text-ink-500" />
                    <p>Role ini selalu boleh membuka semua menu dan melakukan semua aksi, jadi tidak perlu diatur. Pilih role lain di kiri untuk mengatur hak aksesnya.</p>
                </div>
            </x-ui.card>
        @else
            <form method="POST" action="{{ route('admin.permissions.update') }}"
                  x-data="{
                      selected: @js(array_map('intval', $assigned)),
                      original: @js(array_map('intval', $assigned)),
                      groups: @js($groups),
                      grantable: @js($grantable),
                      search: '',
                      open: {},
                      allowed(id) { return this.grantable === null || this.grantable.includes(id) },
                      has(id) { return this.selected.includes(id) },
                      toggle(id) {
                          if (! this.allowed(id)) return;
                          this.selected = this.has(id) ? this.selected.filter(x => x !== id) : [...this.selected, id];
                      },
                      countIn(group) { return group.ids.filter(id => this.has(id)).length },
                      toggleGroup(group) {
                          const ids = group.ids.filter(id => this.allowed(id));
                          const all = ids.every(id => this.has(id));
                          this.selected = all
                              ? this.selected.filter(id => ! ids.includes(id))
                              : [...new Set([...this.selected, ...ids])];
                      },
                      isOpen(name) { return this.search !== '' || (this.open[name] ?? false) },
                      setAll(value) { this.groups.forEach(g => this.open[g.name] = value) },
                      matches(text) { return this.search === '' || text.toLowerCase().includes(this.search.toLowerCase()) },
                      get changes() {
                          const added = this.selected.filter(id => ! this.original.includes(id)).length;
                          const removed = this.original.filter(id => ! this.selected.includes(id)).length;
                          return added + removed;
                      },
                      reset() { this.selected = [...this.original] },
                  }">
                @csrf
                @method('PUT')
                <input type="hidden" name="role_id" value="{{ $role->id }}">
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="permissions[]" :value="id">
                </template>

                <div class="rounded-2xl border border-ink-100 bg-white">
                    <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-ink-950">{{ $role->name }}</h2>
                                <p class="mt-0.5 text-sm text-ink-500">
                                    <span x-text="selected.length"></span> dari {{ $permissionGroups->flatten()->count() }} akses aktif
                                    @if ($role->jobPositions->isNotEmpty())
                                        · dipakai jabatan {{ $role->jobPositions->pluck('name')->join(', ', ' dan ') }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex gap-2 text-xs">
                                <button type="button" x-on:click="setAll(true)" class="rounded-lg px-2.5 py-1.5 font-medium text-ink-600 hover:bg-ink-100">Buka semua</button>
                                <button type="button" x-on:click="setAll(false)" class="rounded-lg px-2.5 py-1.5 font-medium text-ink-600 hover:bg-ink-100">Tutup semua</button>
                            </div>
                        </div>
                        <div class="relative mt-3">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                                <x-icon name="search" class="h-4 w-4" />
                            </span>
                            <x-text-input type="search" x-model.debounce.150ms="search" placeholder="Cari menu atau aksi, misalnya: barang, hapus, laporan..." class="pl-8" />
                        </div>
                        @if ($grantable !== null)
                            <p class="mt-2 flex items-start gap-1.5 text-xs text-ink-500">
                                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0" />
                                Akses yang abu-abu tidak Anda miliki sendiri, jadi tidak bisa Anda berikan.
                            </p>
                        @endif
                    </div>

                    <div class="divide-y divide-ink-100">
                        @foreach ($permissionGroups as $group => $permissions)
                            @php($groupIndex = $loop->index)
                            <section x-show="matches(@js($group.' '.$permissions->pluck('name')->join(' ')))">
                                <div class="flex items-center gap-3 px-5 py-3 sm:px-6">
                                    <button type="button" x-on:click="open[groups[{{ $groupIndex }}].name] = ! isOpen(groups[{{ $groupIndex }}].name)"
                                            class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                        <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-400 transition"
                                                x-bind:class="isOpen(groups[{{ $groupIndex }}].name) && 'rotate-90'" />
                                        <span class="truncate text-sm font-semibold text-ink-950">{{ $group }}</span>
                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                              x-bind:class="countIn(groups[{{ $groupIndex }}]) === 0 ? 'bg-ink-100 text-ink-500'
                                                  : (countIn(groups[{{ $groupIndex }}]) === groups[{{ $groupIndex }}].ids.length ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700')"
                                              x-text="countIn(groups[{{ $groupIndex }}]) + '/' + groups[{{ $groupIndex }}].ids.length"></span>
                                    </button>
                                    @if ($canEdit)
                                        <button type="button" x-on:click="toggleGroup(groups[{{ $groupIndex }}])"
                                                class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100"
                                                x-text="groups[{{ $groupIndex }}].ids.filter(id => allowed(id)).every(id => has(id)) ? 'Kosongkan' : 'Pilih semua'"></button>
                                    @endif
                                </div>

                                <div x-show="isOpen(groups[{{ $groupIndex }}].name)" x-cloak class="grid grid-cols-1 gap-2 px-5 pb-4 sm:grid-cols-2 sm:px-6">
                                    @foreach ($permissions as $permission)
                                        @php([$actionLabel, $actionVariant] = $actionLabels[\Illuminate\Support\Str::afterLast($permission->slug, '.')] ?? ['Akses', 'neutral'])
                                        <label x-show="matches(@js($group.' '.$permission->name.' '.$actionLabel))"
                                               title="{{ $permission->slug }}"
                                               class="flex items-center gap-3 rounded-xl border p-3 transition"
                                               x-bind:class="[
                                                   has({{ $permission->id }}) ? 'border-ink-950 bg-ink-50/70' : 'border-ink-100',
                                                   allowed({{ $permission->id }}) && {{ $canEdit ? 'true' : 'false' }} ? 'cursor-pointer hover:bg-ink-50' : 'cursor-not-allowed opacity-50',
                                               ]">
                                            <input type="checkbox" class="h-4 w-4 shrink-0 rounded border-ink-300 text-ink-950 focus:ring-ink-300"
                                                   x-bind:checked="has({{ $permission->id }})"
                                                   x-on:change="toggle({{ $permission->id }})"
                                                   @disabled(! $canEdit)
                                                   x-bind:disabled="{{ $canEdit ? 'false' : 'true' }} || ! allowed({{ $permission->id }})">
                                            <span class="min-w-0 flex-1 text-sm text-ink-950">{{ $permission->name }}</span>
                                            <x-ui.badge :variant="$actionVariant">{{ $actionLabel }}</x-ui.badge>
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                @if ($canEdit)
                    <div class="sticky bottom-4 z-20 mt-4 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white/95 px-5 py-3 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm" x-bind:class="changes ? 'font-medium text-amber-700' : 'text-ink-500'"
                           x-text="changes ? changes + ' perubahan belum disimpan' : 'Tidak ada perubahan'"></p>
                        <div class="flex gap-2">
                            <x-ui.button type="button" variant="secondary" x-on:click="reset()" x-bind:disabled="! changes">Batal</x-ui.button>
                            <x-ui.button type="submit" icon="check" x-bind:disabled="! changes">Simpan hak akses {{ $role->name }}</x-ui.button>
                        </div>
                    </div>
                @endif
            </form>
        @endif
    </div>
</x-app-layout>
