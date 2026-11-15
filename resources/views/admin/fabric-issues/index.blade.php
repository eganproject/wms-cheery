<x-app-layout title="Kain Keluar">
    <x-ui.page-header title="Kain Keluar" icon="outbound"
                      subtitle="Riwayat kain yang keluar lewat scan QR roll. Kain hanya bisa dikeluarkan dari Scan Kain.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.fabric-issues.export', request()->query())" variant="secondary" icon="excel" data-no-ajax>
                Export Excel
            </x-ui.button>
            @can('fabrics.issue')
                <x-ui.button :href="route('admin.fabric-scan')" icon="scan">Scan Kain Keluar</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.fabric-issues.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari kode roll, SKU/nama barang, atau catatan..." class="pl-8" />
            </div>

            <x-ui.select name="fabric_id" class="lg:w-56">
                <option value="">Semua kain</option>
                @foreach ($fabrics as $fabric)
                    <option value="{{ $fabric->id }}" @selected((int) request('fabric_id') === $fabric->id)>{{ $fabric->sku }} — {{ $fabric->displayName() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="product_id" class="lg:w-56">
                <option value="">Semua barang</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected((int) request('product_id') === $product->id)>{{ $product->sku }} — {{ $product->name }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <x-ui.select name="status" class="sm:w-44">
                <option value="">Semua status</option>
                <option value="keluar" @selected(request('status') === 'keluar')>Keluar</option>
                <option value="batal" @selected(request('status') === 'batal')>Dibatalkan</option>
            </x-ui.select>

            <x-ui.date-filter label="Tanggal keluar" />

            <div class="flex items-center gap-2 sm:ml-auto">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'fabric_id', 'product_id', 'status', 'from', 'to', 'range']))
                    <x-ui.button :href="route('admin.fabric-issues.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    @if ($perFabric->isNotEmpty())
        <div class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
            @foreach ($perFabric->take(8) as $row)
                <x-ui.stat-card :label="$row['fabric']->sku" :value="$row['fabric']->format($row['total'])" icon="outbound"
                                :hint="$row['entries'].'× keluar · '.$row['fabric']->displayName()" />
            @endforeach
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
        @if ($movements->isEmpty())
            <x-ui.empty-state icon="outbound" title="Belum ada kain keluar pada tanggal ini"
                              description="Kain keluar dicatat dari Scan Kain: scan QR roll, isi jumlahnya, simpan." />
        @else
            <div class="overflow-x-auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th><x-ui.sort key="time" first="desc">Waktu</x-ui.sort></th>
                            <th>Roll</th>
                            <th>Kain</th>
                            <th class="text-right"><x-ui.sort key="quantity">Jumlah</x-ui.sort></th>
                            <th class="text-right">Sisa Roll</th>
                            <th>Untuk Barang</th>
                            <th>Oleh</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($movements as $movement)
                            <tr class="{{ $movement->isCancelled() ? 'opacity-60' : '' }}">
                                <td class="whitespace-nowrap text-sm text-ink-600">{{ $movement->moved_at->format('d/m/Y H:i') }}</td>
                                <td class="font-mono text-sm font-semibold">{{ $movement->roll->code }}</td>
                                <td class="text-sm">
                                    <div class="flex items-center gap-2">
                                        <x-ui.fabric-image :fabric="$movement->fabric" size="xs" />
                                        <a href="{{ route('admin.fabrics.show', $movement->fabric) }}" class="hover:underline">{{ $movement->fabric->displayName() }}</a>
                                    </div>
                                </td>
                                <td class="text-right text-sm font-semibold tabular-nums {{ $movement->isCancelled() ? 'line-through' : 'text-rose-600' }}">
                                    {{ $movement->fabric->format(-$movement->quantity) }}
                                </td>
                                <td class="text-right text-sm tabular-nums">{{ $movement->fabric->format($movement->balance_after) }}</td>
                                <td class="text-sm text-ink-700">
                                    @if ($movement->product)
                                        <span class="font-mono text-xs font-semibold text-ink-800">{{ $movement->product->sku }}</span>
                                        <p class="max-w-56 truncate text-sm text-ink-700">{{ $movement->product->name }}</p>
                                    @else
                                        {{ $movement->destination ?? '—' }}
                                    @endif
                                    @if ($movement->note)
                                        <p class="text-[11px] text-ink-400">{{ $movement->note }}</p>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-500">{{ $movement->user?->name ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($movement->isCancelled())
                                        <x-ui.badge variant="gray">Dibatalkan</x-ui.badge>
                                    @else
                                        @can('fabrics.adjust')
                                            <form method="POST" action="{{ route('admin.fabric-issues.cancel', $movement) }}"
                                                  onsubmit="return confirm('Batalkan kain keluar ini? Jumlahnya kembali ke roll {{ $movement->roll->code }}.')">
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm">Batalkan</x-ui.button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pagination :paginator="$movements" />
        @endif
    </div>
</x-app-layout>
