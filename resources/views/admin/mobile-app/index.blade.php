<x-app-layout title="Aplikasi Android">
    <x-ui.page-header title="Aplikasi Android" icon="phone"
                      subtitle="Unggah APK WMS Cheery Scan versi baru. HP yang sudah terpasang akan ditawari pembaruan saat aplikasinya dibuka." />

    <div class="mb-5 grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        @can('mobile-app.update')
            <form method="POST" action="{{ route('admin.mobile-app.store') }}" enctype="multipart/form-data"
                  class="rounded-2xl border border-ink-100 bg-white p-5" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-ink-950">Unggah versi baru</h2>
                    <p class="mt-1 text-xs text-ink-400">Nomor versi dibaca otomatis dari APK. Bila kiriman otomatis GitHub sudah diatur (DEPLOY.md bagian 9), APK baru masuk sendiri dan unggah manual tidak perlu.</p>
                </div>

                <div class="space-y-4">
                    <x-ui.field label="Berkas APK" for="apk" :error="$errors->get('apk')" required
                                :hint="'Berkas .apk WMS Cheery Scan. Batas upload server: '.($maxUploadMb ?: '?').' MB.'"
                                info="APK harus ditandatangani kunci yang sama dengan versi yang sudah terpasang di HP, kalau tidak Android menolak pembaruannya.">
                        <input id="apk" name="apk" type="file" accept=".apk,application/vnd.android.package-archive" required
                               class="block w-full text-sm text-ink-700 file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-sm file:text-ink-800 hover:file:bg-ink-200">
                    </x-ui.field>

                    @if ($maxUploadMb && $maxUploadMb < 60)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                            Batas upload server hanya {{ $maxUploadMb }} MB, sedangkan APK sekitar 30 MB.
                            Naikkan <code>upload_max_filesize</code> dan <code>post_max_size</code> di php.ini (mis. 100M), lalu restart server.
                        </div>
                    @endif

                    <x-ui.field label="Yang baru" for="notes" :error="$errors->get('notes')"
                                info="Ditampilkan di jendela pembaruan di HP. Opsional.">
                        <textarea id="notes" name="notes" rows="3" maxlength="1000" class="form-control h-auto py-2"
                                  placeholder="mis. Scan resi lebih cepat, perbaikan foto absen.">{{ old('notes') }}</textarea>
                    </x-ui.field>

                    <x-ui.toggle name="is_required" :checked="(bool) old('is_required')" label="Wajib diperbarui"
                                 description="HP dengan versi lebih lama tidak bisa memakai aplikasi sebelum memasang versi ini." />

                    <div class="flex justify-end">
                        <x-ui.button type="submit" icon="upload" x-bind:disabled="busy">
                            <span x-text="busy ? 'Mengunggah…' : 'Unggah APK'">Unggah APK</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        @endcan

        <div class="space-y-5">
            <div class="rounded-2xl border border-ink-100 bg-white p-5">
                <h2 class="text-sm font-semibold text-ink-950">Versi yang ditawarkan ke HP</h2>
                @if ($latest)
                    <p class="mt-2 text-2xl font-bold text-ink-950">{{ $latest->version_name }}</p>
                    <p class="mt-1 text-xs text-ink-500">
                        Kode {{ $latest->version_code }} · {{ number_format($latest->file_size / 1048576, 1, ',', '.') }} MB
                        · diunggah {{ $latest->created_at?->translatedFormat('d M Y H:i') }}
                    </p>
                    @if ($requiredCode)
                        <p class="mt-2 text-xs text-amber-700">HP di bawah kode {{ $requiredCode }} wajib memperbarui.</p>
                    @endif
                @else
                    <p class="mt-2 text-sm text-ink-500">
                        Belum ada APK aktif. Selama belum ada unggahan, HP memakai rilis dari GitHub (bila ada).
                    </p>
                @endif
            </div>

            <div class="rounded-2xl border border-ink-100 bg-white p-5">
                <h2 class="flex items-center gap-1 text-sm font-semibold text-ink-950">
                    Pasang pertama kali
                    <x-ui.hint text="Scan QR ini dengan kamera HP baru (atau buka alamatnya di browser HP), lalu tekan Unduh APK. Tidak perlu login. Setelah terpasang, pembaruan berikutnya lewat aplikasi." />
                </h2>
                <p class="mt-1 text-xs text-ink-500">Scan QR dengan kamera HP, tekan <b>Unduh APK</b>, lalu pasang berkasnya.</p>
                <div class="mt-3 flex items-start gap-4">
                    <div class="w-32 shrink-0 overflow-hidden rounded-lg border border-ink-100">
                        <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Support\QrCode::svg($pageUrl)) }}" alt="QR halaman unduh aplikasi" class="block h-auto w-full">
                    </div>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex items-center gap-2" x-data="{ copied: false }">
                            <code class="min-w-0 flex-1 truncate rounded-lg bg-ink-50 px-3 py-2 font-mono text-xs text-ink-800" title="{{ $pageUrl }}">{{ $pageUrl }}</code>
                            <x-ui.button type="button" variant="secondary" size="sm" icon="copy"
                                         @click="navigator.clipboard?.writeText(@js($pageUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-text="copied ? 'Tersalin' : 'Salin'">Salin</span>
                            </x-ui.button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button :href="$pageUrl" variant="secondary" size="sm" icon="external-link" target="_blank">Buka halaman</x-ui.button>
                            @if ($latest)
                                <x-ui.button :href="$downloadUrl" variant="secondary" size="sm" icon="download">Unduh APK</x-ui.button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($releases->isEmpty())
            <x-ui.empty-state icon="phone" title="Belum ada APK yang diunggah"
                              description="Unggah APK WMS Cheery Scan di atas supaya HP bisa memperbarui aplikasinya dari server ini." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="version" first="desc">Versi</x-ui.sort></th>
                            <th>Yang baru</th>
                            <th class="text-right"><x-ui.sort key="size" first="desc">Ukuran</x-ui.sort></th>
                            <th><x-ui.sort key="uploaded" first="desc">Diunggah</x-ui.sort></th>
                            <th><span class="inline-flex items-center gap-1">Wajib <x-ui.hint text="Versi wajib: HP dengan versi lebih lama harus memperbarui dulu sebelum bisa memakai aplikasi." /></span></th>
                            <th><span class="inline-flex items-center gap-1"><x-ui.sort key="status" first="desc">Status</x-ui.sort> <x-ui.hint text="Hanya versi aktif yang ditawarkan ke HP. Nonaktifkan versi yang bermasalah supaya HP kembali ditawari versi aktif sebelumnya (bila lebih baru dari yang terpasang)." /></span></th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($releases as $release)
                            <tr class="align-top">
                                <td>
                                    <div class="font-semibold text-ink-950">{{ $release->version_name }}</div>
                                    <div class="text-xs text-ink-400">Kode {{ $release->version_code }}</div>
                                    @if ($latest?->is($release))
                                        <x-ui.badge variant="success" class="mt-1">Ditawarkan</x-ui.badge>
                                    @endif
                                </td>
                                @can('mobile-app.update')
                                    <td>
                                        <form id="update-release-{{ $release->id }}" method="POST"
                                              action="{{ route('admin.mobile-app.update', $release) }}" class="hidden">
                                            @csrf
                                            @method('PUT')
                                        </form>
                                        <textarea name="notes" rows="2" maxlength="1000" form="update-release-{{ $release->id }}"
                                                  class="form-control h-auto min-w-56 py-1.5 text-xs" placeholder="Tanpa catatan">{{ $release->notes }}</textarea>
                                    </td>
                                @else
                                    <td class="max-w-xs whitespace-pre-line text-xs text-ink-600">{{ $release->notes ?: '—' }}</td>
                                @endcan
                                <td class="text-right text-sm tabular-nums text-ink-700">{{ number_format($release->file_size / 1048576, 1, ',', '.') }} MB</td>
                                <td class="text-sm text-ink-700">
                                    {{ $release->created_at?->translatedFormat('d M Y H:i') }}
                                    <div class="text-xs text-ink-400">{{ $release->uploader?->name ?? 'Otomatis (GitHub)' }}</div>
                                </td>
                                @can('mobile-app.update')
                                    <td>
                                        <input type="hidden" name="is_required" value="0" form="update-release-{{ $release->id }}">
                                        <label class="inline-flex cursor-pointer items-center gap-2">
                                            <x-ui.checkbox name="is_required" value="1" form="update-release-{{ $release->id }}" :checked="$release->is_required" />
                                            <span class="text-sm text-ink-600">Wajib</span>
                                        </label>
                                    </td>
                                    <td>
                                        <input type="hidden" name="is_active" value="0" form="update-release-{{ $release->id }}">
                                        <label class="inline-flex cursor-pointer items-center gap-2">
                                            <x-ui.checkbox name="is_active" value="1" form="update-release-{{ $release->id }}" :checked="$release->is_active" />
                                            <span class="text-sm text-ink-600">Aktif</span>
                                        </label>
                                    </td>
                                @else
                                    <td>
                                        @if ($release->is_required)
                                            <x-ui.badge variant="warning">Wajib</x-ui.badge>
                                        @else
                                            <span class="text-ink-400">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <x-ui.badge :variant="$release->is_active ? 'success' : 'danger'">
                                            {{ $release->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </x-ui.badge>
                                    </td>
                                @endcan
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('mobile-app.update')
                                            <x-ui.button type="submit" form="update-release-{{ $release->id }}"
                                                         variant="secondary" size="sm" icon="check">Simpan</x-ui.button>
                                        @endcan
                                        <x-ui.button :href="route('admin.mobile-app.download', $release)" variant="ghost" size="icon" title="Unduh APK">
                                            <x-icon name="download" class="h-4 w-4" />
                                        </x-ui.button>
                                        @can('mobile-app.delete')
                                            <x-ui.confirm-delete :action="route('admin.mobile-app.destroy', $release)"
                                                                 title="Hapus versi ini?"
                                                                 :description="'APK versi '.$release->version_name.' dihapus dari server.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$releases" unit="versi" />
        @endif
    </div>

    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">Perhatian</p>
        <p class="mt-1 text-xs leading-5 text-amber-800">
            Android hanya mau menimpa aplikasi bila APK baru ditandatangani kunci yang sama. Android tetap meminta operator
            menekan <strong>Perbarui</strong>, dan pertama kali meminta izin "Instal aplikasi tidak dikenal".
        </p>
    </div>
</x-app-layout>
