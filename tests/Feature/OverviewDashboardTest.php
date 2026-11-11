<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryFeeCredit;
use App\Models\DeliveryTrip;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function batch(User $actor, string $code, int $cases, int $daysAgo, int $expiryDays, string $status = 'released'): ProductionBatch
    {
        return ProductionBatch::create([
            'product_id' => Product::where('sku', 'WATER-500ML')->value('id'), 'batch_code' => $code,
            'produced_on' => today()->subDays($daysAgo), 'expires_on' => today()->addDays($expiryDays),
            'cases_produced' => $cases, 'cases_on_hand' => $status === 'released' ? $cases : 0,
            'cases_reserved' => 0, 'status' => $status, 'total_cost' => $cases * 10,
            'cost_per_case' => 10, 'produced_by' => $actor->user_id,
        ]);
    }

    private function invoice(User $actor, ProductionBatch $batch, string $number, int $daysAgo, int $cases, float $amount, float $deliveryFee = 0, string $status = 'invoiced', string $origin = 'genuine'): array
    {
        $customer = Customer::create(['name' => 'Customer '.$number, 'phone_number' => '07'.str_pad((string) Customer::count(), 8, '0', STR_PAD_LEFT)]);
        $order = SalesOrder::create([
            'order_number' => 'SO-'.$number, 'customer_id' => $customer->customer_id,
            'ordered_on' => today()->subDays($daysAgo), 'status' => $status,
            'delivery_address' => 'Nairobi', 'delivery_fee' => $deliveryFee,
            'discount' => 0, 'delivery_cost' => 0, 'total_amount' => $amount,
            'data_origin' => $origin, 'created_by' => $actor->user_id,
        ]);
        $item = SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $batch->product_id, 'cases_ordered' => $cases, 'price_per_case' => ($amount - $deliveryFee) / $cases, 'line_total' => $amount - $deliveryFee]);
        $dispatch = Dispatch::create(['sales_order_id' => $order->id, 'dispatched_on' => today()->subDays($daysAgo), 'status' => 'delivered', 'dispatched_by' => $actor->user_id]);
        DispatchLine::create(['dispatch_id' => $dispatch->id, 'sales_order_item_id' => $item->id, 'production_batch_id' => $batch->id, 'cases' => $cases, 'cost_per_case' => 10]);
        $invoice = Invoice::create(['sales_order_id' => $order->id, 'invoice_number' => 'INV-'.$number, 'issued_on' => today()->subDays($daysAgo), 'amount' => $amount, 'status' => 'unpaid']);

        return [$order, $invoice];
    }

    public function test_operations_sidebar_matches_staff_module_permissions(): void
    {
        $allowedLinks = [
            'production_staff' => ['production.index', 'inventory.index'],
            'inventory_staff' => ['inventory.index'],
            'distribution_staff' => ['distribution.index', 'customers.index'],
            'sales_staff' => ['sales.index', 'customers.index'],
            'auditor' => [],
            'manager' => ['production.index', 'inventory.index', 'distribution.index', 'sales.index', 'customers.index'],
            'administrator' => ['production.index', 'inventory.index', 'distribution.index', 'sales.index', 'customers.index'],
        ];
        $operations = ['production.index', 'inventory.index', 'distribution.index', 'sales.index', 'customers.index'];

        foreach ($allowedLinks as $role => $visibleLinks) {
            $response = $this->actingAs($this->user($role))->get(route('dashboard'))->assertOk();

            foreach ($operations as $routeName) {
                $link = 'href="'.route($routeName).'"';
                if (in_array($routeName, $visibleLinks, true)) {
                    $response->assertSee($link, false);
                } else {
                    $response->assertDontSee($link, false);
                }
            }

            $response->assertSee('href="'.route('profile.show').'"', false);
        }
    }

    public function test_overview_uses_last_thirty_calendar_days_and_current_saleable_stock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $manager = $this->user('manager');
        $oldBatch = $this->batch($manager, 'OLD', 20, 30, 80);
        $boundaryBatch = $this->batch($manager, 'BOUNDARY', 7, 29, 80);
        $recentBatch = $this->batch($manager, 'RECENT', 5, 0, 80);
        $this->invoice($manager, $oldBatch, 'OLD', 30, 7, 1000);
        $this->invoice($manager, $boundaryBatch, 'BOUNDARY', 29, 2, 300, origin: 'synthetic');
        $this->invoice($manager, $recentBatch, 'NEW', 0, 3, 200);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertSee('Business performance — Last 30 days')
            ->assertDontSee('Reporting period')
            ->assertSee('Produced 05 Nov 2026');
        $data = $response->viewData('data');
        $this->assertEquals(32, $data['availableCases']);
        $this->assertEquals(12, $data['producedCases']);
        $this->assertEquals(5, $data['dispatchedCases']);
        $this->assertEquals(500, $data['revenue']);
        $this->assertTrue($data['hasDemonstrationInvoices']);
        $this->assertCount(30, $data['salesChart']);
        $this->assertSame(['label' => '07 Oct 2026', 'total' => 300.0], $data['salesChart']->first());
        $this->assertSame(['label' => '05 Nov 2026', 'total' => 200.0], $data['salesChart']->last());
        $this->assertSame(0.0, $data['salesChart'][1]['total']);

        $ignoredQuery = $this->actingAs($manager)->get(route('dashboard', ['period' => 'all']))->assertOk()->viewData('data');
        $this->assertEquals(500, $ignoredQuery['revenue']);
    }

    public function test_overview_uses_business_timezone_and_shows_zero_activity_without_hiding_current_stock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30:00', 'UTC'));
        $manager = $this->user('manager');
        $batch = $this->batch($manager, 'HISTORICAL', 9, 40, 80);
        $this->invoice($manager, $batch, 'HISTORICAL', 40, 2, 250);
        $batch->update(['produced_on' => '2026-10-06']);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertSee('No invoices in the last 30 days');
        $data = $response->viewData('data');
        $this->assertSame(0, (int) $data['producedCases']);
        $this->assertSame(0, (int) $data['dispatchedCases']);
        $this->assertSame(0, (int) $data['revenue']);
        $this->assertSame(9, (int) $data['availableCases']);
        $this->assertFalse($data['hasRecentInvoices']);
        $this->assertSame('07 Oct 2026', $data['salesChart']->first()['label']);
        $this->assertSame('05 Nov 2026', $data['salesChart']->last()['label']);
        $this->assertSame(0.0, $data['salesChart']->sum('total'));
        $this->assertEquals(250, $response->viewData('financialSummary')['outstanding']);
        $this->assertEquals(0, $response->viewData('financialSummary')['revenue']);
    }

    public function test_attention_counts_use_current_records_and_staff_visibility(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $manager = $this->user('manager');
        $this->batch($manager, 'EXPIRING', 8, 40, 10);
        $this->batch($manager, 'HOLD', 6, 40, 70, 'quality_hold');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        SalesOrder::create(['order_number' => 'SO-WAIT', 'customer_id' => $customer->customer_id, 'ordered_on' => today()->subDays(40), 'status' => 'confirmed', 'delivery_address' => 'Nairobi', 'discount' => 0, 'delivery_cost' => 0, 'total_amount' => 100, 'created_by' => $manager->user_id]);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->assertSame([1, 1, 1], array_column($response->viewData('attention'), 'count'));
        $response->assertSee('Batches approaching expiry')->assertSee('Batches awaiting quality approval')->assertSee('Confirmed orders awaiting dispatch')->assertSee('26 Sep 2026');

        $production = $this->user('production_staff');
        $this->actingAs($production)->get(route('dashboard'))->assertOk()
            ->assertSee('Batches awaiting quality approval')->assertDontSee('Financial Summary')->assertDontSee('Recent customer orders');
        $sales = $this->user('sales_staff');
        $this->actingAs($sales)->get(route('dashboard'))->assertOk()
            ->assertSee('Confirmed orders awaiting dispatch')->assertDontSee('Batches awaiting quality approval')->assertDontSee('Production Planning Summary');
    }

    public function test_financial_summary_matches_profit_report_including_delivery_fees_and_trip_costs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $manager = $this->user('manager');
        $batch = $this->batch($manager, 'FINANCE', 20, 31, 80);
        $this->invoice($manager, $batch, 'EARLIER', 31, 7, 1000);
        [$order, $invoice] = $this->invoice($manager, $batch, 'CURRENT', 2, 3, 200, 20);
        DeliveryFeeCredit::create(['invoice_id' => $invoice->id, 'amount' => 20, 'reason' => 'Approved fee correction', 'approved_by' => $manager->user_id]);
        Payment::create(['invoice_id' => $invoice->id, 'amount' => 50, 'paid_on' => today(), 'method' => 'Bank transfer', 'recorded_by' => $manager->user_id]);
        $trip = DeliveryTrip::create(['truck_identifier' => 'TRUCK-1', 'driver_name' => 'Driver', 'delivery_on' => today()->subDays(2), 'fuel_expense' => 10, 'other_expense' => 5, 'created_by' => $manager->user_id]);
        $trip->orders()->attach($order->id);
        OperatingExpense::create(['expense_date' => today(), 'category' => 'Utilities', 'amount' => 5, 'recorded_by' => $manager->user_id]);

        $summary = $this->actingAs($manager)->get(route('dashboard'))->assertOk()->viewData('financialSummary');
        $this->assertEquals(180, $summary['revenue']);
        $this->assertEquals(150, $summary['grossProfit']);
        $this->assertEquals(130, $summary['operatingProfit']);
        $this->assertEquals(1130, $summary['outstanding']);
        $this->assertEquals(0, $summary['deliveryFeeRevenue']);
        $this->assertEquals(15, $summary['tripExpenses']);

        $report = $this->actingAs($manager)->get(route('packaged.reports.index', ['from' => '2026-10-07', 'to' => '2026-11-05']))->assertOk();
        foreach (['revenue', 'grossProfit', 'operatingProfit', 'outstanding'] as $metric) {
            $this->assertEquals($summary[$metric], $report->viewData($metric));
        }
    }

    public function test_planning_summary_uses_only_valid_current_seven_day_random_forest_forecasts(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->batch($manager, 'STOCK', 5, 2, 80);
        ProductForecast::create(['product_id' => $product->id, 'origin_date' => today()->subDay(), 'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 20, 'mae_cases' => 2, 'bias_cases' => 0, 'evaluation' => ['selected' => true, 'mode' => 'current']]);
        ProductForecast::create(['product_id' => Product::where('sku', 'WATER-1L')->value('id'), 'origin_date' => today()->subDay(), 'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 50, 'mae_cases' => 2, 'bias_cases' => 0, 'evaluation' => ['selected' => true, 'mode' => 'historical_demo']]);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->assertCount(1, $response->viewData('planning'));
        $this->assertSame(17, $response->viewData('planning')->first()['recommendedCases']);
        $response->assertSee('17 additional cases');
    }
}
