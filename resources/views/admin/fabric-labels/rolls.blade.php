{{--
    Tabel roll dengan pilihan cetak label QR.

    Variabel: $rolls (FabricRoll + fabric), $showFabric (tampilkan kolom kain),
    $printable (boleh cetak label).
--}}
@php
    $showFabric ??= false;
    $printable ??= auth()->user()->can('fabrics.receive');
    $layouts = collect(\App\Support\QrLabelLayout::all())->map(fn ($layout) => $layout['label']);
@endphp

<form method="POST" action="{{ route('admin.fabric-labels.pdf') }}" target="_blank" data-no-ajax
      x-data="{
          selected: {{ Js::from($rolls->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
          all: {{ Js::from($rolls->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
          layout: (() => { try { return localStorage.getItem('fabric-label-layout') || 'thermal-50x30' } catch (e) { return 'thermal-50x30' } })(),
          toggleAll() { this.selected = this.selected.length === this.all.length ? [] : [...this.all] },
          remember() { try { localStorage.setItem('fabric-label-layout', this.layout) } catch (e) {} },
      }">
    @csrf

    @if ($printable && $rolls->isNotEmpty())
        <div class="flex flex-col gap-3 border-b border-ink-100 px-4 py-3 sm:flex-row sm:items-center sm:px-5">
            <p class="text-sm text-ink-600"><span class="font-semibold text-ink-950" x-text="selected.length"></span> roll dipilih</p>
            <div class="flex flex-1 flex-wrap items-center gap-2 sm:justify-end">
                <x-ui.select name="layout" x-model="layout" x-on:change="remember()" class="w-full sm:w-64">
                    @foreach ($layouts as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="copies" class="w-28" title="Label per roll">
                    @foreach ([1, 2, 3, 4] as $copies)
                        <option value="{{ $copies }}">{{ $copies }}× / roll</option>
                    @endforeach
                </x-ui.select>
                <x-ui.button type="submit" icon="printer" x-bind:disabled="selected.length === 0">Cetak Label QR</x-ui.button>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="erp-table">
            <thead>
                <tr>
                    @if ($printable)
                        <th class="w-10">
                            <input type="checkbox" class="rounded border-ink-300" x-on:change="toggleAll()"
                                   :checked="selected.length === all.length && all.length > 0" title="Pilih semua">
                        </th>
                    @endif
                    <th>Kode Roll (isi QR)</th>
                    @if ($showFabric)
                        <th>Kain</th>
                    @endif
                    <th class="text-right">Awal</th>
                    <th class="text-right">Sisa</th>
                    <th>Lot / Lokasi</th>
                    <th>Masuk</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rolls as $roll)
                    <tr class="{{ $roll->isEmpty() ? 'opacity-60' : '' }}">
                        @if ($printable)
                            <td><input type="checkbox" name="rolls[]" value="{{ $roll->id }}" x-model="selected" class="rounded border-ink-300"></td>
                        @endif
                        <td class="font-mono text-sm font-semibold text-ink-950">{{ $roll->code }}</td>
                        @if ($showFabric)
                            <td class="text-sm">
                                <a href="{{ route('admin.fabrics.show', $roll->fabric) }}" class="hover:underline">{{ $roll->fabric->displayName() }}</a>
                            </td>
                        @endif
                        <td class="text-right text-sm tabular-nums text-ink-500">{{ $roll->fabric->format($roll->initial_quantity) }}</td>
                        <td class="text-right text-sm font-semibold tabular-nums">
                            @if ($roll->isEmpty())
                                <x-ui.badge variant="gray">Habis</x-ui.badge>
                            @else
                                {{ $roll->fabric->format($roll->quantity) }}
                            @endif
                        </td>
                        <td class="text-sm text-ink-600">{{ collect([$roll->lot, $roll->location])->filter()->implode(' · ') ?: '—' }}</td>
                        <td class="text-sm text-ink-500">
                            @if ($roll->receipt)
                                <a href="{{ route('admin.fabric-receipts.show', $roll->receipt) }}" class="hover:underline">{{ $roll->receipt->code }}</a>
                                <span class="text-[11px]">· {{ $roll->receipt->date->format('d/m/Y') }}</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</form>
