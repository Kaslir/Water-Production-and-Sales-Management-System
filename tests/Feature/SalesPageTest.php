<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function order(User $actor, Customer $customer, string $number, string $status, int $amount, int $fee = 0): SalesOrder
    {
        return SalesOrder::create(['order_number' => $number, 'customer_id' => $customer->customer_id, 'ordered_on' => today(), 'status' => $status, 'delivery_address' => 'Nairobi', 'delivery_fee' => $fee, 'total_amount' => $amount, 'created_by' => $actor->user_id]);
    }

    public function test_sales_defaults_to_orders_and_summary_keeps_invoices_credits_and_payments_distinct(): void
    {
        $sales = $this->user('sales_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $draft = $this->order($sales, $customer, 'SO-DRAFT', 'draft', 500);
        $first = $this->order($sales, $customer, 'SO-FIRST', 'invoiced', 1000);
        $second = $this->order($sales, $customer, 'SO-SECOND', 'invoiced', 2000, 200);
        $invoiceA = Invoice::create(['sales_order_id' => $first->id, 'invoice_number' => 'INV-FIRST', 'issued_on' => today(), 'amount' => 1000, 'status' => 'part_paid']);
        $invoiceB = Invoice::create(['sales_order_id' => $second->id, 'invoice_number' => 'INV-SECOND', 'issued_on' => today(), 'amount' => 2000, 'status' => 'credited']);
        Payment::create(['invoice_id' => $invoiceA->id, 'amount' => 400, 'paid_on' => today(), 'method' => 'Bank transfer', 'recorded_by' => $sales->user_id]);
        DeliveryFeeCredit::create(['invoice_id' => $invoiceB->id, 'amount' => 200, 'reason' => 'Fee credited', 'approved_by' => $sales->user_id]);

        $this->actingAs($sales)->get(route('sales.index'))->assertOk()->assertViewHas('tab', 'orders')
            ->assertViewHas('summary', fn ($summary) => $summary['gross_invoiced'] === 3000.0 && $summary['credits'] === 200.0 && $summary['net_invoiced'] === 2800.0 && $summary['payments'] === 400.0 && $summary['outstanding'] === 2400.0 && $summary['awaiting_confirmation'] === 1)
            ->assertSee($draft->order_number)->assertSee('Customer Orders')->assertSee('Invoices')->assertSee('Create Order');
        $this->actingAs($sales)->get(route('sales.index', ['tab' => 'invoices']))->assertOk()->assertViewHas('tab', 'invoices')->assertSee('INV-FIRST')->assertSee('INV-SECOND');
    }

    public function test_order_tab_filters_and_keeps_filter_during_pagination(): void
    {
        $sales = $this->user('sales_staff');
        $hotel = Customer::create(['name' => 'Nairobi Hotel', 'phone_number' => '0712345678']);
        $school = Customer::create(['name' => 'School', 'phone_number' => '0712345679']);
        foreach (range(1, 21) as $index) {
            $this->order($sales, $hotel, sprintf('SO-HOTEL-%03d', $index), 'draft', 100);
        }
        $this->order($sales, $school, 'SO-SCHOOL', 'draft', 100);

        $this->actingAs($sales)->get(route('sales.index', ['tab' => 'orders', 'customer' => 'Nairobi Hotel', 'order_status' => 'draft']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 20)
            ->assertSee('customer=Nairobi%20Hotel')->assertDontSee('SO-SCHOOL');
        $this->actingAs($sales)->get(route('sales.index', ['tab' => 'orders', 'customer' => 'Nairobi Hotel', 'order_status' => 'draft', 'page' => 2]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 1);
    }

    public function test_zero_order_day_rejects_a_day_with_accepted_orders_and_audits_a_valid_day(): void
    {
        $sales = $this->user('sales_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $this->order($sales, $customer, 'SO-ACCEPTED', 'confirmed', 100);
        $this->actingAs($sales)->post(route('packaged.orders.close-day'), ['order_day' => today()->toDateString()])->assertSessionHasErrors('order_day');
        $this->assertDatabaseCount('daily_order_closures', 0);

        $freeDay = today()->subDay()->toDateString();
        $this->actingAs($sales)->post(route('packaged.orders.close-day'), ['order_day' => $freeDay])->assertRedirect();
        $this->assertDatabaseHas('daily_order_closures', ['order_day' => $freeDay, 'recorded_by' => $sales->user_id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.zero_order_day_confirmed']);
    }

    public function test_order_form_uses_database_prices_and_backend_enforces_manager_adjustments(): void
    {
        $manager = $this->user('manager');
        $sales = $this->user('sales_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $area = DeliveryArea::query()->with('zone')->where('name', 'Westlands')->firstOrFail();
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $product->update(['default_price_per_case' => 620]);
        $this->actingAs($manager)->get(route('packaged.orders.create'))->assertOk()->assertSee('Customer &amp; Delivery Details', false)
            ->assertSee('Products &amp; Pricing', false)->assertSee('Order Summary')
            ->assertSee('col-xl-8', false)->assertSee('col-xl-4', false)
            ->assertSee('value="620.00"', false)->assertSee('readonly', false)
            ->assertDontSee('Price action')->assertDontSee('Price Adjustments')->assertDontSee('Override Price');
        $this->actingAs($sales)->get(route('packaged.orders.create'))->assertOk()->assertDontSee('Override Price');

        $input = ['customer_id' => $customer->customer_id, 'delivery_area_id' => $area->id, 'delivery_address' => 'Westlands office', 'requested_delivery_on' => today()->toDateString(), 'items' => [['product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => 700]], 'discount' => 100];
        $this->actingAs($sales)->post(route('packaged.orders.store'), $input)->assertSessionHasErrors('price_override_reason');
        $this->actingAs($manager)->post(route('packaged.orders.store'), $input)->assertSessionHasErrors('price_override_reason');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->actingAs($manager)->post(route('packaged.orders.store'), $input + ['price_override_reason' => 'Approved customer promotion'])->assertRedirect();
        $order = SalesOrder::firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame(2500.0, (float) $order->total_amount);
        $this->assertSame(1200.0, (float) $order->delivery_fee);
        $this->assertSame(700.0, (float) $order->items()->firstOrFail()->price_per_case);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sales_access_is_restricted_to_authorised_roles(): void
    {
        $production = $this->user('production_staff');
        $this->actingAs($production)->get(route('sales.index'))->assertForbidden();
        $this->actingAs($production)->get(route('packaged.orders.create'))->assertForbidden();
        $this->actingAs($production)->post(route('packaged.orders.close-day'), ['order_day' => today()->toDateString()])->assertForbidden();
    }
}
