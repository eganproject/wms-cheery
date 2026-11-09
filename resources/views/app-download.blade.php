<x-guest-layout title="Unduh Aplikasi Android" heading="WMS Cheery Scan"
                subheading="Aplikasi Android untuk Scan Kemas, Serah Terima, Terima Retur, dan absen.">
    @if ($latest && $downloadUrl)
        <div class="text-center">
            <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Versi terbaru</p>
            <p class="mt-1 text-2xl font-bold text-ink-950">{{ $latest['version_name'] }}</p>
            @if ($latest['size'])
                <p class="mt-1 text-xs text-ink-500">{{ number_format($latest['size'] / 1048576, 1, ',', '.') }} MB</p>
            @endif
        </div>

        <x-ui.button :href="$downloadUrl" size="lg" icon="download" class="mt-5 w-full justify-center">
            Unduh APK
        </x-ui.button>

        @if ($latest['notes'])
            <div class="mt-5 rounded-lg bg-ink-50 px-3 py-2 text-xs leading-5 text-ink-600">
                <p class="font-semibold text-ink-800">Yang baru</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $latest['notes'] }}</p>
            </div>
        @endif

        <div class="mt-5 border-t border-ink-100 pt-4">
            <p class="text-sm font-semibold text-ink-950">Cara pasang</p>
            <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm text-ink-600">
                <li>Tekan <b>Unduh APK</b>, tunggu sampai selesai.</li>
                <li>Buka berkas <b>wms-cheery-scan.apk</b> dari notifikasi atau folder Download.</li>
                <li>Bila muncul peringatan, izinkan <b>“Instal aplikasi tidak dikenal”</b> untuk browser ini, lalu tekan <b>Instal</b>.</li>
                <li>Buka aplikasinya, isi alamat server dan masuk dengan akun WMS.</li>
            </ol>
            <p class="mt-3 text-xs text-ink-400">Setelah terpasang, versi berikutnya ditawarkan langsung dari dalam aplikasi.</p>
        </div>
    @else
        <div class="text-center">
            <x-icon name="phone" class="mx-auto h-8 w-8 text-ink-300" />
            <p class="mt-3 text-sm font-medium text-ink-800">Aplikasi belum tersedia</p>
            <p class="mt-1 text-xs text-ink-500">Admin belum mengunggah APK di Pengaturan &gt; Aplikasi Android.</p>
        </div>
    @endif

    <div class="mt-5 text-center">
        <a href="{{ route('login') }}" class="text-xs text-ink-500 hover:text-ink-800">Masuk ke WMS</a>
    </div>
</x-guest-layout>
