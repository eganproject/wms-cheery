{{--
    Isi hasil hitung fisik per roll. Setiap isian langsung tersimpan; scan atau
    ketik kode roll untuk melompat ke barisnya. Setelah selesai, terapkan:
    selisih menjadi koreksi roll.
--}}
@php
    $draft = $opname->isDraft();
    $canCount = $draft && auth()->user()->can('fabrics.issue');
@endphp
<x-app-layout :title="'Opname '.$opname->code">
    <x-ui.page-header :title="'Opname Kain '.$opname->code"
                      :subtitle="$opname->scopeLabel().' · '.$opname->date->format('d/m/Y').($opname->note ? ' · '.$opname->note : '')"
                      :back="route('admin.fabric-opnames.index')"
                      :status="\App\Models\FabricOpname::STATUSES[$opname->status] ?? null"
                      :statusVariant="$draft ? 'warning' : 'success'">
        <x-slot name="actions">
            @if ($draft)
                @can('fabrics.adjust')
                    <x-ui.confirm-delete :action="route('admin.fabric-opnames.destroy', $opname)" title="Hapus opname ini?"
                                         description="Hasil hitung yang sudah diisi ikut terhapus. Sisa roll tidak berubah." />
                @endcan
            @endif
        </x-slot>
    </x-ui.page-header>

    <div x-data="{
            rows: {{ Js::from($rows) }},
            code: '',
            message: null,
            filter: 'semua',
            saving: {},
            get counted() { return this.rows.filter((row) => row.counted !== null && row.counted !== '').length },
            get differ() { return this.rows.filter((row) => this.diff(row) !== null && Math.abs(this.diff(row)) >= 0.005).length },
            diff(row) { return row.counted === null || row.counted === '' ? null : Math.round((Number(row.counted) - row.system) * 100) / 100 },
            fmt(value) { return Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 }) },
            visible(row) {
                if (this.filter === 'belum') return row.counted === null || row.counted === '';
                if (this.filter === 'selisih') return this.diff(row) !== null && Math.abs(this.diff(row)) >= 0.005;
                return true;
            },
            jump() {
                const code = this.code.trim().toUpperCase();
                const row = this.rows.find((item) => item.code === code);
                this.message = row ? null : `Roll ${code} tidak ada di daftar opname ini.`;
                this.code = '';
                if (! row) return;
                this.filter = 'semua';
                this.$nextTick(() => {
                    const input = document.getElementById(`count-${row.id}`);
                    input?.scrollIntoView({ block: 'center' });
                    input?.focus();
                    input?.select();
                });
            },
            async save(row) {
                this.saving[row.id] = true;
                try {
                    const response = await fetch(@js(route('admin.fabric-opnames.count', $opname)), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({ line_id: row.id, counted: row.counted === '' ? null : row.counted }),
                    });
                    const data = await response.json();
                    if (! response.ok) throw new Error(data.message || 'Gagal menyimpan.');
                    row.counted = data.counted;
                    this.message = null;
                } catch (error) {
                    this.message = error.message;
                } finally {
                    this.saving[row.id] = false;
                }
            },
         }" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="grid grid-cols-3 gap-4">
                <x-ui.stat-card label="Roll" :value="number_format(count($rows), 0, ',', '.')" icon="layers" />
                <div class="rounded-2xl border border-ink-100 bg-white p-4">
                    <p class="text-sm text-ink-500">Sudah dihitung</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-ink-950" x-text="counted"></p>
                </div>
                <div class="rounded-2xl border border-ink-100 bg-white p-4">
                    <p class="text-sm text-ink-500">Ada selisih</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-rose-600" x-text="differ"></p>
                </div>
            </div>

            <div class="flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
                @if ($canCount)
                    <form x-on:submit.prevent="jump()" class="relative flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                            <x-icon name="scan" class="h-4 w-4" />
                        </span>
                        <input x-model="code" type="text" autocomplete="off" placeholder="Scan / ketik kode roll lalu Enter…" class="form-control pl-8">
                    </form>
                @endif
                <div class="flex rounded-xl bg-control p-1 text-sm">
                    <button type="button" x-on:click="filter = 'semua'" :class="filter === 'semua' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'" class="rounded-lg px-3 py-1.5 font-semibold">Semua</button>
                    <button type="button" x-on:click="filter = 'belum'" :class="filter === 'belum' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'" class="rounded-lg px-3 py-1.5 font-semibold">Belum</button>
                    <button type="button" x-on:click="filter = 'selisih'" :class="filter === 'selisih' ? 'bg-white text-ink-950 shadow-sm' : 'text-ink-500'" class="rounded-lg px-3 py-1.5 font-semibold">Selisih</button>
                </div>
            </div>
            <p x-show="message" x-cloak x-text="message" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700"></p>

            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
                <div class="overflow-x-auto">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Roll</th>
                                <th class="text-right">Tercatat</th>
                                <th class="text-right">Hasil Hitung</th>
                                <th class="text-right">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in rows" :key="row.id">
                                <tr x-show="visible(row)">
                                    <td>
                                        <span class="block font-mono text-sm font-semibold text-ink-950" x-text="row.code"></span>
                                        <span class="block text-[11px] text-ink-400" x-text="row.fabric + (row.location ? ' · ' + row.location : '')"></span>
                                    </td>
                                    <td class="text-right text-sm tabular-nums" x-text="fmt(row.system) + ' ' + row.unit"></td>
                                    <td class="text-right">
                                        @if ($canCount)
                                            <div class="relative ml-auto w-32">
                                                <input :id="`count-${row.id}`" type="number" step="0.01" min="0" inputmode="decimal" placeholder="—"
                                                       x-model="row.counted" x-on:change="save(row)" x-on:keydown.enter.prevent="$event.target.blur()"
                                                       class="form-control h-9 pr-9 text-right tabular-nums">
                                                <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-[11px] text-ink-400" x-text="saving[row.id] ? '…' : row.unit"></span>
                                            </div>
                                        @else
                                            <span class="text-sm tabular-nums" x-text="row.counted === null ? '—' : fmt(row.counted) + ' ' + row.unit"></span>
                                        @endif
                                    </td>
                                    <td class="text-right text-sm font-semibold tabular-nums"
                                        :class="diff(row) > 0 ? 'text-green-700' : (diff(row) < 0 ? 'text-rose-600' : 'text-ink-400')"
                                        x-text="diff(row) === null ? '—' : (diff(row) > 0 ? '+' : '') + fmt(diff(row)) + ' ' + row.unit"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            @if ($draft)
                @can('fabrics.adjust')
                    <x-ui.card title="Terapkan Opname" subtitle="Sisa tiap roll yang dihitung diganti dengan hasil hitung; selisihnya tercatat sebagai koreksi.">
                        <form method="POST" action="{{ route('admin.fabric-opnames.apply', $opname) }}" class="space-y-3"
                              onsubmit="return confirm('Terapkan opname ini? Sisa roll akan dikoreksi dan opname tidak bisa diubah lagi.')">
                            @csrf
                            <p class="form-label">Roll yang belum dihitung</p>
                            @foreach (\App\Models\FabricOpname::MISSING_RULES as $value => $label)
                                <label class="flex items-center gap-2 text-sm text-ink-700">
                                    <input type="radio" name="missing_rule" value="{{ $value }}" @checked(old('missing_rule', 'skip') === $value) class="border-ink-300">
                                    {{ $label }}
                                </label>
                            @endforeach
                            <x-input-error :messages="$errors->get('missing_rule')" />
                            <p class="text-xs text-ink-500"><span x-text="rows.length - counted"></span> roll belum dihitung.</p>
                            <x-ui.button type="submit" icon="check" class="w-full">Terapkan</x-ui.button>
                        </form>
                    </x-ui.card>
                @else
                    <p class="rounded-lg bg-ink-50 px-3 py-3 text-sm text-ink-500">Opname diterapkan oleh admin setelah semua roll dihitung.</p>
                @endcan
            @else
                <x-ui.card title="Sudah Diterapkan">
                    <p class="text-sm text-ink-700">{{ $opname->applied_at?->format('d/m/Y H:i') }} oleh {{ $opname->appliedBy?->name ?? '—' }}.</p>
                    <p class="mt-1 text-sm text-ink-500">Roll tidak dihitung: {{ \App\Models\FabricOpname::MISSING_RULES[$opname->missing_rule] ?? '—' }}.</p>
                    <p class="mt-1 text-sm text-ink-500">{{ $opname->lines->filter(fn ($line) => abs((float) $line->applied_difference) >= 0.005)->count() }} roll dikoreksi. Riwayatnya ada di detail roll dengan catatan "Opname {{ $opname->code }}".</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-app-layout>
