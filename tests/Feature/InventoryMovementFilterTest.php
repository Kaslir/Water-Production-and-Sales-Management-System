<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementFilterTest extends TestCase
{
    use RefreshDatabase;

    private function movement(string $code, int $daysAgo, User $user, Product $product): ProductionBatch
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

        return $batch;
    }

    public function test_inventory_search_and_movement_dates_filter_history_but_not_current_stock(): void
    {
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->movement('B-OLD-001', 30, $user, $product);
        $this->movement('B-NEW-001', 5, $user, $product);
        $this->movement('B-NEW-002', 1, $user, $product);

        $this->actingAs($user)->get(route('inventory.index', [
            'tab' => 'movements',
            'batch' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ]))->assertOk()->assertSee('B-NEW-001')->assertDontSee('B-NEW-002')->assertDontSee('B-OLD-001')->assertSee('1 records')->assertSee('30 cases');

        $this->actingAs($user)->get(route('inventory.index', [
            'tab' => 'movements',
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('B-OLD-001')->assertDontSee('B-NEW-001');
    }

    public function test_invalid_inventory_date_range_is_rejected(): void
    {
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('inventory.index', [
            'tab' => 'movements',
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('to');
    }

    public function test_batch_tab_filters_batches_and_production_staff_can_open_it(): void
    {
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->movement('B-OLD-001', 30, $user, $product);
        $this->movement('B-NEW-001', 5, $user, $product);

        $this->actingAs($user)->get(route('inventory.index', [
            'tab' => 'batches',
            'batch' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
        ]))->assertOk()->assertSee('B-NEW-001')->assertDontSee('B-OLD-001')->assertDontSee('Record batch');

        $this->actingAs($user)->get(route('packaged.batches.index'))
            ->assertRedirect(route('inventory.index', ['tab' => 'batches']));
    }

    public function test_batches_are_default_and_stock_excludes_holds_expiry_and_reservations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $available = $this->movement('B-AVAILABLE', 2, $user, $product);
        $available->update(['cases_on_hand' => 8, 'cases_reserved' => 3, 'expires_on' => today()->addDays(15)]);
        StockMovement::create(['production_batch_id' => $available->id, 'signed_cases' => -2, 'reason' => 'customer_dispatch', 'performed_by' => $user->user_id]);
        $this->movement('B-HOLD', 1, $user, $product)->update(['status' => 'quality_hold']);
        $this->movement('B-EXPIRED', 3, $user, $product)->update(['expires_on' => today()->subDay()]);

        $default = $this->actingAs($user)->get(route('inventory.index'))->assertOk()
            ->assertSee('Batches &amp; Quality', false)->assertSee('Movement History')
            ->assertSee('Expires within 30 days')->assertSee('Expired')->assertSee('Quality Hold')
            ->assertDontSee('Stock overview')->assertDontSee('Record batch');
        $this->assertSame('batches', $default->viewData('tab'));
        $this->assertSame(5, $default->viewData('availableCases'));
        $default->assertSee('<td>3</td>', false);
        $this->assertSame('released', $available->fresh()->status);

        $movements = $this->actingAs($user)->get(route('inventory.index', ['tab' => 'movements']))->assertOk();
        $this->assertSame(5, $movements->viewData('availableCases'));
        $legacyLink = $this->actingAs($user)->get(route('inventory.index', ['tab' => 'stock']))->assertOk();
        $this->assertSame('batches', $legacyLink->viewData('tab'));
        $legacyLink->assertDontSee('Stock overview');
    }

    public function test_batch_filters_combine_product_quality_and_dates_with_empty_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $small = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $large = Product::where('sku', 'WATER-20L')->firstOrFail();
        $this->movement('FIND-RELEASED', 2, $user, $small);
        $this->movement('FIND-HOLD', 2, $user, $small)->update(['status' => 'quality_hold']);
        $this->movement('FIND-OTHER', 2, $user, $large);
        $this->movement('FIND-OLD', 40, $user, $small);

        $filters = ['tab' => 'batches', 'batch' => 'FIND', 'product_id' => $small->id, 'status' => 'released', 'from' => '2026-11-01', 'to' => '2026-11-05'];
        $response = $this->actingAs($user)->get(route('inventory.index', $filters))->assertOk()
            ->assertSee('FIND-RELEASED')->assertDontSee('FIND-HOLD')->assertDontSee('FIND-OTHER')->assertDontSee('FIND-OLD');
        $this->assertSame(1, $response->viewData('rows')->total());
        $this->assertSame((int) $small->id, (int) $response->viewData('filters')['product_id']);
        $this->actingAs($user)->get(route('inventory.index', array_replace($filters, ['batch' => 'MISSING'])))->assertOk()->assertSee('No records match these filters.');
        $this->actingAs($user)->get(route('inventory.index', ['product_id' => 999999]))->assertSessionHasErrors('product_id');

        for ($number = 1; $number <= 21; $number++) {
            $this->movement('PAGE-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 0, $user, $small);
        }
        $pageFilters = ['tab' => 'batches', 'batch' => 'PAGE', 'product_id' => $small->id, 'status' => 'released', 'from' => '2026-11-01', 'to' => '2026-11-05'];
        $firstPage = $this->actingAs($user)->get(route('inventory.index', $pageFilters))->assertOk();
        $this->assertSame(21, $firstPage->viewData('rows')->total());
        $this->assertSame(20, $firstPage->viewData('rows')->count());
        parse_str(parse_url($firstPage->viewData('rows')->url(2), PHP_URL_QUERY), $pageQuery);
        $this->assertSame('PAGE', $pageQuery['batch']);
        $this->assertSame((string) $small->id, (string) $pageQuery['product_id']);
        $this->assertSame('released', $pageQuery['status']);
        $this->assertSame('2026-11-01', $pageQuery['from']);
        $this->assertSame('2026-11-05', $pageQuery['to']);
        $this->actingAs($user)->get(route('inventory.index', $pageFilters + ['page' => 2]))->assertOk()->assertSee('PAGE-01');
    }

    public function test_movement_filters_show_signed_cases_references_and_preserve_query_on_page_two(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $small = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $large = Product::where('sku', 'WATER-20L')->firstOrFail();
        $this->movement('MOVE-LARGE', 1, $user, $large);
        for ($number = 1; $number <= 21; $number++) {
            $batch = $this->movement('MOVE-SMALL-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 0, $user, $small);
            $batch->update(['cases_on_hand' => 9]);
            StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => -1, 'reason' => 'quality_rejection', 'reference_type' => 'production_batch', 'reference_id' => $batch->id, 'performed_by' => $user->user_id, 'notes' => 'Entirely synthetic dataset']);
        }

        $filters = ['tab' => 'movements', 'batch' => 'MOVE-SMALL', 'product_id' => $small->id, 'movement_type' => 'quality_rejection', 'from' => '2026-11-05', 'to' => '2026-11-05'];
        $response = $this->actingAs($user)->get(route('inventory.index', $filters))->assertOk()
            ->assertSee('Outgoing -1 cases')->assertSee('Quality Rejection')->assertDontSee('Entirely synthetic dataset')->assertDontSee('MOVE-LARGE');
        $this->assertSame(21, $response->viewData('rows')->total());
        $this->assertSame(20, $response->viewData('rows')->count());
        parse_str(parse_url($response->viewData('rows')->url(2), PHP_URL_QUERY), $pageQuery);
        $this->assertSame('2', (string) $pageQuery['page']);
        $this->assertSame('MOVE-SMALL', $pageQuery['batch']);
        $this->assertSame((string) $small->id, (string) $pageQuery['product_id']);
        $this->assertSame('quality_rejection', $pageQuery['movement_type']);
        $this->assertSame('2026-11-05', $pageQuery['from']);
        $this->assertSame('2026-11-05', $pageQuery['to']);
        $this->actingAs($user)->get(route('inventory.index', $filters + ['page' => 2]))->assertOk()->assertSee('Outgoing -1 cases');
        $this->actingAs($user)->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'disposal']))->assertOk()->assertSee('No records match these filters.');
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'production_batch', 'notes' => 'Entirely synthetic dataset']);
    }

    public function test_movement_history_shows_actual_order_reference_and_meaningful_notes(): void
    {
        $user = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $batch = $this->movement('ORDER-BATCH', 0, $user, Product::where('sku', 'WATER-500ML')->firstOrFail());
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $order = SalesOrder::create(['order_number' => 'SO-REFERENCE', 'customer_id' => $customer->customer_id, 'ordered_on' => today(), 'status' => 'dispatched', 'delivery_address' => 'Nairobi', 'total_amount' => 100, 'created_by' => $user->user_id]);
        $dispatch = Dispatch::create(['sales_order_id' => $order->id, 'dispatched_on' => today(), 'status' => 'delivered', 'dispatched_by' => $user->user_id]);
        $batch->update(['cases_on_hand' => 6]);
        StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => -4, 'reason' => 'customer_dispatch', 'reference_type' => 'dispatch', 'reference_id' => $dispatch->id, 'performed_by' => $user->user_id, 'notes' => 'Delivered to hotel reception']);

        $this->actingAs($user)->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'customer_dispatch']))->assertOk()
            ->assertSee('Outgoing -4 cases')->assertSee('Order SO-REFERENCE')->assertSee('Delivered to hotel reception');
        $this->assertDatabaseHas('stock_movements', ['production_batch_id' => $batch->id, 'signed_cases' => -4, 'reference_id' => $dispatch->id]);
    }

    public function test_inventory_access_does_not_grant_quality_or_movement_edit_permissions(): void
    {
        $inventory = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $sales = User::create(['name' => 'Sales Staff', 'email' => 'sales@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);
        $batch = $this->movement('LOCKED', 0, $inventory, Product::where('sku', 'WATER-500ML')->firstOrFail());

        $this->actingAs($inventory)->get(route('inventory.index'))->assertOk();
        $this->actingAs($inventory)->get(route('packaged.batches.create'))->assertForbidden();
        $this->actingAs($inventory)->post(route('packaged.batches.release', $batch), [])->assertForbidden();
        $this->actingAs($inventory)->post(route('packaged.batches.dispose', $batch), [])->assertForbidden();
        $this->actingAs($sales)->get(route('inventory.index'))->assertForbidden();
        $this->assertDatabaseHas('stock_movements', ['production_batch_id' => $batch->id, 'signed_cases' => 10]);
    }
}
