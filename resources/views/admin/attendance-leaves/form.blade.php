@php
    $isEdit = (bool) $leave;
    $type = old('type', $leave?->type ?? 'izin');
    $start = old('start_date', $leave?->start_date?->format('Y-m-d') ?? $selectedDate ?? now()->format('Y-m-d'));
    $end = old('end_date', $leave?->end_date?->format('Y-m-d') ?? $selectedDate ?? now()->format('Y-m-d'));
@endphp
<x-app-layout :title="$isEdit ? 'Ubah Izin / Sakit / Cuti' : 'Catat Izin / Sakit / Cuti'">
    <x-ui.page-header :title="$isEdit ? 'Ubah Izin / Sakit / Cuti' : 'Catat Izin / Sakit / Cuti'"
                      subtitle="Dicatat oleh admin, tanpa persetujuan. Hari-hari ini tidak dihitung tidak hadir."
                      :back="route('admin.attendance-leaves.index')" />

    <form method="POST" enctype="multipart/form-data"
          action="{{ $isEdit ? route('admin.attendance-leaves.update', $leave) : route('admin.attendance-leaves.store') }}"
          x-data="{ type: @js($type), start: @js($start), end: @js($end), half: @js((bool) old('is_half_day', $leave?->is_half_day)) }">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card title="Keterangan Tidak Masuk">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.field label="Karyawan" for="employee_id" :error="$errors->get('employee_id')" required class="sm:col-span-2">
                            <x-ui.select id="employee_id" name="employee_id" required>
                                <option value="">Pilih karyawan</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((int) old('employee_id', $selectedEmployee) === $employee->id)>
                                        {{ $employee->name }}{{ $employee->position ? ' · '.$employee->position : '' }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            <x-input-label value="Jenis" :required="true" />
                            <div class="mt-1 flex flex-wrap gap-2">
                                @foreach (\App\Models\AttendanceLeave::TYPES as $value => $label)
                                    <label class="cursor-pointer rounded-lg border px-4 py-1.5 text-sm transition"
                                           :class="type === '{{ $value }}' ? 'border-brand-500 bg-brand-50 font-semibold text-ink-950 ring-1 ring-brand-500' : 'border-ink-200 text-ink-700 hover:bg-ink-50'">
                                        <input type="radio" name="type" value="{{ $value }}" x-model="type" class="sr-only">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('type')" class="mt-1" />
                        </div>

                        <x-ui.field label="Dari tanggal" for="start_date" :error="$errors->get('start_date')" required>
                            <x-text-input id="start_date" name="start_date" type="date" x-model="start"
                                          @change="if (end < start || half) end = start" required />
                        </x-ui.field>
                        <x-ui.field label="Sampai tanggal" for="end_date" :error="$errors->get('end_date')" required>
                            <x-text-input id="end_date" name="end_date" type="date" x-model="end" ::min="start" ::disabled="half" required />
                            <input type="hidden" name="end_date" :value="end" x-bind:disabled="! half">
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            <x-ui.toggle name="is_half_day" :checked="(bool) old('is_half_day', $leave?->is_half_day)" x-model="half" @change="if (half) end = start"
                                         label="Setengah hari"
                                         description="Untuk datang siang atau pulang lebih awal dengan izin. Jam datang/pulang hari itu tidak dihitung telat atau pulang cepat." />
                            <x-input-error :messages="$errors->get('is_half_day')" class="mt-1" />
                        </div>

                        <x-ui.field label="Alasan" for="reason" :error="$errors->get('reason')" class="sm:col-span-2">
                            <textarea id="reason" name="reason" rows="3" class="form-control" maxlength="500"
                                      placeholder="Misalnya: demam, acara keluarga, urus dokumen">{{ old('reason', $leave?->reason) }}</textarea>
                        </x-ui.field>
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Lampiran">
                    <x-ui.field for="attachment" :error="$errors->get('attachment')" hint="Foto surat dokter atau dokumen lain. JPG/PNG/PDF, maks 5 MB. Opsional.">
                        <input id="attachment" name="attachment" type="file" accept="image/*,application/pdf"
                               class="block w-full text-sm text-ink-700 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-sm file:text-ink-800 hover:file:bg-ink-200">
                    </x-ui.field>
                    @if ($leave?->attachment_path)
                        <div class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-ink-50 px-3 py-2 text-sm">
                            <a href="{{ route('admin.attendance-leaves.attachment', $leave) }}" target="_blank" rel="noopener" class="font-medium text-sky-700 hover:underline">Lampiran saat ini</a>
                            <label class="flex items-center gap-1.5 text-xs text-ink-600">
                                <input type="checkbox" name="remove_attachment" value="1" class="rounded"> Hapus
                            </label>
                        </div>
                    @endif
                </x-ui.card>

                <div class="rounded-2xl border border-ink-100 bg-ink-50 p-4 text-sm text-ink-600">
                    Absensi tanggal ini otomatis berubah jadi
                    <b x-text="{ izin: 'Izin', sakit: 'Sakit', cuti: 'Cuti' }[type]"></b>
                    dan tidak dihitung tidak hadir di rekap.
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button :href="route('admin.attendance-leaves.index')" variant="secondary">Batal</x-ui.button>
                    <x-ui.button type="submit" icon="check">Simpan</x-ui.button>
                </div>
            </div>
        </div>
    </form>
</x-app-layout>
