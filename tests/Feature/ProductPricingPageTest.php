<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPricingPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    public function test_manager_changes_default_price_without_changing_an_existing_order(): void
    {
        $manager = $this->user('manager');
        $customer = Customer::create(['name' => 'Hotel', 'phone_number' => '0712345678']);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $product->update(['default_price_per_case' => 620]);

        $this->actingAs($manager)->get(route('packaged.pricing.index'))
            ->assertOk()->assertSee('Selling price per case');
        $this->actingAs($manager)->post(route('packaged.orders.store'), [
            'customer_id' => $customer->customer_id,
            'delivery_address' => 'Nairobi',
            'delivery_cost' => 0,
            'items' => [['product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => 620]],
        ])->assertRedirect();
        $order = SalesOrder::firstOrFail();

        $this->actingAs($manager)->post(route('packaged.products.price', $product), [
            'default_price_per_case' => 700,
        ])->assertRedirect(route('packaged.pricing.index'));

        $this->assertSame(700.0, (float) $product->fresh()->default_price_per_case);
        $this->assertSame(620.0, (float) $order->items()->firstOrFail()->price_per_case);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.price_changed', 'subject_id' => $product->id]);
        $this->actingAs($manager)->get(route('packaged.orders.create'))->assertOk()->assertSee('value="700.00"', false);
        $this->actingAs($manager)->get(route('packaged.batches.index'))
            ->assertOk()->assertDontSee('Set price')->assertDontSee('Cost / case');
    }

    public function test_production_staff_cannot_access_or_change_selling_prices(): void
    {
        $staff = $this->user('production_staff');
        $product = Product::where('sku', 'WATER-1L')->firstOrFail();

        $this->actingAs($staff)->get(route('packaged.pricing.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('packaged.products.price', $product), [
            'default_price_per_case' => 700,
        ])->assertForbidden();
    }
}
