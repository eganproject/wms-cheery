@php
    $isEdit = (bool) $shift;
    $days = collect(old('work_days', $shift?->work_days ?? [1, 2, 3, 4, 5, 6]))->map(fn ($day) => (int) $day)->all();
@endphp
<x-app-layout :title="$isEdit ? 'Ubah Jam Kerja' : 'Tambah Jam Kerja'">
    <x-ui.page-header :title="$isEdit ? 'Ubah Jam Kerja '.$shift->name : 'Tambah Jam Kerja'"
                      subtitle="Perubahan berlaku juga untuk hari-hari yang sudah lewat."
                      :back="route('admin.work-shifts.index')" />

    <form method="POST" action="{{ $isEdit ? route('admin.work-shifts.update', $shift) : route('admin.work-shifts.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card title="Jam">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.field label="Nama" for="name" :error="$errors->get('name')" required class="sm:col-span-2">
                            <x-text-input id="name" name="name" type="text" :value="old('name', $shift?->name)" placeholder="Reguler, Pagi, Siang..." required maxlength="60" />
                        </x-ui.field>
                        <x-ui.field label="Jam masuk" for="start_time" :error="$errors->get('start_time')" required>
                            <x-text-input id="start_time" name="start_time" type="time" :value="old('start_time', $shift ? substr($shift->start_time, 0, 5) : '08:00')" required />
                        </x-ui.field>
                        <x-ui.field label="Jam pulang" for="end_time" :error="$errors->get('end_time')" required
                                    hint="Lebih kecil dari jam masuk berarti pulang keesokan harinya (shift malam).">
                            <x-text-input id="end_time" name="end_time" type="time" :value="old('end_time', $shift ? substr($shift->end_time, 0, 5) : '17:00')" required />
                        </x-ui.field>
                        <x-ui.field label="Istirahat mulai" for="break_start" :error="$errors->get('break_start')">
                            <x-text-input id="break_start" name="break_start" type="time" :value="old('break_start', $shift?->break_start ? substr($shift->break_start, 0, 5) : null)" />
                        </x-ui.field>
                        <x-ui.field label="Istirahat selesai" for="break_end" :error="$errors->get('break_end')" hint="Hanya keterangan; tidak perlu tap saat istirahat.">
                            <x-text-input id="break_end" name="break_end" type="time" :value="old('break_end', $shift?->break_end ? substr($shift->break_end, 0, 5) : null)" />
                        </x-ui.field>
                    </div>
                </x-ui.card>

                <x-ui.card title="Hari Kerja">
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Models\WorkShift::DAY_NAMES as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-200 px-3 py-1.5 text-sm hover:bg-ink-50 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:font-semibold">
                                <input type="checkbox" name="work_days[]" value="{{ $value }}" @checked(in_array($value, $days, true)) class="rounded border-ink-300 text-ink-950 focus:ring-ink-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('work_days')" class="mt-1" />
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Aturan">
                    <x-ui.field label="Toleransi telat (menit)" for="late_tolerance_minutes" :error="$errors->get('late_tolerance_minutes')" required
                                hint="Masuk sampai sekian menit setelah jam masuk masih dihitung tepat waktu.">
                        <x-text-input id="late_tolerance_minutes" name="late_tolerance_minutes" type="number" min="0" max="240"
                                      :value="old('late_tolerance_minutes', $shift?->late_tolerance_minutes ?? 10)" required />
                    </x-ui.field>
                    <div class="mt-4">
                        <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $shift?->is_active ?? true)" label="Aktif"
                                     description="Jam kerja nonaktif tidak bisa dipilih untuk karyawan baru." />
                    </div>
                </x-ui.card>

                <div class="flex justify-end gap-2">
                    <x-ui.button :href="route('admin.work-shifts.index')" variant="secondary">Batal</x-ui.button>
                    <x-ui.button type="submit" icon="check">Simpan</x-ui.button>
                </div>
            </div>
        </div>
    </form>
</x-app-layout>
