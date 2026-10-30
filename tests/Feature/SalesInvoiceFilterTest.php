<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceFilterTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $number, int $daysAgo, string $status, int $amount, User $user, Customer $customer): void
    {
        $order = SalesOrder::create([
            'order_number' => 'SO-'.$number,
            'customer_id' => $customer->customer_id,
            'ordered_on' => today()->subDays($daysAgo)->toDateString(),
            'status' => 'invoiced',
            'delivery_address' => 'Nairobi',
            'total_amount' => $amount,
            'created_by' => $user->user_id,
        ]);
        Invoice::create([
            'sales_order_id' => $order->id,
            'invoice_number' => $number,
            'issued_on' => today()->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    public function test_invoice_number_issue_dates_and_status_filter_rows_and_total(): void
    {
        $user = User::create(['name' => 'Sales Staff', 'email' => 'sales@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $this->invoice('INV-OLD-001', 30, 'unpaid', 1000, $user, $customer);
        $this->invoice('INV-NEW-001', 5, 'paid', 2000, $user, $customer);
        $this->invoice('INV-NEW-002', 1, 'part_paid', 3000, $user, $customer);

        $this->actingAs($user)->get(route('sales.index', [
            'invoice' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
            'status' => 'paid',
        ]))->assertOk()->assertSee('INV-NEW-001')->assertDontSee('INV-NEW-002')->assertDontSee('INV-OLD-001')->assertSee('KSh 2,000')->assertSee('1 records');

        $this->actingAs($user)->get(route('sales.index', [
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('INV-OLD-001')->assertDontSee('INV-NEW-001')->assertSee('KSh 1,000');
    }

    public function test_invalid_invoice_filters_are_rejected(): void
    {
        $user = User::create(['name' => 'Sales Staff', 'email' => 'sales@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('sales.index', [
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
            'status' => 'unknown',
        ]))->assertSessionHasErrors(['to', 'status']);
    }
}
