<x-app-layout title="Barang">
    <x-ui.page-header title="Barang" icon="box"
                      subtitle="Master data barang sekaligus saldo stoknya. Tambah dan ubah barang dilakukan di sini.">
        <x-slot name="actions">
            @can('products.import')
                <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                    Import Excel
                </x-ui.button>
            @endcan
            @can('products.view')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.products.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
            @can('products.create')
                <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>


    {{--
        Paket mendapat kartunya sendiri, bukan menumpang sebagai catatan kaki
        di kartu pertama.

        Empat kartu di sebelahnya semuanya berbicara tentang saldo di rak, dan
        paket tidak punya saldo — ikut menghitungnya membuat "Jenis Barang"
        tidak lagi cocok dengan "Total Unit". Tetapi tabel di bawah memang
        menampilkan paket, jadi tanpa kartu kelima ini jumlah kartu dan jumlah
        baris bercerita beda tanpa ada yang menjelaskan selisihnya.

        Kartunya hanya muncul bila memang ada paket, supaya gudang yang tidak
        memakainya tidak diberi kolom kosong untuk selamanya.
    --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 max-xl:[&>*:last-child:nth-child(odd)]:col-span-2 {{ $summary['bundles'] > 0 ? 'xl:grid-cols-5' : 'xl:grid-cols-4' }}">
        <x-ui.stat-card label="Jenis Barang" :value="$summary['total']" icon="box" accent hint="SKU barang di rak" />
        <x-ui.stat-card label="Total Unit" :value="number_format($summary['units'], 0, ',', '.')" icon="chart" hint="Seluruh stok tersedia" />
        <x-ui.stat-card label="Stok Menipis" info="Barang yang stoknya di bawah atau sama dengan batas minimum masing-masing." :value="$summary['low']" icon="warning" hint="Di bawah batas minimum" />
        <x-ui.stat-card label="Stok Habis" :value="$summary['out']" icon="x-circle" hint="Perlu segera diisi" />
        @if ($summary['bundles'] > 0)
            <x-ui.stat-card label="Paket Bundling" info="Barang paket yang stoknya dihitung dari komponen penyusunnya." :value="$summary['bundles']" icon="sparkles"
                            hint="Tanpa stok sendiri, dirakit dari isinya" />
        @endif
    </div>

    <form method="GET" action="{{ route('admin.products.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama, SKU, atau barcode..." class="pl-8" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="category" class="sm:w-44">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="stock" class="sm:w-40">
                <option value="">Semua stok</option>
                <option value="safe" @selected(request('stock') === 'safe')>Aman</option>
                <option value="low" @selected(request('stock') === 'low')>Menipis</option>
                <option value="out" @selected(request('stock') === 'out')>Habis</option>
            </x-ui.select>

            <x-ui.select name="type" class="sm:w-36">
                <option value="">Semua jenis</option>
                <option value="single" @selected(request('type') === 'single')>Barang</option>
                <option value="variant" @selected(request('type') === 'variant')>Bervarian</option>
                <option value="bundle" @selected(request('type') === 'bundle')>Paket</option>
            </x-ui.select>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'category', 'stock', 'status', 'type']))
                <x-ui.button :href="route('admin.products.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($products->isEmpty())
        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
            <x-ui.empty-state icon="box" title="Barang tidak ditemukan"
                              description="Tambahkan barang satu per satu, atau import banyak sekaligus dari Excel beserta stoknya.">
                <x-slot name="action">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        @can('products.create')
                            <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
                        @endcan
                        @can('products.import')
                            <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                                Import dari Excel
                            </x-ui.button>
                        @endcan
                    </div>
                </x-slot>
            </x-ui.empty-state>
        </div>
    @else
        {{--
            Seluruh daftar berada di dalam satu form: centang beberapa barang,
            ubah batas menipisnya sekaligus.

            Yang bisa diubah massal sengaja hanya batas menipis. Batas adalah
            setelan kapan barang mulai disebut menipis; ia tidak pernah menambah
            atau mengurangi saldo, jadi tidak ada angka gudang yang bisa menjadi
            rancu karenanya. Stok sendiri hanya boleh bergerak lewat dokumen.
        --}}
        <form method="POST" action="{{ route('admin.products.bulk.min-stock') }}"
              x-data="productBulkEdit({
                  ids: @js($products->pluck('id')->all()),
                  total: {{ $products->total() }},
                  key: @js(collect(request()->query())->except('page')->sortKeys()->toJson()),
              })"
              {{-- Pilihan yang sudah dipakai tidak perlu diingat lagi. --}}
              x-on:submit="forget()">
            @csrf
            @method('PATCH')

            {{--
                Barang terpilih dikirim dari daftar pilihan, bukan dari kotak
                centang di layar: sebagian di antaranya bisa berada di halaman
                yang sedang tidak terlihat, dan kotak centangnya pun tidak ada
                di dokumen ini.
            --}}
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>

            {{--
                Saringan yang sedang aktif ikut terkirim: ia menentukan barang
                mana saja yang termasuk saat pilihan berlaku untuk seluruh hasil
                saringan, dan sekaligus membawa daftar kembali ke tampilan yang
                sama setelah selesai.
            --}}
            @foreach (['search', 'category', 'stock', 'status', 'type'] as $filter)
                @if (request()->filled($filter))
                    <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                @endif
            @endforeach

            <input type="hidden" name="scope" :value="allFiltered ? 'filtered' : 'selected'">

            @can('products.update')
                {{--
                    Bilah aksi berada di luar kartu daftarnya: kartu itu
                    ber-overflow-hidden, dan di dalamnya `sticky` tidak pernah
                    bekerja karena elemen ber-overflow menjadi wadah gulirnya
                    sendiri.
                --}}
                <div x-show="count > 0" x-cloak
                     class="sticky top-[calc(var(--navbar-height)+3.75rem)] z-20 mb-3 flex flex-col gap-3 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-blue-900">
                            <span x-text="allFiltered
                                ? `Seluruh ${total} barang sesuai saringan dipilih`
                                : `${selected.length} barang dipilih`"></span>
                        </p>

                        {{--
                            Halaman ini memuat sepuluh baris, sedangkan saringan
                            bisa mengenai ratusan. Tanpa jalan memilih seluruh
                            hasil saringan, penyuntingan massal harus diulang
                            sepuluh baris sekali — jadi jalannya disediakan,
                            tetapi sebagai tindakan tersendiri yang jumlahnya
                            disebut, bukan sesuatu yang terjadi diam-diam.
                        --}}
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-blue-800/80">
                            {{-- Yang terpilih di halaman lain tetap disebut; kalau
                                 tidak, jumlahnya terbaca lebih besar dari yang
                                 terlihat tanpa penjelasan. --}}
                            <template x-if="! allFiltered && offPage > 0">
                                <span x-text="`${offPage} di antaranya dari halaman lain`"></span>
                            </template>

                            <template x-if="! allFiltered && pageChosen && total > ids.length">
                                <button type="button" x-on:click="chooseEverything()"
                                        class="font-medium text-blue-700 underline underline-offset-4 hover:text-blue-900"
                                        x-text="`Pilih seluruh ${total} barang sesuai saringan`"></button>
                            </template>

                            <template x-if="allFiltered">
                                <button type="button" x-on:click="clearAll()"
                                        class="font-medium text-blue-700 underline underline-offset-4 hover:text-blue-900">
                                    Batalkan pilihan
                                </button>
                            </template>

                            <span>Hanya batas menipis yang diubah — stok tidak tersentuh.</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 ring-1 ring-inset ring-blue-200">
                            <span class="whitespace-nowrap text-xs text-ink-500">Batas menipis</span>
                            <input type="number" name="min_stock" min="0" max="999999" required
                                   inputmode="numeric" placeholder="0"
                                   class="w-16 border-0 bg-transparent p-0 text-center text-base font-semibold text-ink-950 shadow-none focus:shadow-none focus:ring-0">
                        </label>

                        <button type="submit"
                                class="btn btn-primary">
                            <x-icon name="check" class="h-4 w-4" />
                            <span x-text="`Terapkan ke ${count} barang`"></span>
                        </button>

                        <button type="button" x-on:click="clearAll()" title="Batalkan pilihan"
                                class="btn btn-ghost btn-icon text-blue-800">
                            <x-icon name="close" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endcan

            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white">
                <div class="hidden overflow-x-auto md:block">
                <table class="erp-table">
                    <thead>
                        <tr>
                            @can('products.update')
                                <th class="w-12 pl-6 pr-0">
                                    <input type="checkbox" :checked="pageChosen" x-on:change="togglePage()"
                                           title="Pilih semua di halaman ini"
                                           class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                    <span class="sr-only">Pilih semua barang di halaman ini</span>
                                </th>
                            @endcan
                            <th>Barang</th>
                            <th>Kategori</th>
                            <th><span class="inline-flex items-center gap-1">Lokasi <x-ui.hint text="Posisi rak tempat barang disimpan." /></span></th>
                            <th class="text-right"><span class="inline-flex items-center gap-1">Stok <x-ui.hint text="Saldo stok di gudang. Angka &quot;min.&quot; adalah batas menipis barang ini." /></span></th>
                            <th><span class="inline-flex items-center gap-1">Status <x-ui.hint text="Aman, Menipis (di bawah batas minimum), atau Habis." /></span></th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    {{--
                        Varian satu produk berurutan (diurutkan di controller),
                        jadi cukup sisipkan satu baris induk setiap kali
                        kelompoknya berganti. Baris induk bisa dilipat.
                    --}}
                    <tbody x-data="{ closed: [] }">
                        @php $previousGroup = null; @endphp
                        @foreach ($products as $product)
                            @if ($product->isVariant() && $product->product_group_id !== $previousGroup)
                                @include('admin.products.partials.group-row', ['group' => $product->group])
                            @endif
                            @php $previousGroup = $product->product_group_id; @endphp
                            <tr @if ($product->isVariant()) x-show="! closed.includes({{ $product->product_group_id }})" @endif
                                :class="selected.includes({{ $product->id }}) && 'bg-ink-50/70'">
                                @can('products.update')
                                    <td class="w-12 pl-6 pr-0 align-top">
                                        <input type="checkbox" value="{{ $product->id }}"
                                               x-model.number="selected" x-on:change="allFiltered = false"
                                               class="mt-0.5 h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                        <span class="sr-only">Pilih {{ $product->name }}</span>
                                    </td>
                                @endcan
                                <td>
                                    <div class="flex items-center gap-3 {{ $product->isVariant() ? 'pl-6' : '' }}">
                                        <x-ui.product-image :product="$product" size="sm" />
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <x-ui.sku :value="$product->sku" />
                                                <p class="truncate text-sm font-medium text-ink-950">{{ $product->isVariant() ? $product->variantLabel() : $product->name }}</p>
                                                @if ($product->isBundle())
                                                    <x-ui.badge variant="dark" icon="sparkles">Paket</x-ui.badge>
                                                @endif
                                            </div>
                                            @if ($product->barcode)
                                                <p class="mt-1 font-mono text-[11px] text-ink-400">Barcode {{ $product->barcode }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-sm text-ink-600">{{ $product->category ?: '—' }}</td>
                                <td class="font-mono text-xs text-ink-500">{{ $product->location ?: '—' }}</td>
                                <td class="text-right">
                                    {{-- Paket tidak punya saldo; yang berarti baginya adalah berapa
                                         yang masih bisa dirakit dari komponen yang tersedia. --}}
                                    <span class="text-sm font-semibold text-ink-950">{{ number_format($product->availableStock(), 0, ',', '.') }}</span>
                                    <span class="text-xs text-ink-400">{{ $product->unit }}</span>
                                    <p class="text-[11px] text-ink-400">
                                        {{ $product->isBundle() ? 'bisa dijanjikan' : 'min. '.$product->min_stock }}
                                    </p>
                                </td>
                                <td>
                                    <x-ui.stock-badge :product="$product" />
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.products.show', $product) }}" title="Kartu stok"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="document" class="h-4 w-4" />
                                        </a>
                                        @can('products.update')
                                            <a href="{{ route('admin.products.edit', $product) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('products.delete')
                                            <x-ui.confirm-delete :action="route('admin.products.destroy', $product)"
                                                                 title="Hapus barang ini?"
                                                                 :description="$product->name.' akan dihapus dari master data.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>

                <div class="divide-y divide-ink-50 md:hidden">
                    @php $previousGroup = null; @endphp
                    @foreach ($products as $product)
                        @if ($product->isVariant() && $product->product_group_id !== $previousGroup)
                            <a href="{{ route('admin.products.groups.show', $product->product_group_id) }}"
                               class="flex items-center gap-3 bg-ink-50/70 px-4 py-2.5">
                                @if ($product->group?->imageUrl())
                                    <img src="{{ $product->group->imageUrl() }}" alt="" class="h-8 w-8 rounded-md object-cover">
                                @endif
                                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink-950">{{ $product->group?->name }}</span>
                                <x-ui.badge variant="purple" icon="layers">{{ $product->group?->variants_count }} varian</x-ui.badge>
                            </a>
                        @endif
                        @php $previousGroup = $product->product_group_id; @endphp
                        <div class="flex items-start gap-3 p-4 {{ $product->isVariant() ? 'pl-8' : '' }}"
                             :class="selected.includes({{ $product->id }}) && 'bg-ink-50/70'">
                            @can('products.update')
                                <label class="mt-1 shrink-0">
                                    <input type="checkbox" value="{{ $product->id }}"
                                           x-model.number="selected" x-on:change="allFiltered = false"
                                           class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                    <span class="sr-only">Pilih {{ $product->name }}</span>
                                </label>
                            @endcan

                            <x-ui.product-image :product="$product" size="lg" />

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <x-ui.sku :value="$product->sku" />
                                            @if ($product->isBundle())
                                                <x-ui.badge variant="dark" icon="sparkles">Paket</x-ui.badge>
                                            @endif
                                            @if ($product->isVariant())
                                                <x-ui.badge variant="purple" icon="layers">Varian</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-1 truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                                    </div>
                                    <x-ui.stock-badge :product="$product" />
                                </div>

                                <div class="mt-3 flex items-end justify-between gap-3">
                                    <div>
                                        <p class="text-2xl font-semibold text-ink-950">
                                            {{ number_format($product->availableStock(), 0, ',', '.') }}
                                            <span class="text-xs font-normal text-ink-400">{{ $product->unit }}</span>
                                        </p>
                                        <p class="text-[11px] text-ink-400">
                                            @if ($product->isBundle())
                                                masih bisa dijanjikan
                                            @else
                                                min. {{ $product->min_stock }} &middot; {{ $product->location ?: 'tanpa lokasi' }}
                                            @endif
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <x-ui.button :href="route('admin.products.show', $product)" variant="ghost" size="sm" icon="document">Kartu</x-ui.button>
                                        @can('products.update')
                                            <x-ui.button :href="route('admin.products.edit', $product)" variant="secondary" size="sm" icon="pencil">Ubah</x-ui.button>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.pagination :paginator="$products" />
            </div>
        </form>
    @endif
</x-app-layout>
