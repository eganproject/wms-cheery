<?php

namespace Tests\Feature;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\StockApiAllowedIp;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API mutasi stok dan laporan stok.
 */
class StockReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected Product $oli;

    protected Product $rem;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stock_api.enabled' => true, 'stock_api.token' => 'test-secret']);
        StockApiAllowedIp::create(['ip_address' => '127.0.0.1', 'label' => 'Test', 'is_active' => true]);

        $this->oli = Product::create(['sku' => 'FLT-OLI', 'name' => 'Filter Oli', 'category' => 'Filter', 'unit' => 'pcs', 'min_stock' => 2]);
        $this->rem = Product::create(['sku' => 'KMP-REM', 'name' => 'Kampas Rem', 'category' => 'Rem', 'unit' => 'set', 'min_stock' => 1]);
        $this->oli->forceFill(['stock' => 6])->save();
        $this->rem->forceFill(['stock' => 5])->save();

        $this->movement($this->oli, 'in', 10, 10, Inbound::class, '2026-08-01 08:00:00');
        $this->movement($this->oli, 'out', 4, 6, Outbound::class, '2026-08-02 09:00:00');
        $this->movement($this->rem, 'in', 5, 5, Inbound::class, '2026-08-02 10:00:00');
        $this->movement($this->rem, 'in', 2, 2, null, '2026-08-02 11:00:00', StockMovement::BUCKET_DAMAGED);
    }

    public function test_movements_require_a_token(): void
    {
        $this->getJson('/api/v1/stock-movements')->assertUnauthorized();
        $this->getJson('/api/v1/stock-report')->assertUnauthorized();
    }

    public function test_movements_are_filtered_and_paginated(): void
    {
        $this->api('/api/v1/stock-movements?from=2026-08-01&to=2026-08-31&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('meta.summary.incoming', 17)
            ->assertJsonPath('meta.summary.outgoing', 4)
            ->assertJsonCount(2, 'data')
            // Terbaru lebih dulu, sama seperti halaman admin.
            ->assertJsonPath('data.0.sku', 'KMP-REM')
            ->assertJsonPath('data.0.bucket', 'damaged');

        $this->api('/api/v1/stock-movements?from=2026-08-01&to=2026-08-31&sku=FLT-OLI&type=out')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.quantity', 4)
            ->assertJsonPath('data.0.balance_after', 6)
            ->assertJsonPath('data.0.source', 'outbound');

        $this->api('/api/v1/stock-movements?from=2026-08-02&to=2026-08-02&source=inbound&bucket=good')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'KMP-REM');

        $this->api('/api/v1/stock-movements?range=semua&search=oli')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_movements_default_to_today(): void
    {
        $this->api('/api/v1/stock-movements')
            ->assertOk()
            ->assertJsonPath('meta.from', now()->toDateString())
            ->assertJsonPath('meta.total', 0);
    }

    public function test_movements_reject_invalid_parameters(): void
    {
        foreach (['type=x', 'bucket=x', 'source=x', 'from=2026-13-01', 'from=2026-08-05&to=2026-08-01', 'per_page=501', 'range=semua&from=2026-08-01', 'product_id=abc'] as $query) {
            $this->api('/api/v1/stock-movements?'.$query)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'INVALID_PARAMETER');
        }
    }

    public function test_report_returns_opening_and_closing_balance_per_item(): void
    {
        $this->api('/api/v1/stock-report?from=2026-08-02&to=2026-08-02&sort=nama')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.summary.outgoing', 4)
            ->assertJsonPath('data.0.sku', 'FLT-OLI')
            ->assertJsonPath('data.0.opening', 10)
            ->assertJsonPath('data.0.outgoing', 4)
            ->assertJsonPath('data.0.sold', 4)
            ->assertJsonPath('data.0.closing', 6)
            ->assertJsonPath('data.1.sku', 'KMP-REM')
            ->assertJsonPath('data.1.opening', 0)
            ->assertJsonPath('data.1.incoming', 5)
            ->assertJsonPath('data.1.closing', 5);

        $this->api('/api/v1/stock-report?from=2026-08-01&to=2026-08-31&category=Rem')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'KMP-REM');

        $this->api('/api/v1/stock-report?from=2026-08-01&to=2026-08-31&view=bergerak&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'FLT-OLI');
    }

    public function test_report_rejects_invalid_parameters(): void
    {
        foreach (['view=x', 'sort=x', 'to=2026-02-30', 'from=2026-08-05&to=2026-08-01', 'page=0'] as $query) {
            $this->api('/api/v1/stock-report?'.$query)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'INVALID_PARAMETER');
        }
    }

    protected function api(string $uri)
    {
        return $this->withToken('test-secret')->getJson($uri);
    }

    protected function movement(Product $product, string $type, int $qty, int $balance, ?string $reference, string $at, string $bucket = StockMovement::BUCKET_GOOD): void
    {
        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'bucket' => $bucket,
            'quantity' => $qty,
            'balance_after' => $balance,
            'reference_type' => $reference,
            'reference_id' => $reference ? 1 : null,
            'description' => 'Uji',
        ])->forceFill(['created_at' => $at])->saveQuietly();
    }
}
