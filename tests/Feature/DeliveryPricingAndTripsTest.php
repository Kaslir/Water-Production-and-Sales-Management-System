<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryTrip;
use App\Models\DeliveryZone;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryPricingAndTripsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function area(string $name): DeliveryArea
    {
        return DeliveryArea::query()->with('zone')->where('name', $name)->firstOrFail();
    }

    private function order(User $actor, Customer $customer, DeliveryArea $area, int $cases = 10): SalesOrder
    {
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $product->update(['default_price_per_case' => 620]);
        $this->actingAs($actor)->post(route('packaged.orders.store'), [
            'customer_id' => $customer->customer_id,
            'delivery_area_id' => $area->id,
            'delivery_address' => 'Warehouse, '.$area->name,
            'requested_delivery_on' => today()->toDateString(),
            'delivery_fee' => 1,
            'delivery_cost' => 9999,
            'items' => [['product_id' => $product->id, 'cases_ordered' => $cases, 'price_per_case' => 620]],
        ])->assertRedirect();

        return SalesOrder::latest('id')->firstOrFail();
    }

    public function test_five_zones_assign_fees_and_existing_customers_are_not_given_an_arbitrary_area(): void
    {
        $this->assertSame(5, DeliveryZone::count());
        $this->assertSame(1200.0, (float) $this->area('Westlands')->zone->fixed_fee);
        $this->assertSame('C', $this->area('Westlands')->zone->code);
        $this->assertSame('Nairobi', $this->area('Westlands')->county);
        $customer = Customer::create(['name' => 'Existing hotel', 'phone_number' => '0712345000']);
        $this->assertNull($customer->fresh()->delivery_area_id);
    }

    public function test_order_fee_is_calculated_by_the_server_once_and_kept_after_a_zone_change(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'ABC Supermarket', 'phone_number' => '0712345001']);
        $area = $this->area('Westlands');
        $order = $this->order($manager, $customer, $area);
        $this->assertSame(1200.0, (float) $order->delivery_fee);
        $this->assertSame(7400.0, (float) $order->total_amount);
        $this->assertSame(0.0, (float) $order->delivery_cost);

        $this->actingAs($manager)->post(route('packaged.delivery-zones.update', $area->zone), ['fixed_fee' => '1500.00', 'is_active' => 1, 'reason' => 'Approved route fee review'])->assertRedirect();
        $this->assertSame(1200.0, (float) $order->fresh()->delivery_fee);
        $this->assertSame(1500.0, (float) $area->zone->fresh()->fixed_fee);
        $this->assertDatabaseHas('delivery_zone_fee_changes', ['delivery_zone_id' => $area->zone->id, 'previous_fee' => 1200, 'new_fee' => 1500, 'changed_by' => $manager->user_id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_zone.updated', 'subject_id' => $area->zone->id]);
        $this->actingAs($manager)->post(route('packaged.delivery-zones.update', $area->zone), ['fixed_fee' => '1500.00', 'is_active' => 0, 'reason' => 'Temporary closure'])->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.orders.store'), [
            'customer_id' => $customer->customer_id, 'delivery_area_id' => $area->id, 'delivery_address' => 'Another site',
            'requested_delivery_on' => today()->toDateString(), 'items' => [['product_id' => Product::where('sku', 'WATER-500ML')->value('id'), 'cases_ordered' => 1, 'price_per_case' => 620]],
        ])->assertSessionHasErrors('delivery_area_id');
    }

    public function test_shared_trip_records_one_expense_for_two_orders_without_deducting_stock_early(): void
    {
        $manager = $this->user('manager');
        $distribution = $this->user('distribution_staff');
        $customerA = Customer::create(['name' => 'Hotel A', 'phone_number' => '0712345002']);
        $customerB = Customer::create(['name' => 'Hotel B', 'phone_number' => '0712345003']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $batch = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'TRIP-TEST-1', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(30), 'cases_produced' => 30, 'cases_on_hand' => 30, 'status' => 'released', 'total_cost' => 3000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $orderA = $this->order($manager, $customerA, $this->area('Westlands'));
        $orderB = $this->order($manager, $customerB, $this->area('Industrial Area'));
        foreach ([$orderA, $orderB] as $order) {
            $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        }
        $this->actingAs($distribution)->get(route('distribution.index'))->assertOk()
            ->assertSee('2 orders')->assertSee($orderA->order_number)->assertSee($orderB->order_number)
            ->assertSee('Westlands')->assertSee('Industrial Area')->assertSee('Assign to Trip');
        $this->actingAs($distribution)->get(route('distribution.index', [
            'delivery_zone_id' => $this->area('Westlands')->delivery_zone_id,
            'customer' => 'Hotel A',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))->assertOk()->assertSee($orderA->order_number)->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $orderA->id);
        $this->actingAs($distribution)->get(route('packaged.trips.create', ['order_id' => $orderA->id]))->assertOk()
            ->assertSee('value="'.$orderA->id.'" checked', false);
        $this->actingAs($distribution)->post(route('packaged.orders.dispatch', $orderA), ['delivery_reference' => 'TOO-EARLY'])->assertSessionHasErrors('workflow');
        $this->actingAs($distribution)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id, $orderB->id]])->assertRedirect();
        $trip = DeliveryTrip::firstOrFail();
        $this->assertSame('DT-000001', $trip->trip_reference);
        $this->assertNull($trip->truck_identifier);
        $this->assertSame(30, $batch->fresh()->cases_on_hand);
        $this->assertSame(2, $trip->orders()->count());
        $this->actingAs($distribution)->get(route('distribution.index', ['tab' => 'trips']))->assertOk()
            ->assertSee('1 trips')->assertSee($trip->trip_reference)->assertSee('Planned');
        $this->actingAs($distribution)->post(route('packaged.trips.expense', $trip), ['fuel_expense' => '2800.00', 'other_expense' => '400.00', 'expense_reference' => 'FUEL-001', 'reason' => 'Receipts checked'])->assertRedirect();
        $this->assertSame(3200.0, $trip->fresh()->totalExpense());
        $this->assertDatabaseCount('operating_expenses', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_trip.expense_updated', 'subject_id' => $trip->id]);
        $this->actingAs($distribution)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id]])->assertSessionHasErrors('order_ids');

        $this->actingAs($distribution)->post(route('packaged.orders.dispatch', $orderA), ['delivery_reference' => 'TOO-EARLY'])->assertSessionHasErrors('workflow');
        $this->actingAs($distribution)->post(route('packaged.trips.dispatch', $trip))->assertRedirect();
        $this->assertSame('dispatched', $trip->fresh()->status);
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $this->actingAs($distribution)->post(route('packaged.trips.dispatch', $trip))->assertSessionHasErrors('trip');
        foreach ([$orderA, $orderB] as $index => $order) {
            $this->actingAs($distribution)->post(route('packaged.orders.dispatch', $order), ['delivery_reference' => 'PARTIAL-'.$index, 'cases' => 1])->assertSessionHasErrors('cases');
            $this->actingAs($distribution)->post(route('packaged.orders.deliver', $order), ['received_by' => 'Customer', 'receipt_reference' => 'SIGNED-'.$index])->assertRedirect();
            $this->actingAs($manager)->post(route('packaged.orders.invoice', $order))->assertRedirect();
        }
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $this->assertSame('completed', $trip->fresh()->status);
        $this->actingAs($distribution)->get(route('distribution.index'))->assertOk()
            ->assertSee('0 orders')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->actingAs($distribution)->get(route('distribution.index', ['tab' => 'history', 'customer' => 'Hotel A', 'status' => 'delivered']))->assertOk()
            ->assertSee($orderA->order_number)->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->order->id === $orderA->id);
        $this->assertSame(7400.0, (float) $orderA->fresh()->invoice->amount);
        $this->assertSame(7000.0, (float) $orderB->fresh()->invoice->amount);
        $this->actingAs($manager)->get(route('packaged.reports.index', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertOk()
            ->assertViewHas('deliveryFeeRevenue', 2000.0)->assertViewHas('tripExpenses', 3200.0)->assertViewHas('operatingProfit', 9200.0);
        $this->actingAs($manager)->post(route('packaged.invoices.return', $orderA->fresh()->invoice), ['dispatch_line_id' => $orderA->fresh()->dispatch->lines()->firstOrFail()->id, 'cases' => 1, 'reason' => 'Damaged bottle'])->assertRedirect();
        $this->assertDatabaseCount('delivery_fee_credits', 0);
        $this->actingAs($manager)->get(route('packaged.reports.index', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertOk()
            ->assertViewHas('deliveryFeeRevenue', 2000.0)->assertViewHas('bottledRevenue', 11780.0);
    }

    public function test_delivery_fee_credit_is_once_only_and_becomes_a_refundable_customer_credit(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Hospital', 'phone_number' => '0712345004']);
        $order = $this->order($manager, $customer, $this->area('Westlands'));
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'FEE-CREDIT-1', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(30), 'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 1000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$order->id]])->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.trips.dispatch', DeliveryTrip::firstOrFail()))->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.orders.deliver', $order), ['received_by' => 'Hospital', 'receipt_reference' => 'SIGNED-FEE'])->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.orders.invoice', $order))->assertRedirect();
        $invoice = $order->fresh()->invoice;
        $this->actingAs($manager)->post(route('packaged.invoices.payment', $invoice), ['amount' => 1000, 'method' => 'Bank transfer', 'reference' => 'BANK-1'])->assertRedirect();
        $this->assertSame('part_paid', $invoice->fresh()->status);
        $this->actingAs($manager)->post(route('packaged.invoices.payment', $invoice), ['amount' => 6400, 'method' => 'Bank transfer', 'reference' => 'BANK-2'])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->actingAs($manager)->post(route('packaged.invoices.delivery-fee-credit', $invoice), ['reason' => 'Delivery was disrupted'])->assertRedirect();
        $this->assertDatabaseHas('delivery_fee_credits', ['invoice_id' => $invoice->id, 'amount' => 1200]);
        $this->assertSame('credit_due', $invoice->fresh()->status);
        $this->actingAs($manager)->post(route('packaged.invoices.delivery-fee-credit', $invoice), ['reason' => 'Duplicate'])->assertSessionHasErrors('workflow');
        $this->actingAs($manager)->post(route('packaged.invoices.refund', $invoice), ['amount' => 1200, 'method' => 'Bank transfer', 'reference' => 'REF-1', 'reason' => 'Delivery fee credit'])->assertRedirect();
        $this->assertSame('settled', $invoice->fresh()->status);
        $this->actingAs($manager)->get(route('packaged.reports.index', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertOk()
            ->assertViewHas('deliveryFeeRevenue', 0.0)->assertViewHas('customerCreditDue', 0.0);
    }

    public function test_cancellation_before_dispatch_removes_the_trip_stop_without_charging_a_fee(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Office', 'phone_number' => '0712345006']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'CANCEL-TRIP-1', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(30), 'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 1000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $order = $this->order($manager, $customer, $this->area('Industrial Area'));
        $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$order->id]])->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.orders.cancel', $order), ['reason' => 'Customer cancelled before dispatch'])->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, $order->deliveryTrips()->count());
        $this->assertSame(0, $order->dispatch()->count());
        $this->assertSame(0, $order->invoice()->count());
        $this->assertSame(0, ProductionBatch::firstOrFail()->cases_reserved);
        $this->assertSame('cancelled', DeliveryTrip::firstOrFail()->status);
        $this->actingAs($manager)->get(route('packaged.trips.show', DeliveryTrip::firstOrFail()))->assertOk()->assertSee('KSh 0.00');
    }

    public function test_customer_registration_requires_a_supported_area_and_discount_does_not_reduce_the_fee(): void
    {
        $manager = $this->user('manager');
        $area = $this->area('Westlands');
        $this->actingAs($manager)->post(route('operations.store', ['type' => 'customers']), ['name' => 'New retailer', 'phone_number' => '0712345007', 'address' => 'Near station', 'delivery_area_id' => $area->id])->assertRedirect();
        $customer = Customer::where('name', 'New retailer')->firstOrFail();
        $this->assertSame('Nairobi', $customer->delivery_county);
        $this->assertSame($area->id, $customer->delivery_area_id);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $product->update(['default_price_per_case' => 620]);
        $this->actingAs($manager)->post(route('packaged.orders.store'), [
            'customer_id' => $customer->customer_id,
            'delivery_area_id' => $area->id,
            'delivery_address' => 'Another Westlands branch',
            'requested_delivery_on' => today()->toDateString(),
            'discount' => 200,
            'price_override_reason' => 'Manager-approved promotion',
            'items' => [['product_id' => $product->id, 'cases_ordered' => 10, 'price_per_case' => 620]],
        ])->assertRedirect();
        $order = SalesOrder::firstOrFail();
        $this->assertSame(7200.0, (float) $order->total_amount);
        $this->assertSame(1200.0, (float) $order->delivery_fee);
        $this->assertSame('Another Westlands branch', $order->delivery_address);
    }

    public function test_one_truck_prevents_a_second_active_trip_until_delivery_is_confirmed(): void
    {
        $manager = $this->user('manager');
        $customerA = Customer::create(['name' => 'Shop A', 'phone_number' => '0712345020']);
        $customerB = Customer::create(['name' => 'Shop B', 'phone_number' => '0712345021']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $batch = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'ONE-TRUCK-1', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(30), 'cases_produced' => 30, 'cases_on_hand' => 30, 'status' => 'released', 'total_cost' => 3000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $orderA = $this->order($manager, $customerA, $this->area('Westlands'));
        $orderB = $this->order($manager, $customerB, $this->area('Industrial Area'));
        foreach ([$orderA, $orderB] as $order) {
            $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        }
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id]])->assertRedirect();
        $tripA = DeliveryTrip::firstOrFail();
        $this->actingAs($manager)->get(route('packaged.trips.edit', $tripA))->assertOk();
        $this->actingAs($manager)->patch(route('packaged.trips.update', $tripA), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id]])->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderB->id]])->assertRedirect();
        $tripB = DeliveryTrip::latest('id')->firstOrFail();
        $this->actingAs($manager)->patch(route('packaged.trips.update', $tripB), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id]])->assertSessionHasErrors('order_ids');

        $this->actingAs($manager)->post(route('packaged.trips.dispatch', $tripA))->assertRedirect();
        $this->assertSame(20, $batch->fresh()->cases_on_hand);
        $this->actingAs($manager)->post(route('packaged.trips.dispatch', $tripB))->assertSessionHasErrors('trip');
        $this->assertSame('planned', $tripB->fresh()->status);
        $this->assertSame(20, $batch->fresh()->cases_on_hand);
        $this->actingAs($manager)->post(route('packaged.orders.deliver', $orderA), ['received_by' => 'Shop A', 'receipt_reference' => 'RECEIPT-A'])->assertRedirect();
        $this->assertSame('completed', $tripA->fresh()->status);
        $this->actingAs($manager)->post(route('packaged.trips.dispatch', $tripB))->assertRedirect();
        $this->assertSame('dispatched', $tripB->fresh()->status);
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $this->actingAs($manager)->post(route('packaged.trips.dispatch', $tripA))->assertSessionHasErrors('trip');
        $this->actingAs($manager)->post(route('packaged.trips.cancel', $tripB), ['reason' => 'Too late'])->assertSessionHasErrors('trip');
    }

    public function test_planned_trip_can_be_cancelled_without_deducting_stock_or_losing_the_order(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Shop C', 'phone_number' => '0712345022']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $batch = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'CANCEL-TRIP-2', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(30), 'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 1000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $order = $this->order($manager, $customer, $this->area('Westlands'));
        $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$order->id]])->assertRedirect();
        $trip = DeliveryTrip::firstOrFail();
        $this->actingAs($manager)->post(route('packaged.trips.cancel', $trip), ['reason' => 'Delivery rescheduled'])->assertRedirect();
        $this->assertSame('cancelled', $trip->fresh()->status);
        $this->assertSame(0, $trip->orders()->count());
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(10, $batch->fresh()->cases_on_hand);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_trip.cancelled', 'subject_id' => $trip->id]);
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$order->id]])->assertRedirect();
    }

    public function test_trip_dispatch_rolls_back_all_orders_if_a_reserved_batch_is_unusable(): void
    {
        $manager = $this->user('manager');
        $customerA = Customer::create(['name' => 'Shop D', 'phone_number' => '0712345023']);
        $customerB = Customer::create(['name' => 'Shop E', 'phone_number' => '0712345024']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $batchA = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'ROLLBACK-A', 'produced_on' => today()->subDays(2), 'expires_on' => today()->addDays(30), 'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 1000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $batchB = ProductionBatch::create(['product_id' => $product->id, 'batch_code' => 'ROLLBACK-B', 'produced_on' => today()->subDay(), 'expires_on' => today()->addDays(40), 'cases_produced' => 10, 'cases_on_hand' => 10, 'status' => 'released', 'total_cost' => 1000, 'cost_per_case' => 100, 'produced_by' => $manager->user_id]);
        $orderA = $this->order($manager, $customerA, $this->area('Westlands'));
        $orderB = $this->order($manager, $customerB, $this->area('Industrial Area'));
        foreach ([$orderA, $orderB] as $order) {
            $this->actingAs($manager)->post(route('packaged.orders.confirm', $order))->assertRedirect();
        }
        $this->actingAs($manager)->post(route('packaged.trips.store'), ['delivery_on' => today()->toDateString(), 'order_ids' => [$orderA->id, $orderB->id]])->assertRedirect();
        $trip = DeliveryTrip::firstOrFail();
        $batchB->update(['status' => 'quarantined']);
        $this->actingAs($manager)->post(route('packaged.trips.dispatch', $trip))->assertSessionHasErrors('workflow');
        $this->assertSame('planned', $trip->fresh()->status);
        $this->assertNull($trip->fresh()->active_dispatch_key);
        $this->assertSame(10, $batchA->fresh()->cases_on_hand);
        $this->assertSame(10, $batchB->fresh()->cases_on_hand);
        $this->assertDatabaseCount('dispatches', 0);
    }

    public function test_roles_and_customer_location_update_are_enforced(): void
    {
        $manager = $this->user('manager');
        $sales = $this->user('sales_staff');
        $distribution = $this->user('distribution_staff');
        $customer = Customer::create(['name' => 'School', 'phone_number' => '0712345005']);
        $this->actingAs($sales)->get(route('packaged.delivery-zones.index'))->assertForbidden();
        $this->actingAs($sales)->post(route('packaged.delivery-zones.update', $this->area('Westlands')->zone), ['fixed_fee' => 1, 'is_active' => 1, 'reason' => 'Unauthorised'])->assertForbidden();
        $this->actingAs($sales)->get(route('packaged.trips.create'))->assertForbidden();
        $trip = DeliveryTrip::create(['trip_reference' => 'DT-ROLE-1', 'status' => 'planned', 'delivery_on' => today(), 'created_by' => $manager->user_id]);
        $this->actingAs($sales)->get(route('packaged.trips.edit', $trip))->assertForbidden();
        $this->actingAs($sales)->post(route('packaged.trips.dispatch', $trip))->assertForbidden();
        $this->actingAs($sales)->post(route('packaged.trips.cancel', $trip), ['reason' => 'Unauthorised'])->assertForbidden();
        $this->actingAs($sales)->post(route('packaged.trips.expense', $trip), ['fuel_expense' => 1, 'other_expense' => 0, 'expense_reference' => 'R', 'reason' => 'Unauthorised'])->assertForbidden();
        $this->actingAs($distribution)->get(route('packaged.trips.create'))->assertOk();
        $this->actingAs($sales)->post(route('customers.delivery.update', $customer), ['delivery_area_id' => $this->area('Westlands')->id, 'address' => 'Near park'])->assertRedirect();
        $this->assertSame('Westlands', $customer->fresh()->deliveryArea->name);
        $order = $this->order($manager, $customer, $this->area('Westlands'));
        $invoice = Invoice::create(['sales_order_id' => $order->id, 'invoice_number' => 'INV-ROLE-1', 'issued_on' => today(), 'amount' => $order->total_amount, 'status' => 'unpaid']);
        $this->actingAs($sales)->post(route('packaged.invoices.delivery-fee-credit', $invoice), ['reason' => 'Unauthorised'])->assertForbidden();
        $this->actingAs($manager)->get(route('packaged.delivery-zones.index'))->assertOk();
    }
}
