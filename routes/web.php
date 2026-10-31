<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\CourierReportController;
use App\Http\Controllers\Admin\DailyWaybillReportController;
use App\Http\Controllers\Admin\DamagedStockController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InboundController;
use App\Http\Controllers\Admin\OutboundController;
use App\Http\Controllers\Admin\OutboundHandoverController;
use App\Http\Controllers\Admin\OutboundMarketplaceController;
use App\Http\Controllers\Admin\OutboundScanController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\RestockReportController;
use App\Http\Controllers\Admin\ReturnMarketplaceController;
use App\Http\Controllers\Admin\ReturnReceiptController;
use App\Http\Controllers\Admin\ReturnScanController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShipmentImportController;
use App\Http\Controllers\Admin\ShipmentOrderCancellationController;
use App\Http\Controllers\Admin\ShipmentSkuMatchController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockApiAllowedIpController;
use App\Http\Controllers\Admin\StockMovementController;
use App\Http\Controllers\Admin\StockOpnameController;
use App\Http\Controllers\Admin\StockOpnameStationController;
use App\Http\Controllers\Admin\StockReportController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\OrderPriorityController;
use App\Http\Controllers\Admin\PoPeriodController;
use App\Http\Controllers\Admin\ShipmentOrderDeadlineController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PackingListController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('auth')
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Operasional gudang
        Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
        Route::get('products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import.store');
        Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::patch('products/bulk/min-stock', [ProductController::class, 'bulkMinStock'])->name('products.bulk.min-stock');
        Route::post('products/variants', [ProductGroupController::class, 'store'])->name('products.groups.store');
        Route::get('products/variants/{product_group}', [ProductGroupController::class, 'show'])->name('products.groups.show');
        Route::get('products/variants/{product_group}/edit', [ProductGroupController::class, 'edit'])->name('products.groups.edit');
        Route::put('products/variants/{product_group}', [ProductGroupController::class, 'update'])->name('products.groups.update');
        Route::delete('products/variants/{product_group}', [ProductGroupController::class, 'destroy'])->name('products.groups.destroy');
        Route::post('products/variants/{product_group}/variants', [ProductGroupController::class, 'addVariants'])->name('products.groups.variants.store');
        Route::post('products/variants/{product_group}/attach', [ProductGroupController::class, 'attach'])->name('products.groups.attach');
        Route::delete('products/variants/{product_group}/variants/{product}', [ProductGroupController::class, 'detach'])->name('products.groups.detach');
        Route::resource('products', ProductController::class);
        // Barang bervarian dikelola dari halaman Barang. URL lama menu
        // Produk Varian diarahkan ke sana.
        Route::get('product-groups', fn () => redirect()->route('admin.products.index', ['type' => 'variant']));
        Route::get('product-groups/create', fn () => redirect()->route('admin.products.create', ['type' => 'variant']));
        Route::get('product-groups/{id}', fn (int $id) => redirect()->route('admin.products.groups.show', $id))->whereNumber('id');
        Route::get('product-groups/{id}/edit', fn (int $id) => redirect()->route('admin.products.groups.edit', $id))->whereNumber('id');

        Route::get('movements/export', [StockMovementController::class, 'export'])->name('movements.export');
        Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');

        Route::get('reports/stock/export', [StockReportController::class, 'export'])->name('reports.stock.export');
        Route::get('reports/stock', [StockReportController::class, 'index'])->name('reports.stock');
        Route::get('reports/restock/export', [RestockReportController::class, 'export'])->name('reports.restock.export');
        Route::get('reports/restock', [RestockReportController::class, 'index'])->name('reports.restock');

        Route::get('inbounds/export', [InboundController::class, 'export'])->name('inbounds.export');
        Route::post('inbounds/{inbound}/submit', [InboundController::class, 'submit'])->name('inbounds.submit');
        Route::post('inbounds/{inbound}/approve', [InboundController::class, 'approve'])->name('inbounds.approve');
        Route::post('inbounds/{inbound}/withdraw', [InboundController::class, 'withdraw'])->name('inbounds.withdraw');
        Route::resource('inbounds', InboundController::class);

        Route::get('outbounds/export', [OutboundController::class, 'export'])->name('outbounds.export');
        Route::get('outbounds/handover/export', [OutboundHandoverController::class, 'export'])->name('outbounds.handover.export');
        Route::get('outbounds/marketplace', [OutboundMarketplaceController::class, 'create'])->name('outbounds.marketplace');
        Route::post('outbounds/marketplace', [OutboundMarketplaceController::class, 'store'])->name('outbounds.marketplace.store');
        Route::get('outbounds/handover', [OutboundHandoverController::class, 'index'])->name('outbounds.handover');
        Route::post('outbounds/handover', [OutboundHandoverController::class, 'process'])->name('outbounds.handover.process');
        Route::post('outbounds/handover/scan', [OutboundHandoverController::class, 'scan'])->name('outbounds.handover.scan');
        // Alamat lama halaman ini, dulu bernama "Siap Dikirim".
        Route::get('outbounds/ready', [OutboundHandoverController::class, 'legacy'])->name('outbounds.handover.legacy');
        Route::get('outbounds/{outbound}/scan', [OutboundScanController::class, 'show'])->name('outbounds.scan');
        Route::post('outbounds/{outbound}/scan/resi', [OutboundScanController::class, 'resi'])->name('outbounds.scan.resi');
        Route::post('outbounds/{outbound}/scan/item', [OutboundScanController::class, 'item'])->name('outbounds.scan.item');
        Route::post('outbounds/{outbound}/scan/reset', [OutboundScanController::class, 'reset'])->name('outbounds.scan.reset');
        Route::post('outbounds/{outbound}/submit', [OutboundController::class, 'submit'])->name('outbounds.submit');
        Route::post('outbounds/{outbound}/approve', [OutboundController::class, 'approve'])->name('outbounds.approve');
        Route::post('outbounds/{outbound}/withdraw', [OutboundController::class, 'withdraw'])->name('outbounds.withdraw');
        Route::resource('outbounds', OutboundController::class);

        Route::get('returns/export', [ReturnReceiptController::class, 'export'])->name('returns.export');
        Route::get('returns/marketplace', [ReturnMarketplaceController::class, 'create'])->name('returns.marketplace');
        Route::post('returns/marketplace', [ReturnMarketplaceController::class, 'store'])->name('returns.marketplace.store');
        Route::post('returns/marketplace/manual', [ReturnMarketplaceController::class, 'manual'])->name('returns.marketplace.manual');
        Route::post('returns/marketplace/lookup', [ReturnMarketplaceController::class, 'lookupItem'])->name('returns.marketplace.lookup');
        Route::post('returns/marketplace/{return}/item', [ReturnMarketplaceController::class, 'addItem'])->name('returns.marketplace.item');
        Route::delete('returns/marketplace/{return}/item/{item}', [ReturnMarketplaceController::class, 'removeItem'])->name('returns.marketplace.item.remove');
        Route::post('returns/marketplace/{return}/finish', [ReturnMarketplaceController::class, 'finish'])->name('returns.marketplace.finish');
        Route::get('returns/{return}/scan', [ReturnScanController::class, 'show'])->name('returns.scan');
        Route::post('returns/{return}/scan/resi', [ReturnScanController::class, 'resi'])->name('returns.scan.resi');
        Route::post('returns/{return}/scan/reset', [ReturnScanController::class, 'reset'])->name('returns.scan.reset');
        Route::post('returns/{return}/submit', [ReturnReceiptController::class, 'submit'])->name('returns.submit');
        Route::post('returns/{return}/approve', [ReturnReceiptController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return}/withdraw', [ReturnReceiptController::class, 'withdraw'])->name('returns.withdraw');
        Route::resource('returns', ReturnReceiptController::class)->parameters(['returns' => 'return']);

        Route::get('adjustments/export', [StockAdjustmentController::class, 'export'])->name('adjustments.export');
        Route::post('adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submit'])->name('adjustments.submit');
        Route::post('adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/withdraw', [StockAdjustmentController::class, 'withdraw'])->name('adjustments.withdraw');
        Route::resource('adjustments', StockAdjustmentController::class);

        Route::get('disposals/export', [DamagedStockController::class, 'export'])->name('disposals.export');
        Route::post('disposals/{disposal}/submit', [DamagedStockController::class, 'submit'])->name('disposals.submit');
        Route::post('disposals/{disposal}/approve', [DamagedStockController::class, 'approve'])->name('disposals.approve');
        Route::post('disposals/{disposal}/withdraw', [DamagedStockController::class, 'withdraw'])->name('disposals.withdraw');
        Route::resource('disposals', DamagedStockController::class)->except(['edit', 'update']);

        Route::get('opnames/{opname}/export', [StockOpnameController::class, 'export'])->name('opnames.export');
        Route::get('opnames/{opname}/station', [StockOpnameStationController::class, 'show'])->name('opnames.station');
        Route::get('opnames/{opname}/station/progress', [StockOpnameStationController::class, 'progress'])->name('opnames.station.progress');
        Route::post('opnames/{opname}/station/lookup', [StockOpnameStationController::class, 'lookup'])->name('opnames.station.lookup');
        Route::post('opnames/{opname}/station/count', [StockOpnameStationController::class, 'count'])->name('opnames.station.count');
        Route::post('opnames/{opname}/count', [StockOpnameController::class, 'count'])->name('opnames.count');
        Route::post('opnames/{opname}/submit', [StockOpnameController::class, 'submit'])->name('opnames.submit');
        Route::post('opnames/{opname}/approve', [StockOpnameController::class, 'approve'])->name('opnames.approve');
        Route::post('opnames/{opname}/withdraw', [StockOpnameController::class, 'withdraw'])->name('opnames.withdraw');
        Route::resource('opnames', StockOpnameController::class)->except(['edit', 'update']);

        Route::resource('suppliers', SupplierController::class);

        // Persetujuan dokumen
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        // Import resi dari TikTok Shop
        Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
        Route::get('orders/priority', [OrderPriorityController::class, 'create'])->name('orders.priority');
        Route::post('orders/priority', [OrderPriorityController::class, 'store'])->name('orders.priority.store');
        Route::put('orders/{order}/deadline', [ShipmentOrderDeadlineController::class, 'update'])->name('orders.deadline');
        Route::delete('orders/{order}/deadline', [ShipmentOrderDeadlineController::class, 'destroy'])->name('orders.deadline.reset');
        Route::get('po-periods', [PoPeriodController::class, 'index'])->name('po-periods.index');
        Route::post('po-periods', [PoPeriodController::class, 'store'])->name('po-periods.store');
        Route::put('po-periods/settings', [PoPeriodController::class, 'settings'])->name('po-periods.settings');
        Route::delete('po-periods/{period}', [PoPeriodController::class, 'destroy'])->name('po-periods.destroy');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/packing-list/excel', [PackingListController::class, 'excel'])->name('orders.packing.excel');
        Route::get('orders/packing-list/pdf', [PackingListController::class, 'pdf'])->name('orders.packing.pdf');
        Route::get('orders/packing-list', [PackingListController::class, 'index'])->name('orders.packing');
        // Status Resi sudah digabung ke Pesanan; alamat lamanya tetap jalan.
        Route::get('imports/status', fn (\Illuminate\Http\Request $request) => redirect()->route('admin.orders.index', $request->query(), 301))->name('imports.status');
        Route::get('imports/daily/export', [DailyWaybillReportController::class, 'export'])->name('imports.daily.export');
        Route::get('imports/daily', [DailyWaybillReportController::class, 'index'])->name('imports.daily');
        Route::get('imports/couriers/export', [CourierReportController::class, 'export'])->name('imports.couriers.export');
        Route::get('imports/couriers', CourierReportController::class)->name('imports.couriers');
        Route::post('imports/rematch', [ShipmentSkuMatchController::class, 'all'])->name('imports.rematch');
        Route::post('imports/orders/{order}/rematch', [ShipmentSkuMatchController::class, 'order'])->name('imports.orders.rematch');
        Route::post('imports/orders/{order}/cancel', [ShipmentOrderCancellationController::class, 'store'])->name('imports.orders.cancel');
        Route::delete('imports/orders/{order}/cancel', [ShipmentOrderCancellationController::class, 'destroy'])->name('imports.orders.restore');
        Route::get('imports/lookup', [ShipmentImportController::class, 'lookup'])->name('imports.lookup');
        Route::get('imports/batches', [ShipmentImportController::class, 'batches'])->name('imports.batches');
        Route::get('imports/{import}/failures', [ShipmentImportController::class, 'failures'])->name('imports.failures');
        Route::resource('imports', ShipmentImportController::class)->except('edit', 'update')
            ->parameters(['imports' => 'import']);

        // Manajemen akses
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::put('permissions', [PermissionController::class, 'update'])->name('permissions.update');

        Route::get('stock-api-access', [StockApiAllowedIpController::class, 'index'])->name('stock-api-access.index');
        Route::post('stock-api-access', [StockApiAllowedIpController::class, 'store'])->name('stock-api-access.store');
        Route::put('stock-api-access/{stockApiAllowedIp}', [StockApiAllowedIpController::class, 'update'])->name('stock-api-access.update');
        Route::delete('stock-api-access/{stockApiAllowedIp}', [StockApiAllowedIpController::class, 'destroy'])->name('stock-api-access.destroy');

        Route::resource('users', UserController::class);
        Route::resource('roles', RoleController::class)->except('show');
    });

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
