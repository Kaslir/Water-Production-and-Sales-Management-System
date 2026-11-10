<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryTrip;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\PackagedFinancialSummary;
use App\Services\PackagedWaterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StabilisationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'manager'): User
    {
        return User::create(['name' => ucfirst($role), 'email' => uniqid().$role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function stock(User $actor, string $code, int $cases, int $expiryDays = 30): ProductionBatch
    {
        return ProductionBatch::create(['product_id' => Product::where('sku', 'WATER-500ML')->value('id'), 'batch_code' => $code,
            'produced_on' => today()->subDay(), 'expires_on' => today()->addDays($expiryDays), 'cases_produced' => $cases,
            'cases_on_hand' => $cases, 'status' => 'released', 'total_cost' => $cases * 10, 'cost_per_case' => 10, 'produced_by' => $actor->user_id]);
    }

    private function order(User $actor, int $cases = 2): SalesOrder
    {
        $customer = Customer::create(['name' => 'Hotel '.uniqid(), 'phone_number' => sprintf('0712%06d', Customer::count())]);
        $service = app(PackagedWaterService::class);
        $order = $service->createOrder(['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'items' => [[
            'product_id' => Product::where('sku', 'WATER-500ML')->value('id'), 'cases_ordered' => $cases, 'price_per_case' => 100,
        ]]], $actor->user_id);
        $service->confirmOrder($order, $actor->user_id);

        return $order;
    }

    private function dispatch(SalesOrder $order, User $actor): DeliveryTrip
    {
        $trip = DeliveryTrip::create(['delivery_on' => today(), 'status' => 'dispatched', 'active_dispatch_key' => 1, 'created_by' => $actor->user_id]);
        $trip->orders()->attach($order->id);
        app(PackagedWaterService::class)->dispatchOrder($order, $actor->user_id, 'TEST-'.$order->id);

        return $trip;
    }

    public function test_payment_rejects_extra_decimal_places_and_preserves_partial_balance(): void
    {
        $actor = $this->actor();
        $this->stock($actor, 'PAY-STOCK', 10);
        $order = $this->order($actor);
        $this->dispatch($order, $actor);
        $service = app(PackagedWaterService::class);
        $service->deliverOrder($order, $actor->user_id);
        $invoice = $service->invoiceOrder($order, $actor->user_id);
        $this->actingAs($actor)->post(route('packaged.invoices.payment', $invoice), ['amount' => '1.001', 'method' => 'Cash'])->assertSessionHasErrors('amount');
        try {
            $service->recordPayment($invoice, ['amount' => '1.001', 'method' => 'Cash'], $actor->user_id);
            $this->fail('The service must reject sub-cent payments as well.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
        }
        $service->recordPayment($invoice, ['amount' => '99.99', 'method' => 'Cash'], $actor->user_id);
        $this->assertSame('part_paid', $invoice->fresh()->status);
        $this->actingAs($actor)->post(route('packaged.invoices.payment', $invoice), ['amount' => '100.02', 'method' => 'Cash'])->assertSessionHasErrors('workflow');
        $service->recordPayment($invoice, ['amount' => '100.01', 'method' => 'Cash'], $actor->user_id);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_failed_delivery_return_and_inspection_restore_stock_once(): void
    {
        $actor = $this->actor();
        $batch = $this->stock($actor, 'RETURN-STOCK', 10);
        $order = $this->order($actor);
        $trip = $this->dispatch($order, $actor);
        $service = app(PackagedWaterService::class);
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $service->failDelivery($order, 'Customer closed', $actor->user_id);
        $this->actingAs($actor)->get(route('packaged.orders.show', $order))->assertOk()->assertSee('Failed delivery resolution')->assertSee('Confirm all cases returned on quality hold');
        $this->assertSame('dispatched', $trip->fresh()->status);
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $service->receiveFailedDelivery($order, 'WH-001', $actor->user_id);
        $this->assertSame('completed', $trip->fresh()->status);
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $lineId = $order->fresh()->dispatch->lines()->firstOrFail()->id;
        $service->inspectFailedDeliveryLine($lineId, true, 'Seals intact', $actor->user_id);
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $this->assertDatabaseHas('failed_delivery_lines', ['dispatch_line_id' => $lineId, 'status' => 'released']);
        $this->assertDatabaseCount('invoices', 0);
        $this->expectException(ValidationException::class);
        $service->inspectFailedDeliveryLine($lineId, true, 'Duplicate', $actor->user_id);
    }

    public function test_failed_delivery_disposal_is_a_loss_without_restoring_stock(): void
    {
        $actor = $this->actor();
        $batch = $this->stock($actor, 'LOSS-STOCK', 10);
        $order = $this->order($actor);
        $this->dispatch($order, $actor);
        $service = app(PackagedWaterService::class);
        $service->failDelivery($order, 'Broken bottles', $actor->user_id);
        $service->receiveFailedDelivery($order, 'WH-002', $actor->user_id);
        $service->inspectFailedDeliveryLine($order->fresh()->dispatch->lines()->firstOrFail()->id, false, 'Seals broken', $actor->user_id);
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $summary = app(PackagedFinancialSummary::class)->forPeriod(today()->toDateString(), today()->toDateString());
        $this->assertSame(20.0, $summary['disposalLoss']);
        $this->actingAs($actor)->get(route('reports.index', ['type' => 'disposals', 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()->assertSee('LOSS-STOCK')->assertSee('Seals broken');
    }

    public function test_shared_trip_closes_only_after_delivered_and_failed_stops_are_accounted_for(): void
    {
        $actor = $this->actor();
        $this->stock($actor, 'SHARED-STOCK', 10);
        $first = $this->order($actor);
        $second = $this->order($actor);
        $trip = DeliveryTrip::create(['delivery_on' => today(), 'status' => 'dispatched', 'active_dispatch_key' => 1, 'created_by' => $actor->user_id]);
        $trip->orders()->attach([$first->id, $second->id]);
        $service = app(PackagedWaterService::class);
        $service->dispatchOrder($first, $actor->user_id, 'SHARED-1');
        $service->dispatchOrder($second, $actor->user_id, 'SHARED-2');
        $service->failDelivery($first, 'Customer closed', $actor->user_id);
        $service->deliverOrder($second, $actor->user_id);
        $this->assertSame('dispatched', $trip->fresh()->status);
        $service->receiveFailedDelivery($first, 'WH-003', $actor->user_id);
        $this->assertSame('completed', $trip->fresh()->status);
        $this->assertNull($trip->fresh()->active_dispatch_key);
    }

    public function test_invalid_reservation_is_reallocated_fefo_before_dispatch(): void
    {
        $actor = $this->actor();
        $early = $this->stock($actor, 'EARLY-STOCK', 2, 5);
        $late = $this->stock($actor, 'LATE-STOCK', 2, 30);
        $order = $this->order($actor);
        $early->update(['expires_on' => today()->subDay()]);
        $this->dispatch($order, $actor);
        $this->assertSame(2, $early->fresh()->cases_on_hand);
        $this->assertSame(0, $late->fresh()->cases_on_hand);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.reservation_reallocated']);
    }

    public function test_insufficient_replacement_stock_blocks_whole_trip_without_deducting_stock(): void
    {
        $actor = $this->actor();
        $batch = $this->stock($actor, 'ONLY-STOCK', 2, 5);
        $order = $this->order($actor);
        $batch->update(['expires_on' => today()->subDay()]);
        $trip = DeliveryTrip::create(['delivery_on' => today(), 'status' => 'planned', 'created_by' => $actor->user_id]);
        $trip->orders()->attach($order->id);
        $this->actingAs($actor)->post(route('packaged.trips.dispatch', $trip))->assertSessionHasErrors('workflow');
        $this->assertSame('planned', $trip->fresh()->status);
        $this->assertSame(2, $batch->fresh()->cases_on_hand);
        $this->assertDatabaseCount('dispatches', 0);
    }

    public function test_recall_reallocates_when_stock_exists_and_flags_order_when_it_does_not(): void
    {
        $actor = $this->actor();
        $recalled = $this->stock($actor, 'RECALLED-STOCK', 2, 5);
        $replacement = $this->stock($actor, 'REPLACEMENT-STOCK', 2, 30);
        $order = $this->order($actor);
        app(PackagedWaterService::class)->recallBatch($recalled, $actor->user_id, 'Possible seal failure');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(0, $recalled->fresh()->cases_reserved);
        $this->assertSame(2, $replacement->fresh()->cases_reserved);
        app(PackagedWaterService::class)->recallBatch($replacement, $actor->user_id, 'Second seal failure');
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseHas('app_alerts', ['alert_key' => 'order:reallocation:'.$order->id]);
    }

    public function test_quarantine_immediately_blocks_reserved_stock_and_reallocates_the_order(): void
    {
        $actor = $this->actor();
        $suspect = $this->stock($actor, 'SUSPECT-STOCK', 2, 5);
        $replacement = $this->stock($actor, 'SAFE-STOCK', 2, 30);
        $order = $this->order($actor);
        app(PackagedWaterService::class)->quarantineBatch($suspect, $actor->user_id, 'Failed seal inspection');
        $this->assertSame('quarantined', $suspect->fresh()->status);
        $this->assertSame(0, $suspect->fresh()->cases_reserved);
        $this->assertSame(2, $replacement->fresh()->cases_reserved);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_approved_forecast_values_survive_new_runs_and_repeat_approval_is_blocked(): void
    {
        $actor = $this->actor();
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $forecast = ProductForecast::create(['product_id' => $product->id, 'origin_date' => today()->startOfWeek()->subDay(),
            'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 50, 'mae_cases' => 2, 'bias_cases' => -1,
            'evaluation' => ['selected' => true, 'mode' => 'current']]);
        $payload = ['approved_cases' => 52, 'planned_for' => today()->toDateString()];
        $this->actingAs($actor)->post(route('packaged.forecasts.decision', $forecast), $payload)->assertRedirect();
        $decision = ProductionForecastDecision::firstOrFail();
        ProductForecast::create(['product_id' => $product->id, 'origin_date' => $forecast->origin_date,
            'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 80, 'mae_cases' => 5, 'bias_cases' => 3,
            'evaluation' => ['selected' => true, 'mode' => 'current']]);
        $this->actingAs($actor)->post(route('packaged.forecasts.decision', $forecast), $payload)->assertSessionHasErrors('forecast');
        $this->assertEquals(50, $decision->fresh()->forecast_predicted_cases);
        $this->assertEquals(2, $decision->fresh()->forecast_mae_cases);
        $this->assertDatabaseCount('production_forecast_decisions', 1);
    }

    public function test_staff_cannot_approve_a_production_plan(): void
    {
        $staff = $this->actor('production_staff');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $forecast = ProductForecast::create(['product_id' => $product->id, 'origin_date' => today()->startOfWeek()->subDay(),
            'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 50, 'mae_cases' => 2, 'bias_cases' => -1,
            'evaluation' => ['selected' => true, 'mode' => 'current']]);
        $this->actingAs($staff)->post(route('packaged.forecasts.decision', $forecast), ['approved_cases' => 52, 'planned_for' => today()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('production_forecast_decisions', 0);
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        $admin = $this->actor('administrator');
        $this->actingAs($admin)->put(route('users.update', $admin), ['name' => $admin->name, 'email' => $admin->email,
            'phone_number' => '0712345678', 'role' => 'manager', 'is_active' => 1])->assertStatus(422);
        $this->assertSame('administrator', $admin->fresh()->role);
    }

    public function test_two_administrators_can_deactivate_one_but_not_the_last_and_inactive_session_is_rejected(): void
    {
        $first = $this->actor('administrator');
        $second = $this->actor('administrator');
        $this->actingAs($first)->delete(route('users.destroy', $second))->assertRedirect();
        $this->assertFalse((bool) $second->fresh()->is_active);
        $this->actingAs($second)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($first)->put(route('users.update', $first), ['name' => $first->name, 'email' => $first->email,
            'phone_number' => '0712345678', 'role' => 'manager', 'is_active' => 1])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'subject_id' => $second->user_id]);
    }

    public function test_business_date_uses_nairobi_calendar_at_utc_midnight_boundary(): void
    {
        Carbon::setTestNow('2026-11-05 22:30:00 UTC');
        try {
            $actor = $this->actor();
            $this->stock($actor, 'NAIROBI-STOCK', 5);
            $order = $this->order($actor);
            $this->assertSame('2026-11-06', $order->ordered_on->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_product_report_labels_invoiced_cases_separately_from_dispatches(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->get(route('packaged.reports.index'))->assertOk()
            ->assertSee('Net invoiced cases after credited returns')
            ->assertSee('Net bottled-water revenue');
    }
}
