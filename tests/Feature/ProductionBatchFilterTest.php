<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionBatchFilterTest extends TestCase
{
    use RefreshDatabase;

    private function batch(string $code, int $daysAgo, int $cases, User $user, Product $product): void
    {
        ProductionBatch::create([
            'product_id' => $product->id,
            'batch_code' => $code,
            'produced_on' => today()->subDays($daysAgo)->toDateString(),
            'expires_on' => today()->addMonths(6)->toDateString(),
            'cases_produced' => $cases,
            'cases_on_hand' => $cases,
            'status' => 'quality_hold',
            'total_cost' => 0,
            'cost_per_case' => 0,
            'produced_by' => $user->user_id,
        ]);
    }

    public function test_batch_code_and_production_dates_filter_rows_and_summary(): void
    {
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->batch('B-OLD-001', 30, 10, $user, $product);
        $this->batch('B-NEW-001', 5, 20, $user, $product);
        $this->batch('B-NEW-002', 1, 30, $user, $product);

        $this->actingAs($user)->get(route('production.index', [
            'batch' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ]))->assertOk()->assertSee('B-NEW-001')->assertDontSee('B-NEW-002')->assertDontSee('B-OLD-001')->assertSee('20 cases')->assertSee('1 records');

        $this->actingAs($user)->get(route('production.index', [
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('B-OLD-001')->assertDontSee('B-NEW-001');
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('production.index', [
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('to');
    }
}
