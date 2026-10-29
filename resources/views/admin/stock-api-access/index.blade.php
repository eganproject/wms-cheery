<x-app-layout title="Akses API Stok">
    <x-ui.page-header title="Akses API Stok" icon="key"
                      subtitle="Batasi server yang boleh membaca endpoint stok berdasarkan alamat IP sumber." />


    @can('stock-api-access.update')
        <form method="POST" action="{{ route('admin.stock-api-access.store') }}"
              class="mb-5 rounded-2xl border border-ink-100 bg-white p-5">
            @csrf
            <div class="mb-4">
                <h2 class="text-sm font-semibold text-ink-950">Izinkan IP baru</h2>
                <p class="mt-1 text-xs text-ink-400">Gunakan IP publik server pemanggil, bukan domain atau URL.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto] md:items-end">
                <x-ui.field label="Alamat IP" for="ip_address" :error="$errors->get('ip_address')" required>
                    <x-text-input id="ip_address" name="ip_address" :value="old('ip_address')"
                                  placeholder="203.0.113.10" required />
                </x-ui.field>
                <x-ui.field label="Keterangan" for="label" :error="$errors->get('label')">
                    <x-text-input id="label" name="label" :value="old('label')"
                                  placeholder="Server pusat Surabaya" />
                </x-ui.field>
                <x-ui.button type="submit" icon="plus">Izinkan IP</x-ui.button>
            </div>
        </form>
    @endcan

    <form method="GET" action="{{ route('admin.stock-api-access.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari IP atau keterangan..." class="pl-8" />
        </div>
        <x-ui.select name="status" class="sm:w-40">
            <option value="">Semua status</option>
            <option value="active" @selected(request('status') === 'active')>Aktif</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
        </x-ui.select>
        <x-ui.button type="submit" variant="secondary" icon="filter">Terapkan</x-ui.button>
        @if (request()->hasAny(['search', 'status']))
            <x-ui.button :href="route('admin.stock-api-access.index')" variant="ghost" size="icon" title="Reset filter">
                <x-icon name="refresh" class="h-4 w-4" />
            </x-ui.button>
        @endif
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($ips->isEmpty())
            <x-ui.empty-state icon="key" title="Belum ada IP yang diizinkan"
                              description="API akan menolak semua sumber sampai setidaknya satu IP aktif ditambahkan." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Alamat IP</th>
                            <th>Keterangan</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ips as $allowedIp)
                            <tr class="align-top">
                                @can('stock-api-access.update')
                                    <td>
                                            <form id="update-ip-{{ $allowedIp->id }}" method="POST"
                                                  action="{{ route('admin.stock-api-access.update', $allowedIp) }}" class="hidden">
                                                @csrf
                                                @method('PUT')
                                            </form>
                                            <x-text-input name="ip_address" :value="$allowedIp->ip_address"
                                                          form="update-ip-{{ $allowedIp->id }}"
                                                          class="min-w-44 font-mono" required />
                                    </td>
                                    <td>
                                            <x-text-input name="label" :value="$allowedIp->label"
                                                          form="update-ip-{{ $allowedIp->id }}"
                                                          placeholder="Tanpa keterangan" class="min-w-56" />
                                    </td>
                                    <td>
                                            <input type="hidden" name="is_active" value="0" form="update-ip-{{ $allowedIp->id }}">
                                            <label class="inline-flex cursor-pointer items-center gap-2">
                                                <input type="checkbox" name="is_active" value="1"
                                                       form="update-ip-{{ $allowedIp->id }}"
                                                       @checked($allowedIp->is_active)
                                                       class="rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                                <span class="text-sm text-ink-600">Aktif</span>
                                            </label>
                                    </td>
                                    <td>
                                            <div class="flex items-center justify-end gap-1">
                                                <x-ui.button type="submit" form="update-ip-{{ $allowedIp->id }}"
                                                             variant="secondary" size="sm" icon="check">Simpan</x-ui.button>
                                @else
                                    <td class="font-mono text-sm font-semibold text-ink-950">{{ $allowedIp->ip_address }}</td>
                                    <td class="text-sm text-ink-600">{{ $allowedIp->label ?: '—' }}</td>
                                    <td>
                                        <x-ui.badge :variant="$allowedIp->is_active ? 'success' : 'danger'">
                                            {{ $allowedIp->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </x-ui.badge>
                                    </td>
                                    <td><div class="flex justify-end">
                                @endcan
                                                @can('stock-api-access.delete')
                                                    <x-ui.confirm-delete :action="route('admin.stock-api-access.destroy', $allowedIp)"
                                                                         title="Hapus akses IP ini?"
                                                                         :description="$allowedIp->ip_address.' tidak akan dapat mengakses API stok lagi.'" />
                                                @endcan
                                            </div>
                                        </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$ips" />
        @endif
    </div>

    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">Perhatian</p>
        <p class="mt-1 text-xs leading-5 text-amber-800">
            Bearer token tetap wajib. Perubahan status IP berlaku pada request berikutnya; menonaktifkan IP lebih aman
            daripada menghapusnya bila akses hanya dihentikan sementara.
        </p>
    </div>
</x-app-layout>
