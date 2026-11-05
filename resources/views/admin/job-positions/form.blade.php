@php($isEdit = (bool) $position)
<x-app-layout :title="$isEdit ? 'Ubah Jabatan' : 'Tambah Jabatan'">
    <x-ui.page-header :title="$isEdit ? 'Ubah Jabatan '.$position->name : 'Tambah Jabatan'"
                      subtitle="Role bawaan dipakai untuk akun login karyawan dengan jabatan ini."
                      :back="route('admin.job-positions.index')" />

    <form method="POST" action="{{ $isEdit ? route('admin.job-positions.update', $position) : route('admin.job-positions.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card title="Jabatan">
                    <div class="grid grid-cols-1 gap-5">
                        <x-ui.field label="Nama jabatan" for="name" :error="$errors->get('name')" required>
                            <x-text-input id="name" name="name" type="text" :value="old('name', $position?->name)" placeholder="Packer, Admin Gudang, Supervisor..." required autofocus maxlength="80" />
                        </x-ui.field>
                        <x-ui.field label="Keterangan" for="description" :error="$errors->get('description')">
                            <x-text-input id="description" name="description" type="text" :value="old('description', $position?->description)" placeholder="Tugas singkat jabatan ini (opsional)" maxlength="255" />
                        </x-ui.field>
                    </div>
                </x-ui.card>

                <x-ui.card title="Role Bawaan" subtitle="Hak akses tiap role diatur di menu Pengguna & Akses.">
                    <x-ui.field label="Role" for="role_id" :error="$errors->get('role_id')"
                                hint="Saat akun login dibuat atau dihubungkan ke karyawan berjabatan ini, role-nya otomatis diisi dengan role ini. Tetap bisa diubah manual untuk pengecualian.">
                        <x-ui.select id="role_id" name="role_id">
                            <option value="">Tanpa role bawaan</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected((int) old('role_id', $position?->role_id) === $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($isEdit && $position->employees_count > 0)
                        @can('users.update')
                            <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-ink-100 bg-ink-50/50 p-3.5">
                                <input type="checkbox" name="apply_to_accounts" value="1" @checked(old('apply_to_accounts')) class="mt-0.5 rounded border-ink-300 text-ink-950 focus:ring-ink-300">
                                <span class="text-sm text-ink-700">
                                    Terapkan juga ke {{ $position->employees_count }} akun karyawan yang sudah ada
                                    <span class="block text-xs text-ink-500">Role akun mereka diganti dengan role bawaan di atas. Akun super admin tidak diubah.</span>
                                </span>
                            </label>
                        @endcan
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Status">
                    <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $position?->is_active ?? true)" label="Aktif"
                                 description="Jabatan nonaktif tidak bisa dipilih untuk karyawan." />
                </x-ui.card>

                <div class="flex justify-end gap-2">
                    <x-ui.button :href="route('admin.job-positions.index')" variant="secondary">Batal</x-ui.button>
                    <x-ui.button type="submit" icon="check">Simpan</x-ui.button>
                </div>
            </div>
        </div>
    </form>
</x-app-layout>
