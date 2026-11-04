<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Services\AppAlertService;
use App\Services\PackagedWaterService;
use App\Services\ProductForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OperationalGapFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function batch(User $manager, int $cases = 10): ProductionBatch
    {
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();

        return ProductionBatch::create([
            'product_id' => $product->id,
            'batch_code' => 'COUNT-'.uniqid(),
            'produced_on' => today()->subDay(),
            'expires_on' => today()->addDays(20),
            'cases_produced' => $cases,
            'cases_on_hand' => $cases,
            'cases_reserved' => 0,
            'status' => 'released',
            'total_cost' => 100,
            'cost_per_case' => 10,
            'produced_by' => $manager->user_id,
        ]);
    }

    public function test_shortage_alert_uses_the_active_weekly_origin(): void
    {
        Mail::fake();
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $origin = today()->subDays(today()->dayOfWeek === 0 ? 7 : today()->dayOfWeek);
        ProductForecast::create(['product_id' => $product->id, 'origin_date' => $origin, 'horizon_days' => 7, 'method' => 'random_forest', 'predicted_cases' => 50, 'mae_cases' => 5, 'bias_cases' => 0, 'evaluation' => ['selected' => true, 'mode' => 'current']]);

        app(AppAlertService::class)->scanIfDue(true);

        $this->assertDatabaseHas('app_alerts', ['user_id' => $manager->user_id, 'alert_key' => 'product:shortage:'.$product->id, 'category' => 'shortage']);
    }

    public function test_expiry_day_stock_is_not_shown_as_available(): void
    {
        $manager = $this->user('manager');
        $batch = $this->batch($manager);
        $batch->update(['expires_on' => today()]);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('0 cases');
        $this->actingAs($manager)->get(route('inventory.index'))->assertOk()->assertSee('0 cases');
    }

    public function test_sales_staff_cannot_change_a_listed_price_or_discount(): void
    {
        $staff = $this->user('sales_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $payload = ['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'requested_delivery_on' => today()->addDay()->toDateString(), 'delivery_cost' => 0, 'items' => [['product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => (float) $product->default_price_per_case + 1]]];

        $this->actingAs($staff)->post(route('packaged.orders.store'), $payload)->assertSessionHasErrors('price_override_reason');
        $this->assertDatabaseCount('sales_orders', 0);
        $manager = $this->user('manager');
        $this->actingAs($manager)->post(route('packaged.orders.store'), $payload + ['price_override_reason' => 'Approved customer contract'])->assertRedirect();
        $this->assertDatabaseHas('sales_orders', ['price_override_reason' => 'Approved customer contract']);
        $this->assertSame(today()->addDay()->toDateString(), SalesOrder::firstOrFail()->requested_delivery_on->toDateString());
    }

    public function test_stock_count_needs_manager_approval_and_preserves_a_movement(): void
    {
        $manager = $this->user('manager');
        $inventory = $this->user('inventory_staff');
        $batch = $this->batch($manager);

        $this->actingAs($inventory)->post(route('packaged.batches.stock-counts.store', $batch), ['counted_cases' => 8, 'reason' => 'Shelf count'])->assertRedirect();
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $count = $batch->stockCounts()->firstOrFail();
        $this->actingAs($inventory)->post(route('packaged.stock-counts.decision', $count), ['decision' => 'approve', 'reason' => 'Verified'])->assertForbidden();
        $this->actingAs($manager)->post(route('packaged.stock-counts.decision', $count), ['decision' => 'approve', 'reason' => 'Verified'])->assertRedirect();
        $this->assertSame(8, $batch->fresh()->cases_on_hand);
        $this->assertDatabaseHas('stock_movements', ['production_batch_id' => $batch->id, 'signed_cases' => -2, 'reason' => 'stocktake_adjustment']);
    }

    public function test_admin_email_change_removes_old_verification(): void
    {
        $admin = $this->user('administrator');
        $staff = $this->user('sales_staff');
        $staff->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($admin)->put(route('users.update', $staff), ['name' => $staff->name, 'email' => 'new@example.test', 'phone_number' => '0712345678', 'role' => $staff->role, 'is_active' => 1])->assertRedirect();

        $this->assertNull($staff->fresh()->email_verified_at);
    }

    public function test_unreviewed_zero_order_days_stop_a_current_forecast(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        foreach ([today()->subDays(70), today()] as $day) {
            $order = SalesOrder::create(['order_number' => 'TEST-'.uniqid(), 'customer_id' => $customer->customer_id, 'ordered_on' => $day, 'requested_delivery_on' => $day, 'status' => 'confirmed', 'delivery_address' => 'Nairobi', 'total_amount' => 100, 'created_by' => $manager->user_id]);
            SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'cases_ordered' => 1, 'price_per_case' => 100, 'line_total' => 100]);
        }

        $result = app(ProductForecastService::class)->run($product);

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('not closed', $result['reason']);
    }

    public function test_paid_return_creates_separate_customer_credit_then_a_traceable_refund(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $batch = $this->batch($manager);
        $service = app(PackagedWaterService::class);
        $order = $service->createOrder(['customer_id' => $customer->customer_id, 'delivery_address' => 'Nairobi', 'delivery_cost' => 0, 'items' => [['product_id' => $batch->product_id, 'cases_ordered' => 2, 'price_per_case' => 100]]], $manager->user_id);
        $service->confirmOrder($order, $manager->user_id);
        $dispatch = $service->dispatchOrder($order, $manager->user_id, 'TRIP-REFUND');
        $service->deliverOrder($order, $manager->user_id, ['received_by' => 'Hotel buyer', 'receipt_reference' => 'SIGNED-1']);
        $invoice = $service->invoiceOrder($order, $manager->user_id);
        $service->recordPayment($invoice, ['amount' => 200, 'method' => 'M-Pesa', 'reference' => 'MP-1'], $manager->user_id);
        $service->recordReturn($invoice, $dispatch->lines()->firstOrFail(), 1, 'Damaged in delivery', $manager->user_id);

        $this->actingAs($manager)->get(route('packaged.reports.index'))->assertOk()->assertSee('Customer credits awaiting refund: KSh 100.00');
        $service->recordRefund($invoice, ['amount' => 100, 'method' => 'M-Pesa', 'reference' => 'MP-REF-1', 'reason' => 'Return credit'], $manager->user_id);

        $this->assertSame('settled', $invoice->fresh()->status);
        $this->actingAs($manager)->get(route('packaged.reports.index'))->assertOk()->assertSee('Customer credits awaiting refund: KSh 0.00');
        $this->assertDatabaseHas('customer_refunds', ['invoice_id' => $invoice->id, 'reference' => 'MP-REF-1']);
    }

    public function test_quality_release_requires_measured_results_and_evidence(): void
    {
        $manager = $this->user('manager');
        $batch = $this->batch($manager);
        $batch->update(['status' => 'quality_hold']);

        $this->actingAs($manager)->post(route('packaged.batches.release', $batch), ['quality_notes' => 'Looks good', 'rejected_cases' => 0])->assertSessionHasErrors(['quality_tested_on', 'quality_evidence_reference', 'quality_ph', 'quality_turbidity_ntu', 'microbiological_result']);
        $this->actingAs($manager)->post(route('packaged.batches.release', $batch), ['quality_notes' => 'Checked against approved procedure', 'rejected_cases' => 0, 'quality_tested_on' => today()->toDateString(), 'quality_evidence_reference' => 'LAB-001', 'quality_ph' => '7.10', 'quality_turbidity_ntu' => '0.300', 'microbiological_result' => 'pass'])->assertRedirect();

        $this->assertDatabaseHas('production_batches', ['id' => $batch->id, 'quality_evidence_reference' => 'LAB-001', 'status' => 'released']);
    }

    public function test_actual_cost_closeout_preserves_the_original_estimate(): void
    {
        $manager = $this->user('manager');
        $batch = $this->batch($manager);

        $this->actingAs($manager)->post(route('packaged.batches.actual-cost', $batch), [
            'actual_treatment' => '20.00', 'actual_bottles' => '40.00', 'actual_inbound' => '5.00',
            'actual_caps_labels' => '10.00', 'actual_labour' => '20.00', 'actual_electricity' => '20.00',
            'actual_other' => '10.00', 'evidence_reference' => 'COST-SHEET-1',
        ])->assertRedirect();

        $this->assertSame(100.0, (float) $batch->fresh()->total_cost);
        $this->assertSame(125.0, (float) $batch->actualCost()->firstOrFail()->actual_total);
        $this->actingAs($manager)->get(route('packaged.reports.index'))->assertOk()->assertSee('25.00');
    }
}
