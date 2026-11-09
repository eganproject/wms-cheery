{{--
    Tindak lanjut retur ke pemasok: dikirim → barang pengganti diterima
    (masuk stok layak jual) → sisa yang tidak diganti ditutup sebagai
    ditolak pemasok. Dokumen selesai saat tidak ada unit yang masih ditunggu.
--}}
@php
    $canAct = auth()->user()->can('disposals.post') && ! $disposal->isReturnDone();
    $panel = $errors->has('shipped_at') || $errors->has('courier') || $errors->has('tracking_number') ? 'ship' : null;
@endphp

<div x-data="{ panel: @js($panel) }" class="mt-6" data-supplier-return>
    <x-ui.card title="Retur ke Pemasok"
               :subtitle="($disposal->supplier?->name ?? 'Pemasok belum dipilih').($disposal->daysWithSupplier() !== null ? ' · '.$disposal->daysWithSupplier().' hari di pemasok' : '')"
               padding="p-0">
        <x-slot name="actions">
            <x-ui.badge :variant="$disposal->returnStatusVariant()">{{ $disposal->returnStatusLabel() }}</x-ui.badge>
            @if ($canAct)
                <x-ui.button type="button" variant="secondary" icon="truck" x-on:click="panel = panel === 'ship' ? null : 'ship'">
                    {{ $disposal->isReturnUnsent() ? 'Tandai Dikirim' : 'Ubah Pengiriman' }}
                </x-ui.button>
                @if ($disposal->isReturnSent())
                    <x-ui.button type="button" icon="login" x-on:click="panel = panel === 'replace' ? null : 'replace'">
                        Terima Pengganti
                    </x-ui.button>
                    <x-ui.button type="button" variant="danger-soft" icon="x-circle" x-on:click="panel = panel === 'reject' ? null : 'reject'">
                        Ditolak Pemasok
                    </x-ui.button>
                @endif
            @endif
        </x-slot>

        {{-- Info pengiriman --}}
        <dl class="grid grid-cols-2 gap-4 border-b border-ink-100 px-4 py-3 text-sm sm:grid-cols-4 sm:px-5">
            <div>
                <dt class="text-xs text-ink-500">Tanggal kirim</dt>
                <dd class="font-medium text-ink-950">{{ $disposal->shipped_at?->translatedFormat('d M Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-500">Ekspedisi</dt>
                <dd class="font-medium text-ink-950">{{ $disposal->courier ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-500">No. resi</dt>
                <dd class="font-mono font-medium text-ink-950">{{ $disposal->tracking_number ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-500">Selesai</dt>
                <dd class="font-medium text-ink-950">
                    {{ $disposal->closed_at?->translatedFormat('d M Y') ?? '—' }}
                    @if ($disposal->closer)
                        <span class="text-xs font-normal text-ink-400">oleh {{ $disposal->closer->name }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($canAct)
            {{-- Form: pengiriman --}}
            <form x-show="panel === 'ship'" x-cloak method="POST" action="{{ route('admin.disposals.ship', $disposal) }}"
                  class="grid grid-cols-1 gap-3 border-b border-ink-100 bg-ink-50/60 px-4 py-4 sm:grid-cols-4 sm:items-end sm:px-5">
                @csrf
                <div>
                    <x-input-label for="shipped_at" value="Tanggal kirim" />
                    <x-text-input id="shipped_at" name="shipped_at" type="date" class="mt-1.5 w-full" required
                                  :value="old('shipped_at', $disposal->shipped_at?->toDateString() ?? now()->toDateString())" />
                    <x-input-error :messages="$errors->get('shipped_at')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="courier" value="Ekspedisi (opsional)" />
                    <x-text-input id="courier" name="courier" class="mt-1.5 w-full" :value="old('courier', $disposal->courier)" placeholder="Misal: JNE, kurir sendiri" />
                </div>
                <div>
                    <x-input-label for="tracking_number" value="No. resi (opsional)" />
                    <x-text-input id="tracking_number" name="tracking_number" class="mt-1.5 w-full" :value="old('tracking_number', $disposal->tracking_number)" />
                </div>
                <x-ui.button type="submit" icon="check">Simpan Pengiriman</x-ui.button>
            </form>

            @if ($disposal->isReturnSent())
                {{-- Form: penutupan sebagai ditolak --}}
                <form x-show="panel === 'reject'" x-cloak method="POST" action="{{ route('admin.disposals.reject-return', $disposal) }}"
                      class="space-y-3 border-b border-ink-100 bg-red-50/40 px-4 py-4 sm:px-5">
                    @csrf
                    <p class="text-sm text-ink-700">
                        Sisa <span class="font-semibold">{{ number_format($disposal->outstandingQuantity(), 0, ',', '.') }} unit</span>
                        yang belum diganti akan ditutup sebagai ditolak pemasok, dan retur ini selesai.
                    </p>
                    <div>
                        <x-input-label for="close_note" value="Alasan ditolak" />
                        <textarea id="close_note" name="close_note" rows="2" required class="form-control mt-1.5"
                                  placeholder="Misal: kerusakan karena penyimpanan, bukan cacat produksi.">{{ old('close_note') }}</textarea>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-ink-700">
                        <x-ui.checkbox name="back_to_warehouse" value="1" class="mt-0.5" />
                        <span>Barangnya dikirim balik oleh pemasok, masukkan lagi ke {{ $disposal->isFromDamaged() ? 'stok rusak' : 'stok layak jual' }}.</span>
                    </label>
                    <x-ui.button type="submit" variant="danger" icon="x-circle">Tutup sebagai Ditolak</x-ui.button>
                </form>
            @endif
        @endif

        {{-- Rincian per barang; jadi form penerimaan saat panel pengganti dibuka. --}}
        <form method="POST" action="{{ route('admin.disposals.replace', $disposal) }}">
            @csrf
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="text-right">Dikirim</th>
                            <th class="text-right">Diganti</th>
                            <th class="text-right">Ditolak</th>
                            <th class="text-right">Sisa Ditunggu</th>
                            @if ($canAct && $disposal->isReturnSent())
                                <th x-show="panel === 'replace'" x-cloak class="text-right">Terima Sekarang</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($disposal->items as $item)
                            <tr>
                                <td>
                                    <span class="text-sm font-medium text-ink-950">{{ $item->product->name }}</span>
                                    <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                </td>
                                <td class="text-right text-sm tabular-nums">{{ number_format($item->quantity, 0, ',', '.') }}</td>
                                <td class="text-right text-sm tabular-nums text-emerald-700">{{ number_format($item->replaced_quantity, 0, ',', '.') }}</td>
                                <td class="text-right text-sm tabular-nums text-red-600">{{ number_format($item->rejected_quantity, 0, ',', '.') }}</td>
                                <td class="text-right text-sm font-semibold tabular-nums text-ink-950">{{ number_format($item->outstandingQuantity(), 0, ',', '.') }}</td>
                                @if ($canAct && $disposal->isReturnSent())
                                    <td x-show="panel === 'replace'" x-cloak class="text-right">
                                        @if ($item->outstandingQuantity() > 0)
                                            <input type="number" min="0" max="{{ $item->outstandingQuantity() }}" inputmode="numeric"
                                                   name="replacements[{{ $item->id }}]" value="{{ old('replacements.'.$item->id) }}"
                                                   class="form-control ml-auto w-24 text-right tabular-nums">
                                        @else
                                            <span class="text-xs text-ink-400">lengkap</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canAct && $disposal->isReturnSent())
                <div x-show="panel === 'replace'" x-cloak
                     class="grid grid-cols-1 gap-3 border-t border-ink-100 bg-emerald-50/40 px-4 py-4 sm:grid-cols-4 sm:items-end sm:px-5">
                    <div>
                        <x-input-label for="replacement_date" value="Tanggal diterima" />
                        <x-text-input id="replacement_date" name="date" type="date" class="mt-1.5 w-full" required
                                      :value="old('date', now()->toDateString())" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="replacement_note" value="Catatan (opsional)" />
                        <x-text-input id="replacement_note" name="note" class="mt-1.5 w-full" :value="old('note')" placeholder="Misal: nomor surat jalan pemasok" />
                    </div>
                    <x-ui.button type="submit" icon="check">Terima ke Stok Layak Jual</x-ui.button>
                </div>
            @endif
        </form>

        @if ($disposal->replacements->isNotEmpty() || $disposal->close_note)
            <div class="space-y-2 border-t border-ink-100 px-4 py-3 text-sm sm:px-5">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Riwayat tindak lanjut</p>
                @foreach ($disposal->replacements->sortBy('date') as $replacement)
                    <p class="text-ink-700">
                        <span class="font-medium">{{ $replacement->date->translatedFormat('d M Y') }}</span> ·
                        pengganti diterima {{ $replacement->items->map(fn ($line) => $line->product->name.' '.$line->quantity)->implode(', ') }}
                        @if ($replacement->user) <span class="text-ink-400">oleh {{ $replacement->user->name }}</span> @endif
                        @if ($replacement->note) <span class="text-ink-500">— {{ $replacement->note }}</span> @endif
                    </p>
                @endforeach
                @if ($disposal->close_note)
                    <p class="text-ink-700"><span class="font-medium text-red-600">Ditolak pemasok:</span> {{ $disposal->close_note }}</p>
                @endif
            </div>
        @endif
    </x-ui.card>
</div>
