<div class="grid grid-cols-1 gap-3 sm:grid-cols-2" x-data="{
        coords: @js(old('coordinates', $location ? $location->latitude.', '.$location->longitude : '')),
        locating: false,
        error: '',
        useMine() {
            if (! navigator.geolocation) { this.error = 'Browser ini tidak bisa membaca lokasi.'; return; }
            this.locating = true; this.error = '';
            navigator.geolocation.getCurrentPosition(
                (pos) => { this.coords = pos.coords.latitude.toFixed(7) + ', ' + pos.coords.longitude.toFixed(7); this.locating = false; },
                () => { this.error = 'Lokasi tidak bisa dibaca. Izinkan akses lokasi di browser.'; this.locating = false; },
                { enableHighAccuracy: true, timeout: 15000 },
            );
        },
    }">
    <x-ui.field label="Nama lokasi" :error="$location ? null : $errors->get('name')" required>
        <x-text-input name="name" :value="old('name', $location?->name ?? 'Gudang')" required maxlength="120" />
    </x-ui.field>
    <x-ui.field label="Radius (meter)" info="Absen dari HP ditolak kalau posisi karyawan lebih jauh dari ini." :error="$location ? null : $errors->get('radius_meters')" required>
        <x-text-input name="radius_meters" type="number" min="20" max="5000" :value="old('radius_meters', $location?->radius_meters ?? 100)" required />
    </x-ui.field>
    <x-ui.field label="Titik koordinat" class="sm:col-span-2" :error="$location ? null : ($errors->get('latitude') ?: $errors->get('longitude'))" required
                hint="Tempel dari Google Maps (klik kanan titik gudang › salin koordinat), atau pakai lokasi perangkat ini saat berada di gudang.">
        <div class="flex gap-2">
            <x-text-input name="coordinates" x-model="coords" class="font-mono" placeholder="-7.2575, 112.7521" required />
            <button type="button" class="btn btn-default shrink-0" @click="useMine()" :disabled="locating">
                <x-icon name="map-pin" class="h-3.5 w-3.5" /><span x-text="locating ? 'Membaca...' : 'Lokasi saya'"></span>
            </button>
        </div>
        <p class="mt-1 text-xs text-rose-600" x-show="error" x-text="error"></p>
    </x-ui.field>
    <div class="sm:col-span-2">
        <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $location?->is_active ?? true)" label="Aktif" />
    </div>
</div>
