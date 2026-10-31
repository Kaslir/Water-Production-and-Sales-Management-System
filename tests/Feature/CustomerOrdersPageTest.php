<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function order(Customer $customer, User $user, string $number, int $amount): SalesOrder
    {
        return SalesOrder::create([
            'order_number' => $number,
            'customer_id' => $customer->customer_id,
            'ordered_on' => today()->toDateString(),
            'status' => 'invoiced',
            'delivery_address' => 'Nairobi',
            'total_amount' => $amount,
            'created_by' => $user->user_id,
        ]);
    }

    public function test_customer_search_and_link_to_order_history(): void
    {
        $user = $this->user('sales_staff');
        $customer = Customer::create(['name' => 'Nairobi Hotel', 'phone_number' => '0712345678', 'email' => 'orders@hotel.test', 'contact_person' => 'Mary Wanjiku', 'address' => 'Nairobi']);
        $other = Customer::create(['name' => 'Mombasa School', 'phone_number' => '0700000002']);
        $order = $this->order($customer, $user, 'SO-HOTEL-001', 1000);
        $this->order($other, $user, 'SO-SCHOOL-001', 2000);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => 500, 'line_total' => 1000]);
        $invoice = Invoice::create(['sales_order_id' => $order->id, 'invoice_number' => 'INV-HOTEL-001', 'issued_on' => today()->toDateString(), 'amount' => 1000, 'status' => 'part_paid']);
        Payment::create(['invoice_id' => $invoice->id, 'amount' => 400, 'paid_on' => today()->toDateString(), 'method' => 'bank_transfer', 'recorded_by' => $user->user_id]);

        $this->actingAs($user)->get(route('customers.index', ['q' => 'Mary']))
            ->assertOk()->assertSee($customer->name)->assertDontSee($other->name)->assertSee(route('customers.show', $customer))
            ->assertSee(route('packaged.orders.index'))->assertSee('Customer orders');
        $this->actingAs($user)->get(route('customers.index', ['q' => '0712345678']))
            ->assertOk()->assertSee($customer->name)->assertDontSee($other->name);
        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertOk()->assertSee('Mary Wanjiku')->assertSee('SO-HOTEL-001')->assertDontSee('SO-SCHOOL-001')
            ->assertSee('INV-HOTEL-001')->assertSee('KSh 1,000.00')->assertSee('KSh 400.00')->assertSee('KSh 600.00');
    }

    public function test_staff_without_customer_access_cannot_open_customer_details(): void
    {
        $user = $this->user('production_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);

        $this->actingAs($user)->get(route('customers.show', $customer))->assertForbidden();
    }

    public function test_distribution_staff_can_reach_orders_from_customers_without_customer_management_access(): void
    {
        $user = $this->user('distribution_staff');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);

        $this->actingAs($user)->get(route('customers.index'))
            ->assertOk()->assertSee('Customer orders')->assertSee(route('packaged.orders.index'))
            ->assertDontSee('Add customer')->assertDontSee(route('customers.show', $customer));
        $this->actingAs($user)->get(route('packaged.orders.index'))->assertOk();
        $this->actingAs($user)->get(route('customers.show', $customer))->assertForbidden();
        $this->actingAs($user)->get(route('operations.create', 'customers'))->assertForbidden();
    }
}
