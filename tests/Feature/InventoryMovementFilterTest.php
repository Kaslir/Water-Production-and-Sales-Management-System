<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementFilterTest extends TestCase
{
    use RefreshDatabase;

    private function movement(string $code, int $daysAgo, User $user, Product $product): void
    {
        $batch = ProductionBatch::create([
            'product_id' => $product->id,
            'batch_code' => $code,
            'produced_on' => today()->subDays($daysAgo)->toDateString(),
            'expires_on' => today()->addMonths(6)->toDateString(),
            'cases_produced' => 10,
            'cases_on_hand' => 10,
            'status' => 'released',
            'total_cost' => 0,
            'cost_per_case' => 0,
            'produced_by' => $user->user_id,
        ]);
        $movement = StockMovement::create([
            'production_batch_id' => $batch->id,
            'signed_cases' => 10,
            'reason' => 'production',
            'performed_by' => $user->user_id,
        ]);
        $movement->forceFill(['created_at' => today()->subDays($daysAgo)])->save();
    }

    public function test_inventory_search_and_movement_dates_filter_history_but_not_current_stock(): void
    {
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->movement('B-OLD-001', 30, $user, $product);
        $this->movement('B-NEW-001', 5, $user, $product);
        $this->movement('B-NEW-002', 1, $user, $product);

        $this->actingAs($user)->get(route('inventory.index', [
            'batch' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ]))->assertOk()->assertSee('B-NEW-001')->assertDontSee('B-NEW-002')->assertDontSee('B-OLD-001')->assertSee('1 records')->assertSee('30 cases');

        $this->actingAs($user)->get(route('inventory.index', [
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('B-OLD-001')->assertDontSee('B-NEW-001');
    }

    public function test_invalid_inventory_date_range_is_rejected(): void
    {
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('inventory.index', [
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('to');
    }
}
