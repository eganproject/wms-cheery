@php
    $isEdit = (bool) $employee;
    $method = old('attendance_method', $employee?->attendance_method ?? $defaultMethod);
    $methodHelp = [
        'machine' => ['hash', 'Tap jari di mesin fingerprint gudang.'],
        'phone' => ['phone', 'Absen dari aplikasi Android: selfie + lokasi GPS.'],
        'both' => ['check-circle', 'Boleh dua-duanya, misalnya mesin saat di gudang, HP saat tugas luar.'],
    ];
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.employees.update', $employee) : route('admin.employees.store') }}"
      x-data="{ method: @js($method) }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Identitas">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Kode" hint="Dibuat otomatis oleh sistem.">
                        <x-text-input type="text" :value="$code" class="font-mono" disabled />
                    </x-ui.field>
                    <x-ui.field label="Nama Lengkap" for="name" :error="$errors->get('name')" required>
                        <x-text-input id="name" name="name" type="text" :value="old('name', $employee?->name)" required autofocus maxlength="120" />
                    </x-ui.field>
                    <x-ui.field label="Jabatan" for="position" :error="$errors->get('position')">
                        <x-text-input id="position" name="position" type="text" :value="old('position', $employee?->position)" placeholder="Packer, Admin Gudang, QC..." maxlength="80" />
                    </x-ui.field>
                    <x-ui.field label="Nomor HP" for="phone" :error="$errors->get('phone')">
                        <x-text-input id="phone" name="phone" type="text" :value="old('phone', $employee?->phone)" placeholder="08xxxxxxxxxx" maxlength="30" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Cara Absen">
                <p class="mb-3 text-sm text-ink-500">Pilih bagaimana karyawan ini mencatat jam masuk dan pulang.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach (\App\Models\Employee::METHODS as $value => $label)
                        <label class="flex cursor-pointer flex-col gap-1 rounded-xl border p-3.5 transition"
                               :class="method === '{{ $value }}' ? 'border-brand-500 bg-brand-50/50 ring-1 ring-brand-500' : 'border-ink-200 hover:bg-ink-50'">
                            <span class="flex items-center gap-2 text-sm font-semibold text-ink-950">
                                <input type="radio" name="attendance_method" value="{{ $value }}" x-model="method" class="h-4 w-4 text-ink-950 focus:ring-ink-300">
                                <x-icon :name="$methodHelp[$value][0]" class="h-4 w-4 text-ink-500" /> {{ $label }}
                            </span>
                            <span class="text-xs text-ink-500">{{ $methodHelp[$value][1] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('attendance_method')" class="mt-1" />

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="PIN di Mesin" for="machine_pin" :error="$errors->get('machine_pin')"
                                info="Nomor ID yang dipakai saat mendaftarkan sidik jari karyawan ini di mesin."
                                x-show="method !== 'phone'">
                        <x-text-input id="machine_pin" name="machine_pin" type="text" :value="old('machine_pin', $employee?->machine_pin)" class="font-mono" maxlength="20" placeholder="Misalnya 101" />
                        <p class="form-help" x-text="method === 'machine' ? 'Wajib untuk metode Mesin.' : 'Isi kalau karyawan juga tap di mesin.'"></p>
                    </x-ui.field>
                    <x-ui.field label="Akun Login WMS" for="user_id" :error="$errors->get('user_id')"
                                info="Karyawan login ke aplikasi HP dengan akun ini untuk absen."
                                x-show="method !== 'machine'">
                        <x-ui.select id="user_id" name="user_id">
                            <option value="">Tanpa akun</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected((int) old('user_id', $employee?->user_id) === $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                            @endforeach
                        </x-ui.select>
                        <p class="form-help" x-text="method === 'phone' ? 'Wajib untuk metode HP. Belum ada akunnya? Tambahkan dulu di menu Pengguna.' : 'Isi kalau karyawan juga absen dari HP.'"></p>
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Jam Kerja">
                <x-ui.field label="Jam kerja" for="work_shift_id" :error="$errors->get('work_shift_id')"
                            hint="Dipakai untuk menentukan telat dan pulang cepat. Atur pilihannya di menu Jam Kerja.">
                    <x-ui.select id="work_shift_id" name="work_shift_id">
                        <option value="">Belum diatur</option>
                        @foreach ($shifts as $shift)
                            <option value="{{ $shift->id }}" @selected((int) old('work_shift_id', $employee?->work_shift_id) === $shift->id)>
                                {{ $shift->name }} · {{ $shift->timeRange() }} · {{ $shift->daysLabel() }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="mt-4">
                    <x-ui.field label="Tanggal mulai kerja" for="joined_at" :error="$errors->get('joined_at')"
                                hint="Hari sebelum tanggal ini tidak dihitung tidak hadir.">
                        <x-text-input id="joined_at" name="joined_at" type="date" :value="old('joined_at', $employee?->joined_at?->format('Y-m-d') ?? ($isEdit ? null : now()->format('Y-m-d')))" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Status">
                <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $employee?->is_active ?? true)" label="Aktif"
                             description="Karyawan nonaktif tidak dihitung di absensi dan tidak bisa absen dari HP." />
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button :href="route('admin.employees.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit" icon="check">Simpan</x-ui.button>
            </div>
        </div>
    </div>
</form>
