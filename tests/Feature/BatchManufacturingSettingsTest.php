<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchManufacturingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    private function settings(): array
    {
        return [
            'effective_from' => today()->toDateString(),
            'shelf_life_days' => 120,
            'water_treatment_per_case' => 10,
            'bottle_per_case' => 20,
            'inbound_transport_per_case' => 3,
            'cap_label_per_case' => 4,
            'labour_per_case' => 5,
            'electricity_per_case' => 6,
            'other_production_per_case' => 2,
        ];
    }

    public function test_batch_creation_uses_approved_settings_and_ignores_submitted_expiry_or_costs(): void
    {
        $manager = $this->user('manager');
        $staff = $this->user('production_staff');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();

        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), [
            'effective_from' => today()->toDateString(),
            'price_per_litre' => '1.0000',
            'bill_or_tariff_reference' => 'TEST-BILL-1',
        ])->assertRedirect();
        $this->actingAs($staff)->get(route('packaged.batches.create'))->assertOk()
            ->assertDontSee('Approved production plan (optional)')
            ->assertDontSee('Total cost')
            ->assertDontSee('Water source reference')
            ->assertDontSee('Treatment log reference')
            ->assertDontSee('Water input (L)')
            ->assertDontSee('Approve production without a matching plan?');
        $this->actingAs($manager)->get(route('packaged.batches.create'))->assertOk()
            ->assertSee('Approve production without a matching plan?')
            ->assertSee('id="unplanned-no" value="0" checked', false);
        $batchData = [
            'product_id' => $product->id,
            'produced_on' => today()->toDateString(),
            'cases_produced' => 10,
            'expires_on' => today()->addYears(10)->toDateString(),
            'bottle_cost' => 0,
            'batch_code' => 'USER-OVERRIDE',
        ];
        $this->actingAs($staff)->post(route('packaged.batches.store'), $batchData)->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('production_batches', 0);
        $this->actingAs($manager)->post(route('packaged.batches.store'), $batchData + ['unplanned_approval' => '0'])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('production_batches', 0);
        $batchData['unplanned_approval'] = '1';
        $this->actingAs($manager)->post(route('packaged.batches.store'), $batchData)->assertRedirect(route('inventory.index', ['tab' => 'batches']));

        $batch = ProductionBatch::firstOrFail();
        $this->assertSame(today()->addDays(120)->toDateString(), $batch->expires_on->toDateString());
        $this->assertSame(200.0, (float) $batch->bottle_cost);
        $this->assertSame('120.00', $batch->water_supply_cost);
        $this->assertSame('620.00', $batch->total_cost);
        $this->assertSame('62.0000', $batch->cost_per_case);
        $this->assertSame('quality_hold', $batch->status);
        $this->assertNotSame('USER-OVERRIDE', $batch->batch_code);
        $this->assertNotNull($batch->manufacturing_policy_id);
        $this->assertSame($manager->user_id, $batch->unplanned_authorized_by);
        $this->assertNull($batch->water_source_reference);
        $this->assertNull($batch->treatment_log_reference);
        $this->assertSame(120.0, (float) $batch->water_input_litres);
        $this->assertSame('Approved without a matching production plan.', $batch->unplanned_reason);

        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), [
            'effective_from' => today()->addDay()->toDateString(),
            'price_per_litre' => '2.0000',
            'bill_or_tariff_reference' => 'TEST-BILL-2',
        ])->assertRedirect();
        $this->assertSame('620.00', $batch->fresh()->total_cost);
    }

    public function test_production_staff_cannot_approve_settings_and_unconfigured_products_cannot_be_recorded(): void
    {
        $staff = $this->user('production_staff');
        $product = Product::where('sku', 'WATER-1L')->firstOrFail();

        $this->actingAs($staff)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertForbidden();
        $this->actingAs($staff)->post(route('packaged.batches.store'), [
            'product_id' => $product->id,
            'produced_on' => today()->toDateString(),
            'cases_produced' => 4,
            'water_source_reference' => 'MUNICIPAL-METER-001',
            'water_input_litres' => 50,
            'treatment_log_reference' => 'TREATMENT-001',
        ])->assertSessionHasErrors('product_id');
        $this->assertDatabaseCount('production_batches', 0);
    }

    public function test_water_rate_requires_manager_approval_and_a_reference_before_production(): void
    {
        $manager = $this->user('manager');
        $staff = $this->user('production_staff');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertRedirect();

        $rate = [
            'effective_from' => today()->toDateString(),
            'price_per_litre' => '0.2500',
            'bill_or_tariff_reference' => 'TEST-WATER-BILL',
        ];
        $this->actingAs($staff)->post(route('packaged.manufacturing-settings.water-rate.store'), $rate)->assertForbidden();
        $this->actingAs($manager)->post(route('packaged.batches.store'), [
            'product_id' => $product->id,
            'produced_on' => today()->toDateString(),
            'cases_produced' => 10,
            'unplanned_approval' => '1',
        ])->assertSessionHasErrors('product_id');
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), array_diff_key($rate, ['bill_or_tariff_reference' => true]))
            ->assertSessionHasErrors('bill_or_tariff_reference');
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), $rate)->assertRedirect();
        $this->assertDatabaseCount('water_supply_rates', 1);
    }

    public function test_batch_is_linked_to_a_matching_approved_plan_without_a_form_selector(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-5L')->firstOrFail();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), [
            'effective_from' => today()->toDateString(),
            'price_per_litre' => '1.0000',
            'bill_or_tariff_reference' => 'TEST-BILL-1',
        ])->assertRedirect();
        $forecast = ProductForecast::create([
            'product_id' => $product->id,
            'origin_date' => today()->subDay(),
            'horizon_days' => 7,
            'method' => 'random_forest',
            'predicted_cases' => 30,
        ]);
        $plan = ProductionForecastDecision::create([
            'product_forecast_id' => $forecast->id,
            'recommended_cases' => 20,
            'approved_cases' => 20,
            'planned_for' => today()->toDateString(),
            'cases_completed' => 0,
            'plan_status' => 'planned',
            'decided_by' => $manager->user_id,
        ]);

        $this->actingAs($manager)->post(route('packaged.batches.store'), [
            'product_id' => $product->id,
            'produced_on' => today()->toDateString(),
            'cases_produced' => 10,
            'water_source_reference' => 'MUNICIPAL-METER-001',
            'water_input_litres' => 210,
            'treatment_log_reference' => 'TREATMENT-001',
        ])->assertRedirect(route('inventory.index', ['tab' => 'batches']));

        $this->assertSame($plan->id, ProductionBatch::firstOrFail()->production_forecast_decision_id);
        $this->assertSame(200.0, (float) ProductionBatch::firstOrFail()->water_input_litres);
        $this->assertSame(10, $plan->fresh()->cases_completed);
    }

    public function test_manufacturing_settings_show_the_active_policy_and_its_approval_history(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->actingAs($manager)
            ->get(route('packaged.manufacturing-settings.index'))
            ->assertOk()
            ->assertSee('Product manufacturing policies')
            ->assertDontSee('Approve new settings');

        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertRedirect();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.water-rate.store'), [
            'effective_from' => today()->toDateString(),
            'price_per_litre' => '1.0000',
            'bill_or_tariff_reference' => 'TEST-BILL-1',
        ])->assertRedirect();
        $futureSettings = $this->settings();
        $futureSettings['effective_from'] = today()->addDay()->toDateString();
        $futureSettings['shelf_life_days'] = 150;
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $futureSettings)->assertRedirect();

        $this->actingAs($manager)
            ->get(route('packaged.manufacturing-settings.index', ['product' => $product->id]))
            ->assertOk()
            ->assertSee('Currently approved settings')
            ->assertSee('120 days')
            ->assertSee('KSh 62.00')
            ->assertSee('View Settings History (2)')
            ->assertSee('150 days')
            ->assertDontSee('Supporting document reference')
            ->assertDontSee('Shelf-life evidence or reference')
            ->assertDontSee('Approved by manager');
    }
}
