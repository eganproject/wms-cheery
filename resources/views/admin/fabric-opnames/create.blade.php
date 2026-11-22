<x-app-layout title="Buat Opname Kain">
    <x-ui.page-header title="Buat Opname Kain" subtitle="Pilih cakupan roll yang akan dihitung. Sisa tercatat tiap roll disalin saat opname dibuat."
                      :back="route('admin.fabric-opnames.index')" />

    <form method="POST" action="{{ route('admin.fabric-opnames.store') }}" class="max-w-2xl">
        @csrf
        <x-ui.card title="Cakupan Opname">
            <div class="space-y-4">
                <x-ui.field label="Tanggal" for="date" :error="$errors->get('date')" required>
                    <x-text-input id="date" name="date" type="date" :value="old('date', now()->format('Y-m-d'))" required />
                </x-ui.field>

                <x-ui.field label="Jenis kain" for="fabric_id" :error="$errors->get('fabric_id')" hint="Kosongkan untuk semua jenis kain.">
                    <x-ui.select id="fabric_id" name="fabric_id">
                        <option value="">Semua jenis kain</option>
                        @foreach ($fabrics as $fabric)
                            <option value="{{ $fabric->id }}" @selected((int) old('fabric_id') === $fabric->id)>{{ $fabric->sku }} — {{ $fabric->displayName() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Lokasi" for="location" :error="$errors->get('location')" hint="Kosongkan untuk semua lokasi.">
                    <x-ui.select id="location" name="location">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location }}" @selected(old('location') === $location)>{{ $location }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Catatan" for="note">
                    <x-text-input id="note" name="note" type="text" :value="old('note')" placeholder="Opsional" />
                </x-ui.field>

                <x-ui.button type="submit" icon="check">Buat Opname</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</x-app-layout>
