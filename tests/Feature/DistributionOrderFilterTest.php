<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionOrderFilterTest extends TestCase
{
    use RefreshDatabase;

    private function dispatch(string $number, int $daysAgo, int $cases, User $user, Customer $customer, Product $product, ProductionBatch $batch): void
    {
        $order = SalesOrder::create([
            'order_number' => $number,
            'customer_id' => $customer->customer_id,
            'ordered_on' => today()->subDays($daysAgo)->toDateString(),
            'status' => 'dispatched',
            'delivery_address' => 'Nairobi',
            'total_amount' => $cases * 100,
            'created_by' => $user->user_id,
        ]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'cases_ordered' => $cases,
            'price_per_case' => 100,
            'line_total' => $cases * 100,
        ]);
        $dispatch = Dispatch::create([
            'sales_order_id' => $order->id,
            'dispatched_on' => today()->subDays($daysAgo)->toDateString(),
            'status' => 'dispatched',
            'dispatched_by' => $user->user_id,
        ]);
        DispatchLine::create([
            'dispatch_id' => $dispatch->id,
            'sales_order_item_id' => $item->id,
            'production_batch_id' => $batch->id,
            'cases' => $cases,
            'cost_per_case' => 0,
        ]);
    }

    public function test_order_number_and_dispatch_dates_filter_rows_and_case_total(): void
    {
        $user = User::create(['name' => 'Distribution Staff', 'email' => 'distribution@example.test', 'password' => 'password-long-enough', 'role' => 'distribution_staff', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $batch = ProductionBatch::create([
            'product_id' => $product->id,
            'batch_code' => 'B-TEST-001',
            'produced_on' => today()->subMonths(2)->toDateString(),
            'expires_on' => today()->addMonths(6)->toDateString(),
            'cases_produced' => 60,
            'cases_on_hand' => 0,
            'status' => 'released',
            'total_cost' => 0,
            'cost_per_case' => 0,
            'produced_by' => $user->user_id,
        ]);
        $this->dispatch('ORD-OLD-001', 30, 10, $user, $customer, $product, $batch);
        $this->dispatch('ORD-NEW-001', 5, 20, $user, $customer, $product, $batch);
        $this->dispatch('ORD-NEW-002', 1, 30, $user, $customer, $product, $batch);

        $this->actingAs($user)->get(route('distribution.index', [
            'order' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ]))->assertOk()->assertSee('ORD-NEW-001')->assertDontSee('ORD-NEW-002')->assertDontSee('ORD-OLD-001')->assertSee('20 cases')->assertSee('1 records');

        $this->actingAs($user)->get(route('distribution.index', [
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('ORD-OLD-001')->assertDontSee('ORD-NEW-001')->assertSee('10 cases');
    }

    public function test_invalid_dispatch_date_range_is_rejected(): void
    {
        $user = User::create(['name' => 'Distribution Staff', 'email' => 'distribution@example.test', 'password' => 'password-long-enough', 'role' => 'distribution_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('distribution.index', [
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('to');
    }
}
