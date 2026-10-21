<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PackagedWaterService;
use App\Services\ProductForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PackagedWaterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => true]);
    }

    private function batch(PackagedWaterService $service, User $actor, Product $product, string $code, int $cases, int $expiryDays): ProductionBatch
    {
        return $service->createBatch([
            'product_id' => $product->id,
            'batch_code' => $code,
            'produced_on' => today()->subDay()->toDateString(),
            'expires_on' => today()->addDays($expiryDays)->toDateString(),
            'cases_produced' => $cases,
            'water_treatment_cost' => 100,
            'bottle_cost' => 200,
            'cap_label_cost' => 50,
            'labour_cost' => 50,
            'electricity_cost' => 50,
            'other_production_cost' => 50,
        ], $actor->user_id);
    }

    public function test_quality_hold_expiry_fefo_and_single_dispatch_deduction(): void
    {
        $actor = $this->actor();
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0700000000']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $service = app(PackagedWaterService::class);
        $early = $this->batch($service, $actor, $product, 'EARLY', 10, 5);
        $late = $this->batch($service, $actor, $product, 'LATE', 10, 20);
        $expired = $this->batch($service, $actor, $product, 'EXPIRED', 10, -1);
        $this->assertSame('quality_hold', $early->status);
        try {
            $service->releaseBatch($expired, $actor->user_id, 'Passed test');
            $this->fail('Expired batch should not be released.');
        } catch (ValidationException) {
            $this->assertSame('quality_hold', $expired->fresh()->status);
        }
        $service->releaseBatch($early, $actor->user_id, 'Passed microbiological test');
        $service->releaseBatch($late, $actor->user_id, 'Passed microbiological test');
        $order = $service->createOrder(['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'discount' => 0, 'delivery_cost' => 30, 'items' => [['product_id' => $product->id, 'cases_ordered' => 12, 'price_per_case' => 100]]], $actor->user_id);
        $service->confirmOrder($order, $actor->user_id);
        $this->assertSame(10, $early->fresh()->cases_reserved);
        $this->assertSame(2, $late->fresh()->cases_reserved);
        $this->assertSame(10, $early->fresh()->cases_on_hand);
        $service->dispatchOrder($order, $actor->user_id, 'TRIP-1');
        $this->assertSame(0, $early->fresh()->cases_on_hand);
        $this->assertSame(8, $late->fresh()->cases_on_hand);
        $this->assertSame(2, StockMovement::where('reason', 'customer_dispatch')->count());
        try {
            $service->dispatchOrder($order, $actor->user_id, 'TRIP-2');
            $this->fail('The order should not be dispatched twice.');
        } catch (ValidationException) {
            $this->assertSame(2, StockMovement::where('reason', 'customer_dispatch')->count());
        }
        $service->deliverOrder($order, $actor->user_id);
        $this->assertSame(2, StockMovement::where('reason', 'customer_dispatch')->count());
        $invoice = $service->invoiceOrder($order, $actor->user_id);
        $this->assertEquals(1200, $invoice->amount);
        $service->recordPayment($invoice, ['amount' => 1200, 'method' => 'Bank transfer'], $actor->user_id);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.dispatched', 'actor_id' => $actor->user_id]);
        $this->actingAs($actor)->get(route('packaged.orders.show', $order))->assertOk()->assertSee('EARLY')->assertSee('LATE');
        $this->actingAs($actor)->get(route('packaged.reports.index'))->assertOk()->assertSee('1,200.00');
        $this->actingAs($actor)->get(route('dashboard'))->assertOk()->assertSee('Recent customer orders')->assertSee('EARLY')->assertSee('1,200');
        $this->actingAs($actor)->get(route('production.index'))->assertOk()->assertSee('EARLY')->assertSee('cases');
        $this->actingAs($actor)->get(route('inventory.index'))->assertOk()->assertSee('AVAILABLE SALEABLE STOCK')->assertSee('Customer dispatch');
        $this->actingAs($actor)->get(route('distribution.index'))->assertOk()->assertSee('Nairobi')->assertSee('Hotel');
        $this->actingAs($actor)->get(route('sales.index'))->assertOk()->assertSee('Hotel')->assertSee('1,200.00');
        $this->actingAs($actor)->get(route('analytics.index'))->assertOk()->assertSee('Customer order demand')->assertSee('12');
        foreach (['sales', 'production', 'inventory', 'distribution', 'customers', 'costs', 'expiry', 'disposals'] as $reportType) {
            $this->actingAs($actor)->get(route('reports.index', ['type' => $reportType, 'from' => today()->subDays(2)->toDateString(), 'to' => today()->addDays(30)->toDateString()]))->assertOk();
        }
        $this->actingAs($actor)->post('/production', ['quantity' => 100])->assertStatus(405);
        $this->actingAs($actor)->post('/distribution', ['quantity' => 100])->assertStatus(405);
        $this->actingAs($actor)->post('/sales', ['quantity' => 100])->assertStatus(405);
        $this->actingAs($actor)->post('/inventory/adjust', ['quantity' => 100])->assertNotFound();
        $line = $order->dispatch()->firstOrFail()->lines()->firstOrFail();
        $return = $service->recordReturn($invoice, $line, 1, 'Damaged bottles', $actor->user_id);
        $this->assertEquals(100, $return->credit_amount);
        $this->assertSame('destroyed_on_receipt', $return->disposition);
        $this->assertSame(0, $early->fresh()->cases_on_hand);
        $this->actingAs($actor)->get(route('packaged.reports.index'))->assertOk()->assertSee('1,100.00');
        $service->recallBatch($early, $actor->user_id, 'Failed post-sale quality review');
        $this->assertSame('recalled', $early->fresh()->status);
        $this->actingAs($actor)->get(route('packaged.batches.show', $early))->assertOk()->assertSee('Hotel');
    }

    public function test_packaged_pages_render_for_manager(): void
    {
        $manager = $this->actor();
        $this->actingAs($manager)->get(route('packaged.batches.index'))->assertOk();
        $this->actingAs($manager)->get(route('packaged.batches.create'))->assertOk();
        $this->actingAs($manager)->get(route('packaged.orders.index'))->assertOk();
        $this->actingAs($manager)->get(route('packaged.orders.create'))->assertOk();
        $this->actingAs($manager)->get(route('packaged.reports.index'))->assertOk();
        $this->actingAs($manager)->get(route('packaged.forecasts.index'))->assertOk();
    }

    public function test_quality_rejects_are_removed_and_cost_is_allocated_to_saleable_cases(): void
    {
        $actor = $this->actor();
        $product = Product::where('sku', 'WATER-1L')->firstOrFail();
        $service = app(PackagedWaterService::class);
        $batch = $this->batch($service, $actor, $product, 'QC-REJECT', 10, 30);
        $service->releaseBatch($batch, $actor->user_id, 'Two bottles failed inspection', 2);
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $this->assertSame(2, $batch->fresh()->quality_rejected_cases);
        $this->assertEquals(62.5, $batch->fresh()->cost_per_case);
        $this->assertDatabaseHas('stock_movements', ['production_batch_id' => $batch->id, 'reason' => 'quality_rejection', 'signed_cases' => -2]);
    }

    public function test_insufficient_saleable_stock_does_not_leave_partial_reservations(): void
    {
        $actor = $this->actor();
        $customer = Customer::create(['name' => 'School', 'phone_number' => '0711111111']);
        $product = Product::where('sku', 'WATER-5L')->firstOrFail();
        $service = app(PackagedWaterService::class);
        $batch = $this->batch($service, $actor, $product, 'SHORT-STOCK', 5, 30);
        $service->releaseBatch($batch, $actor->user_id, 'Passed quality test');
        $order = $service->createOrder(['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'discount' => 0, 'delivery_cost' => 0, 'items' => [['product_id' => $product->id, 'cases_ordered' => 6, 'price_per_case' => 100]]], $actor->user_id);
        try {
            $service->confirmOrder($order, $actor->user_id);
            $this->fail('The order should not reserve more cases than are available.');
        } catch (ValidationException) {
            $this->assertSame('draft', $order->fresh()->status);
            $this->assertSame(0, $batch->fresh()->cases_reserved);
            $this->assertDatabaseCount('batch_reservations', 0);
        }
    }

    public function test_approved_plan_is_deducted_and_completed_by_linked_batches(): void
    {
        $actor = $this->actor();
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $forecast = ProductForecast::create([
            'product_id' => $product->id,
            'origin_date' => today(),
            'horizon_days' => 7,
            'method' => 'random_forest',
            'predicted_cases' => 50,
            'mae_cases' => 2,
            'bias_cases' => 0,
            'evaluation' => ['selected' => true, 'mode' => 'current'],
        ]);
        $forecastService = app(ProductForecastService::class);
        $this->assertSame(50, $forecastService->recommendation($forecast)['recommended_cases']);
        $this->actingAs($actor)->post(route('packaged.forecasts.decision', $forecast), [
            'approved_cases' => 40,
            'planned_for' => today()->addDays(3)->toDateString(),
            'reason' => 'Limited bottle supply',
        ])->assertRedirect();
        $plan = ProductionForecastDecision::firstOrFail();
        $this->assertSame(40, $forecastService->recommendation($forecast)['planned_cases']);
        $this->assertSame(10, $forecastService->recommendation($forecast)['recommended_cases']);

        $service = app(PackagedWaterService::class);
        foreach ([15, 25] as $index => $cases) {
            $batch = $service->createBatch([
                'product_id' => $product->id,
                'production_forecast_decision_id' => $plan->id,
                'batch_code' => 'PLAN-'.$index,
                'produced_on' => today()->toDateString(),
                'expires_on' => today()->addDays(30)->toDateString(),
                'cases_produced' => $cases,
            ], $actor->user_id);
            $service->releaseBatch($batch, $actor->user_id, 'Passed quality checks');
        }
        $this->assertSame('completed', $plan->fresh()->plan_status);
        $this->assertSame(40, $plan->fresh()->cases_completed);
        $this->assertSame(0, $forecastService->recommendation($forecast)['planned_cases']);
        $this->assertSame(10, $forecastService->recommendation($forecast)['recommended_cases']);
    }

    public function test_historical_forecast_cannot_be_approved_as_a_current_plan(): void
    {
        $actor = $this->actor();
        $product = Product::where('sku', 'WATER-1L')->firstOrFail();
        $forecast = ProductForecast::create([
            'product_id' => $product->id,
            'origin_date' => today()->subDays(21),
            'horizon_days' => 7,
            'method' => 'random_forest',
            'predicted_cases' => 120,
            'mae_cases' => 8,
            'bias_cases' => 1,
            'evaluation' => ['selected' => true, 'mode' => 'historical_demo'],
        ]);

        $this->actingAs($actor)->get(route('packaged.forecasts.index'))->assertOk()
            ->assertSee('Historical demonstration')->assertSee('No present-day production quantity is recommended');
        $this->actingAs($actor)->post(route('packaged.forecasts.decision', $forecast), [
            'approved_cases' => 120,
            'planned_for' => today()->addDay()->toDateString(),
        ])->assertStatus(422);
        $this->assertDatabaseCount('production_forecast_decisions', 0);
    }
}
