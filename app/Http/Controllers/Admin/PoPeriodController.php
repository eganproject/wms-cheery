<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPoPeriod;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Lama PO per produk dan aturan batas kirim pesanan Normal.
 *
 * Lama PO disimpan sebagai riwayat: tiap periode berlaku mulai tanggal + jam
 * tertentu sampai periode berikutnya dimulai, mengikuti setelan di TikTok
 * yang bisa berganti dari waktu ke waktu. Resi memakai periode yang berlaku
 * pada waktu bayarnya, jadi mengubah lama PO hari ini tidak menggeser batas
 * kirim pesanan yang masuk sebelumnya.
 */
class PoPeriodController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view', only: ['index']),
            new Middleware('can:products.update', only: ['store', 'destroy', 'settings']),
        ];
    }

    public function index(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();

        $search = trim((string) $request->input('search'));
        $owners = $this->owners($search);
        $periods = ProductPoPeriod::query()->with('user:id,name')->orderBy('starts_at')->orderBy('id')->get()
            ->groupBy(fn (ProductPoPeriod $period) => $period->ownerKey());

        $open = ShipmentOrder::query()->whereNull('cancelled_at')
            ->whereDoesntHave('outbounds', fn ($query) => $query->where('status', \App\Models\Outbound::STATUS_POSTED));

        return view('admin.po-periods.index', [
            'owners' => $owners,
            'periods' => $periods,
            'search' => $search,
            'normalDays' => AppSetting::normalShipDays(),
            'missing' => $this->missingOwners(),
            'missingOrders' => (clone $open)->whereNull('computed_ship_by')->count(),
            'oldestOpen' => (clone $open)->min(\Illuminate\Support\Facades\DB::raw('COALESCE(paid_at, order_date, created_at)')),
            'now' => now(),
        ]);
    }

    /**
     * Tambah satu periode sekaligus untuk banyak produk.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owners' => ['required', 'array', 'min:1'],
            'owners.*' => ['string', 'regex:/^[gp]\d+$/'],
            'starts_at' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'owners.required' => 'Centang minimal satu produk.',
            'starts_at.required' => 'Isi tanggal dan jam mulai berlaku.',
        ], [
            'starts_at' => 'mulai berlaku',
            'days' => 'lama PO',
        ]);

        $startsAt = Carbon::parse($data['starts_at'])->startOfMinute();
        $groups = ProductGroup::query()->whereIn('id', $this->ids($data['owners'], 'g'))->pluck('id');
        $products = Product::query()->whereNull('product_group_id')->whereIn('id', $this->ids($data['owners'], 'p'))->pluck('id');

        if ($groups->isEmpty() && $products->isEmpty()) {
            return back()->withInput()->withErrors(['owners' => 'Produk yang dipilih tidak ditemukan.']);
        }

        $saved = 0;

        foreach ([['product_group_id', $groups], ['product_id', $products]] as [$column, $ids]) {
            foreach ($ids as $id) {
                // Periode dengan waktu mulai yang sama diganti, bukan digandakan.
                ProductPoPeriod::query()->updateOrCreate(
                    [$column => $id, 'starts_at' => $startsAt],
                    ['days' => (int) $data['days'], 'note' => $data['note'] ?? null, 'user_id' => $request->user()?->id],
                );
                $saved++;
            }
        }

        return redirect()
            ->route('admin.po-periods.index', $request->only('search'))
            ->with('success', "Lama PO {$data['days']} hari mulai {$startsAt->translatedFormat('d M Y H:i')} disimpan untuk {$saved} produk. Batas kirim resi yang terdampak dihitung ulang.");
    }

    public function destroy(Request $request, ProductPoPeriod $period): RedirectResponse
    {
        $period->delete();

        return redirect()
            ->route('admin.po-periods.index', $request->only('search'))
            ->with('success', 'Periode lama PO dihapus. Batas kirim resi yang terdampak dihitung ulang.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'normal_ship_days' => ['required', 'integer', 'min:0', 'max:30'],
        ], [], ['normal_ship_days' => 'batas kirim pesanan Normal']);

        AppSetting::put(AppSetting::NORMAL_SHIP_DAYS, (string) $data['normal_ship_days']);

        return redirect()
            ->route('admin.po-periods.index', $request->only('search'))
            ->with('success', "Pesanan Normal kini harus dikirim paling lambat {$data['normal_ship_days']} hari setelah dibayar.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * Produk yang bisa diberi lama PO: produk induk, lalu barang tanpa induk
     * (termasuk paket bundling).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function owners(string $search): Collection
    {
        $groups = ProductGroup::query()
            ->withCount('variants')
            ->with(['variants:id,product_group_id,sku'])
            ->search($search ?: null)
            ->orderBy('name')
            ->get()
            ->map(fn (ProductGroup $group) => [
                'key' => 'g'.$group->id,
                'name' => $group->name,
                'detail' => $group->variants_count.' varian · '.$group->variants->pluck('sku')->take(3)->implode(', ').($group->variants_count > 3 ? ', …' : ''),
                'bundle' => false,
                'active' => $group->is_active,
            ]);

        $singles = Product::query()
            ->whereNull('product_group_id')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'type', 'is_active'])
            ->map(fn (Product $product) => [
                'key' => 'p'.$product->id,
                'name' => $product->name,
                'detail' => $product->sku.($product->isBundle() ? ' · paket bundling (tanpa periode: ikut isi paketnya)' : ''),
                'bundle' => $product->isBundle(),
                'active' => $product->is_active,
            ]);

        return $groups->concat($singles)->values();
    }

    /**
     * Produk yang membuat resi PO belum punya batas kirim, dengan jumlah
     * resinya dan waktu bayar tertua (patokan tanggal mulai periodenya).
     *
     * @return Collection<string, array{orders: int, oldest: ?string}>
     */
    protected function missingOwners(): Collection
    {
        $rows = \Illuminate\Support\Facades\DB::table('shipment_order_items')
            ->join('shipment_orders', 'shipment_orders.id', '=', 'shipment_order_items.shipment_order_id')
            ->join('products', 'products.id', '=', 'shipment_order_items.product_id')
            ->whereNull('shipment_orders.cancelled_at')
            ->whereNull('shipment_orders.computed_ship_by')
            ->where(fn ($query) => $query->whereNull('shipment_orders.is_preorder')->orWhere('shipment_orders.is_preorder', true))
            ->whereNotExists(fn ($query) => $query->select(\Illuminate\Support\Facades\DB::raw(1))
                ->from('outbounds')
                ->whereColumn('outbounds.shipment_order_id', 'shipment_orders.id')
                ->where('outbounds.status', \App\Models\Outbound::STATUS_POSTED))
            ->selectRaw('products.product_group_id as group_id')
            ->selectRaw('CASE WHEN products.product_group_id IS NULL THEN products.id END as product_id')
            ->selectRaw('COUNT(DISTINCT shipment_orders.id) as orders')
            ->selectRaw('MIN(COALESCE(shipment_orders.paid_at, shipment_orders.order_date, shipment_orders.created_at)) as oldest')
            ->groupByRaw('products.product_group_id, CASE WHEN products.product_group_id IS NULL THEN products.id END')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            ($row->group_id ? 'g'.$row->group_id : 'p'.$row->product_id) => ['orders' => (int) $row->orders, 'oldest' => $row->oldest],
        ]);
    }

    /**
     * @param  array<int, string>  $owners
     * @return array<int, int>
     */
    protected function ids(array $owners, string $prefix): array
    {
        return collect($owners)
            ->filter(fn (string $owner) => str_starts_with($owner, $prefix))
            ->map(fn (string $owner) => (int) substr($owner, 1))
            ->unique()
            ->values()
            ->all();
    }
}
