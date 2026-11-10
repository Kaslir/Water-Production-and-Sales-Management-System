<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\Product;
use App\Models\ProductManufacturingPolicy;
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
            'delivery_area_id' => DeliveryArea::where('name', 'Industrial Area')->value('id'),
            'requested_delivery_on' => today()->toDateString(),
            'delivery_cost' => 0,
            'items' => [['product_id' => $product->id, 'cases_ordered' => 2, 'price_per_case' => 620]],
        ])->assertRedirect();
        $order = SalesOrder::firstOrFail();

        $this->actingAs($manager)->post(route('packaged.products.price', $product), [
            'default_price_per_case' => 700,
            'reason' => 'Approved quarterly price review',
        ])->assertRedirect(route('packaged.pricing.index'));

        $this->assertSame(700.0, (float) $product->fresh()->default_price_per_case);
        $this->assertSame(620.0, (float) $order->items()->firstOrFail()->price_per_case);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.price_changed', 'subject_id' => $product->id]);
        $this->actingAs($manager)->get(route('packaged.pricing.index'))
            ->assertOk()
            ->assertSee('Price History')
            ->assertSee('KSh 620.00')
            ->assertSee('KSh 700.00')
            ->assertSee('Approved quarterly price review');
        $this->actingAs($manager)->get(route('packaged.orders.create'))->assertOk()->assertSee('value="700.00"', false);
        $this->actingAs($manager)->get(route('inventory.index', ['tab' => 'batches']))
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

    public function test_price_requires_a_positive_amount_with_no_more_than_two_decimal_places(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-1L')->firstOrFail();

        $this->actingAs($manager)->post(route('packaged.products.price', $product), [
            'default_price_per_case' => '12.345',
        ])->assertSessionHasErrors('default_price_per_case');
        $this->actingAs($manager)->post(route('packaged.products.price', $product), [
            'default_price_per_case' => '0',
        ])->assertSessionHasErrors('default_price_per_case');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'product.price_changed', 'subject_id' => $product->id]);
    }

    public function test_pricing_page_hides_estimated_profit_and_margin(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-5L')->firstOrFail();
        $product->update(['default_price_per_case' => '100.00']);
        $this->actingAs($manager)->get(route('packaged.pricing.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#priceHistory"', false)
            ->assertSee('id="priceHistory"', false)
            ->assertDontSee('Estimated gross profit / case')
            ->assertDontSee('Estimated gross margin');

        ProductManufacturingPolicy::create([
            'product_id' => $product->id,
            'effective_from' => today()->toDateString(),
            'shelf_life_days' => 120,
            'shelf_life_evidence' => 'Approved study REF-100',
            'water_treatment_per_case' => 10,
            'bottle_per_case' => 20,
            'inbound_transport_per_case' => 3,
            'cap_label_per_case' => 4,
            'labour_per_case' => 5,
            'electricity_per_case' => 6,
            'other_production_per_case' => 2,
            'approved_by' => $manager->user_id,
        ]);
        $this->actingAs($manager)->get(route('packaged.pricing.index'))
            ->assertOk()
            ->assertDontSee('Estimated gross profit / case')
            ->assertDontSee('Estimated gross margin');
    }
}
