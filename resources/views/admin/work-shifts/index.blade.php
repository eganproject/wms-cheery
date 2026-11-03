<x-app-layout title="Jam Kerja">
    <x-ui.page-header title="Jam Kerja" icon="clock"
                      subtitle="Jam masuk dan pulang yang dipakai untuk menentukan telat dan pulang cepat.">
        <x-slot name="actions">
            @can('work-shifts.update')
                <x-ui.button :href="route('admin.work-shifts.create')" icon="plus">Tambah Jam Kerja</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($shifts->isEmpty())
            <x-ui.empty-state icon="clock" title="Belum ada jam kerja"
                              description="Buat minimal satu jam kerja, misalnya Reguler 08:00–17:00 Senin–Sabtu, lalu pilih di data karyawan.">
                @can('work-shifts.update')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.work-shifts.create')" icon="plus">Tambah Jam Kerja</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Masuk</th>
                            <th>Pulang</th>
                            <th>Istirahat</th>
                            <th>Hari kerja</th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Toleransi telat <x-ui.hint text="Masuk sampai sekian menit setelah jam masuk masih dihitung tepat waktu. Lewat dari itu, telatnya dihitung dari jam masuk." /></span></th>
                            <th class="text-right">Karyawan</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shifts as $shift)
                            <tr>
                                <td class="text-sm font-medium text-ink-950">{{ $shift->name }}</td>
                                <td class="text-sm tabular-nums">{{ substr($shift->start_time, 0, 5) }}</td>
                                <td class="text-sm tabular-nums">
                                    {{ substr($shift->end_time, 0, 5) }}
                                    @if ($shift->end_time <= $shift->start_time)
                                        <span class="text-[11px] text-ink-400">(besoknya)</span>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-600">{{ $shift->breakRange() ?? '—' }}</td>
                                <td class="text-sm text-ink-600">{{ $shift->daysLabel() }}</td>
                                <td class="text-right text-sm">{{ $shift->late_tolerance_minutes }} menit</td>
                                <td class="text-right text-sm font-semibold">{{ $shift->employees_count }}</td>
                                <td><x-ui.badge :variant="$shift->is_active ? 'success' : 'danger'">{{ $shift->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('work-shifts.update')
                                            <a href="{{ route('admin.work-shifts.edit', $shift) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                            <x-ui.confirm-delete :action="route('admin.work-shifts.destroy', $shift)"
                                                                 title="Hapus jam kerja ini?"
                                                                 :description="'Jam kerja '.$shift->name.' akan dihapus. Jam kerja yang masih dipakai karyawan tidak bisa dihapus.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <x-ui.card class="mt-6" title="Hari Libur Nasional & Cuti Bersama"
               subtitle="Tanggal di sini tidak dihitung hari kerja untuk semua karyawan.">
        <x-slot name="actions">
            <form method="GET" action="{{ route('admin.work-shifts.index') }}" data-auto-submit>
                <x-ui.select name="year" class="w-28">
                    @foreach (range(now()->year - 1, now()->year + 1) as $option)
                        <option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>
                    @endforeach
                </x-ui.select>
            </form>
        </x-slot>

        @can('work-shifts.update')
            <form method="POST" action="{{ route('admin.holidays.store') }}" class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                @csrf
                <div class="sm:w-48">
                    <x-text-input type="date" name="date" :value="old('date')" required />
                    <x-input-error :messages="$errors->get('date')" class="mt-1" />
                </div>
                <div class="flex-1">
                    <x-text-input type="text" name="name" :value="old('name')" placeholder="Nama hari libur, misalnya Idul Fitri" required maxlength="120" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <x-ui.button type="submit" variant="secondary" icon="plus">Tambah</x-ui.button>
            </form>
        @endcan

        @if ($holidays->isEmpty())
            <p class="text-sm text-ink-500">Belum ada hari libur di tahun {{ $year }}.</p>
        @else
            <div class="divide-y divide-ink-100 rounded-xl border border-ink-100">
                @foreach ($holidays as $holiday)
                    <div class="flex items-center justify-between gap-3 px-3 py-2">
                        <div class="flex items-center gap-3">
                            <span class="w-32 text-sm tabular-nums text-ink-700">{{ $holiday->date->translatedFormat('D, j M Y') }}</span>
                            <span class="text-sm text-ink-950">{{ $holiday->name }}</span>
                        </div>
                        @can('work-shifts.update')
                            <x-ui.confirm-delete :action="route('admin.holidays.destroy', $holiday)" title="Hapus hari libur ini?"
                                                 :description="$holiday->name.' akan kembali dihitung hari kerja.'" />
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</x-app-layout>
