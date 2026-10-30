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
            'shelf_life_evidence' => 'Approved product shelf-life study QL-2026-01',
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
        $this->actingAs($staff)->get(route('packaged.batches.create'))->assertOk()->assertDontSee('Approved production plan (optional)')->assertDontSee('Total cost');
        $this->actingAs($staff)->post(route('packaged.batches.store'), [
            'product_id' => $product->id,
            'produced_on' => today()->toDateString(),
            'cases_produced' => 10,
            'expires_on' => today()->addYears(10)->toDateString(),
            'bottle_cost' => 0,
            'batch_code' => 'USER-OVERRIDE',
        ])->assertRedirect(route('packaged.batches.index'));

        $batch = ProductionBatch::firstOrFail();
        $this->assertSame(today()->addDays(120)->toDateString(), $batch->expires_on->toDateString());
        $this->assertSame(200.0, (float) $batch->bottle_cost);
        $this->assertSame('500.00', $batch->total_cost);
        $this->assertSame('quality_hold', $batch->status);
        $this->assertNotSame('USER-OVERRIDE', $batch->batch_code);
        $this->assertNotNull($batch->manufacturing_policy_id);
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
        ])->assertSessionHasErrors('product_id');
        $this->assertDatabaseCount('production_batches', 0);
    }

    public function test_batch_is_linked_to_a_matching_approved_plan_without_a_form_selector(): void
    {
        $manager = $this->user('manager');
        $product = Product::where('sku', 'WATER-5L')->firstOrFail();
        $this->actingAs($manager)->post(route('packaged.manufacturing-settings.store', $product), $this->settings())->assertRedirect();
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
        ])->assertRedirect(route('packaged.batches.index'));

        $this->assertSame($plan->id, ProductionBatch::firstOrFail()->production_forecast_decision_id);
        $this->assertSame(10, $plan->fresh()->cases_completed);
    }
}
