@php($canEdit = auth()->user()->can('attendance-settings.update'))
<x-app-layout title="Lokasi & Mesin Absen">
    <x-ui.page-header title="Lokasi & Mesin Absen" icon="map-pin"
                      subtitle="Tempat absen lewat HP diperbolehkan, mesin fingerprint yang terhubung, dan aturan absen." />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Lokasi Absen lewat HP" subtitle="Absen dari HP hanya diterima dalam radius salah satu lokasi aktif.">
                @forelse ($locations as $location)
                    @php($pad = max(0.002, $location->radius_meters / 111000 * 2.5))
                    <div class="mb-5 rounded-xl border border-ink-100 p-4" x-data="{ edit: false }">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
                            <iframe class="h-44 w-full rounded-lg border border-ink-100 md:col-span-2" loading="lazy" title="Peta {{ $location->name }}"
                                    src="https://www.openstreetmap.org/export/embed.html?bbox={{ $location->longitude - $pad }},{{ $location->latitude - $pad }},{{ $location->longitude + $pad }},{{ $location->latitude + $pad }}&layer=mapnik&marker={{ $location->latitude }},{{ $location->longitude }}"></iframe>
                            <div class="md:col-span-3">
                                <div class="flex items-start justify-between gap-2" x-show="! edit">
                                    <div>
                                        <p class="flex items-center gap-2 text-sm font-semibold text-ink-950">
                                            {{ $location->name }}
                                            <x-ui.badge :variant="$location->is_active ? 'success' : 'danger'">{{ $location->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                                        </p>
                                        <p class="mt-1 font-mono text-xs text-ink-500">{{ $location->latitude }}, {{ $location->longitude }}</p>
                                        <p class="mt-1 text-sm text-ink-700">Radius <b>{{ $location->radius_meters }} m</b></p>
                                        <a href="https://www.google.com/maps?q={{ $location->latitude }},{{ $location->longitude }}" target="_blank" rel="noopener"
                                           class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-sky-700 hover:underline">
                                            <x-icon name="external-link" class="h-3.5 w-3.5" />Buka di Google Maps
                                        </a>
                                    </div>
                                    @if ($canEdit)
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="edit = true" title="Ubah" class="btn btn-ghost btn-icon text-ink-500"><x-icon name="pencil" class="h-3.5 w-3.5" /></button>
                                            <x-ui.confirm-delete :action="route('admin.attendance-settings.locations.destroy', $location)" title="Hapus lokasi ini?"
                                                                 :description="'Absen HP di sekitar '.$location->name.' tidak diterima lagi.'" />
                                        </div>
                                    @endif
                                </div>
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('admin.attendance-settings.locations.update', $location) }}" x-show="edit" x-cloak class="space-y-3">
                                        @csrf @method('PUT')
                                        @include('admin.attendance-settings._location-fields', ['location' => $location])
                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="btn btn-default" @click="edit = false">Batal</button>
                                            <x-ui.button type="submit" icon="check">Simpan</x-ui.button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        Belum ada lokasi. Karyawan belum bisa absen dari HP sampai lokasi gudang ditambahkan.
                    </div>
                @endforelse

                @if ($canEdit)
                    <div x-data="{ open: {{ $locations->isEmpty() || $errors->hasAny(['name', 'coordinates', 'latitude', 'longitude', 'radius_meters']) ? 'true' : 'false' }} }">
                        <x-ui.button type="button" variant="secondary" icon="plus" x-show="! open" @click="open = true">Tambah Lokasi</x-ui.button>
                        <form method="POST" action="{{ route('admin.attendance-settings.locations.store') }}" x-show="open" x-cloak
                              class="space-y-3 rounded-xl border border-dashed border-ink-200 p-4">
                            @csrf
                            <p class="text-sm font-semibold text-ink-950">Lokasi baru</p>
                            @include('admin.attendance-settings._location-fields', ['location' => null])
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="submit" icon="check">Simpan Lokasi</x-ui.button>
                            </div>
                        </form>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Mesin Fingerprint" subtitle="Mesin ZKTeco yang mendukung Cloud Server / ADMS (misalnya X100-C, SpeedFace, MB-series).">
                @if ($devices->isEmpty())
                    <p class="mb-4 text-sm text-ink-500">Belum ada mesin terdaftar.</p>
                @else
                    <div class="mb-4 overflow-x-auto">
                        <table class="erp-table">
                            <thead>
                                <tr>
                                    <th>Mesin</th>
                                    <th>Nomor seri</th>
                                    <th>Status</th>
                                    <th>Terakhir terhubung</th>
                                    <th class="text-right">Tap hari ini</th>
                                    @if ($canEdit)<th class="text-right">Aksi</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($devices as $device)
                                    <tr x-data="{ edit: false }">
                                        <td class="text-sm font-medium text-ink-950">
                                            <span x-show="! edit">{{ $device->name }}</span>
                                            @if ($canEdit)
                                                <form x-show="edit" x-cloak method="POST" action="{{ route('admin.attendance-settings.devices.update', $device) }}" id="device-{{ $device->id }}" class="flex flex-col gap-2">
                                                    @csrf @method('PUT')
                                                    <x-text-input name="name" :value="$device->name" required maxlength="120" />
                                                    <x-text-input name="serial_number" :value="$device->serial_number" class="font-mono" required maxlength="60" />
                                                    <x-ui.toggle name="is_active" :checked="$device->is_active" label="Aktif" />
                                                    <div class="flex gap-2">
                                                        <button type="button" class="btn btn-default" @click="edit = false">Batal</button>
                                                        <x-ui.button type="submit">Simpan</x-ui.button>
                                                    </div>
                                                </form>
                                            @endif
                                        </td>
                                        <td class="font-mono text-xs">{{ $device->serial_number }}</td>
                                        <td>
                                            @if (! $device->is_active)
                                                <x-ui.badge variant="neutral">Nonaktif</x-ui.badge>
                                            @elseif ($device->isOnline())
                                                <x-ui.badge variant="success">Online</x-ui.badge>
                                            @elseif ($device->last_seen_at)
                                                <x-ui.badge variant="danger">Offline</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="warning">Belum terhubung</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="text-sm text-ink-600">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="text-right text-sm font-semibold">{{ $device->today_count }}</td>
                                        @if ($canEdit)
                                            <td>
                                                <div class="flex items-center justify-end gap-1">
                                                    <button type="button" @click="edit = true" title="Ubah" class="btn btn-ghost btn-icon text-ink-500"><x-icon name="pencil" class="h-3.5 w-3.5" /></button>
                                                    <x-ui.confirm-delete :action="route('admin.attendance-settings.devices.destroy', $device)" title="Hapus mesin ini?"
                                                                         description="Mesin yang sudah punya riwayat tap hanya dinonaktifkan." />
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($canEdit)
                    <form method="POST" action="{{ route('admin.attendance-settings.devices.store') }}"
                          class="flex flex-col gap-3 rounded-xl border border-dashed border-ink-200 p-4 sm:flex-row sm:items-start">
                        @csrf
                        <div class="flex-1">
                            <x-text-input name="name" :value="old('name')" placeholder="Nama mesin, misalnya Pintu masuk gudang" required maxlength="120" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div class="sm:w-56">
                            <x-text-input name="serial_number" :value="old('serial_number')" placeholder="Nomor seri (SN)" class="font-mono" required maxlength="60" />
                            <x-input-error :messages="$errors->get('serial_number')" class="mt-1" />
                        </div>
                        <x-ui.button type="submit" variant="secondary" icon="plus">Tambah Mesin</x-ui.button>
                    </form>
                @endif

                @if ($unmatchedPins->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-semibold text-amber-900">PIN mesin yang belum terhubung ke karyawan</p>
                        <p class="mt-0.5 text-xs text-amber-800">Tap dari PIN ini tersimpan, tapi belum masuk absensi siapa pun. Isi PIN-nya di data karyawan, tap lamanya langsung ikut tersambung.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($unmatchedPins as $pin)
                                <span class="rounded-md bg-white px-2 py-1 font-mono text-xs text-ink-800 ring-1 ring-amber-200">PIN {{ $pin->machine_pin }} · {{ $pin->total }} tap</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Aturan Absen">
                <form method="POST" action="{{ route('admin.attendance-settings.update') }}" class="space-y-4">
                    @csrf @method('PUT')
                    <x-ui.toggle name="require_selfie" :checked="$requireSelfie" label="Wajib selfie"
                                 description="Foto wajah diambil saat menekan Absen di HP, supaya tidak bisa dititipkan." :disabled="! $canEdit" />
                    <x-ui.toggle name="allow_outside_radius" :checked="$allowOutside" label="Izinkan absen di luar radius"
                                 description="Tetap tercatat, tapi diberi tanda 'Di luar lokasi' untuk dicek admin." :disabled="! $canEdit" />
                    <x-ui.field label="Metode untuk karyawan baru" for="default_method" hint="Bisa diganti per karyawan.">
                        <x-ui.select id="default_method" name="default_method" :disabled="! $canEdit">
                            @foreach (\App\Models\Employee::METHODS as $value => $label)
                                <option value="{{ $value }}" @selected($defaultMethod === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    @if ($canEdit)
                        <div class="flex justify-end"><x-ui.button type="submit" icon="check">Simpan Aturan</x-ui.button></div>
                    @endif
                </form>
            </x-ui.card>

            <x-ui.card title="Cara Menghubungkan Mesin">
                <ol class="list-decimal space-y-2 pl-4 text-sm text-ink-600">
                    <li>Daftarkan nomor seri mesin di halaman ini (ada di stiker belakang mesin, atau menu <b>System Info</b>).</li>
                    <li>Di mesin, buka <b>Menu › Comm › Cloud Server Setting</b>.</li>
                    <li>Alamat server: <span class="font-mono text-xs text-ink-950">{{ $serverHost }}</span>, port <b>443</b>, aktifkan HTTPS. Mode proxy: mati.</li>
                    <li>Daftarkan sidik jari karyawan di mesin, lalu isi <b>PIN</b> (ID User di mesin) di data karyawan.</li>
                    <li>Status mesin di atas berubah jadi <b>Online</b> dalam 1 menit.</li>
                </ol>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
