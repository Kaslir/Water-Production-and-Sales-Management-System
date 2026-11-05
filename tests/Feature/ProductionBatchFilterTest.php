<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionBatchFilterTest extends TestCase
{
    use RefreshDatabase;

    private function batch(string $code, int $daysAgo, int $cases, User $user, Product $product, string $status = 'quality_hold', int $expiryDays = 180): void
    {
        ProductionBatch::create([
            'product_id' => $product->id,
            'batch_code' => $code,
            'produced_on' => today()->subDays($daysAgo)->toDateString(),
            'expires_on' => today()->addDays($expiryDays)->toDateString(),
            'cases_produced' => $cases,
            'cases_on_hand' => $status === 'released' ? $cases : 0,
            'status' => $status,
            'total_cost' => 0,
            'cost_per_case' => 0,
            'produced_by' => $user->user_id,
        ]);
    }

    public function test_batch_code_and_production_dates_filter_rows_and_summary(): void
    {
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->batch('B-OLD-001', 30, 10, $user, $product);
        $this->batch('B-NEW-001', 5, 20, $user, $product);
        $this->batch('B-NEW-002', 1, 30, $user, $product);

        $this->actingAs($user)->get(route('production.index', [
            'batch' => 'NEW',
            'from' => today()->subDays(6)->toDateString(),
            'to' => today()->subDays(2)->toDateString(),
        ]))->assertOk()->assertSee('B-NEW-001')->assertDontSee('B-NEW-002')->assertDontSee('B-OLD-001')->assertSee('20 cases')->assertSee('1 records');

        $this->actingAs($user)->get(route('production.index', [
            'to' => today()->subDays(20)->toDateString(),
        ]))->assertOk()->assertSee('B-OLD-001')->assertDontSee('B-NEW-001');
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);

        $this->actingAs($user)->get(route('production.index', [
            'from' => today()->toDateString(),
            'to' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('to');
        $this->actingAs($user)->get(route('production.index', ['product_id' => 999999]))->assertSessionHasErrors('product_id');
    }

    public function test_summary_uses_all_batches_and_current_month_while_expiry_remains_separate_from_quality(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $this->batch('B-OCT', 6, 10, $user, $product, 'released', -1);
        $this->batch('B-HOLD', 4, 20, $user, $product);
        $this->batch('B-TODAY', 0, 30, $user, $product, 'released');

        $response = $this->actingAs($user)->get(route('production.index', ['batch' => 'B-HOLD']))->assertOk();
        $this->assertSame(['all_time_cases' => 60, 'month_cases' => 50, 'pending_batches' => 1, 'month_label' => 'November 2026'], $response->viewData('productionSummary'));
        $response->assertSee('Create batch')->assertSee('Quality Hold')->assertDontSee('B-OCT');

        $all = $this->actingAs($user)->get(route('production.index'))->assertOk();
        $all->assertSee('B-OCT')->assertSee('Expired')->assertSee('Released')->assertSee('05 Nov 2026');
        $this->assertSame('released', ProductionBatch::where('batch_code', 'B-OCT')->value('status'));

        $inventoryUser = User::create(['name' => 'Inventory Staff', 'email' => 'inventory@example.test', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $this->actingAs($inventoryUser)->get(route('production.index'))->assertForbidden();
        $this->actingAs($inventoryUser)->get(route('packaged.batches.create'))->assertForbidden();
        $this->actingAs($user)->get(route('packaged.batches.create'))->assertOk();
    }

    public function test_product_code_and_dates_combine_and_remain_selected_during_pagination(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Africa/Nairobi'));
        $user = User::create(['name' => 'Production Staff', 'email' => 'production@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => true]);
        $small = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $large = Product::where('sku', 'WATER-20L')->firstOrFail();
        for ($number = 1; $number <= 21; $number++) {
            $this->batch('MATCH-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 2, 1, $user, $small);
        }
        $this->batch('MATCH-OTHER-PRODUCT', 2, 1, $user, $large);
        $this->batch('MATCH-OLD', 40, 1, $user, $small);
        $this->batch('DIFFERENT', 2, 1, $user, $small);

        $filters = ['batch' => 'MATCH', 'product_id' => $small->id, 'from' => '2026-11-01', 'to' => '2026-11-05'];
        $first = $this->actingAs($user)->get(route('production.index', $filters))->assertOk()
            ->assertDontSee('MATCH-OTHER-PRODUCT')->assertDontSee('MATCH-OLD')->assertDontSee('DIFFERENT');
        $this->assertSame(21, $first->viewData('rows')->total());
        $this->assertSame($small->id, (int) $first->viewData('filters')['product_id']);
        parse_str(parse_url($first->viewData('rows')->url(2), PHP_URL_QUERY), $pageQuery);
        $this->assertSame('2', (string) $pageQuery['page']);
        $this->assertSame('MATCH', $pageQuery['batch']);
        $this->assertSame((string) $small->id, (string) $pageQuery['product_id']);
        $this->assertSame('2026-11-01', $pageQuery['from']);
        $this->assertSame('2026-11-05', $pageQuery['to']);
        $this->actingAs($user)->get(route('production.index', $filters + ['page' => 2]))->assertOk()->assertSee('MATCH-01');
        $this->actingAs($user)->get(route('production.index', ['product_id' => $small->id, 'batch' => 'NO-MATCH']))->assertOk()->assertSee('No production batches match these filters.');
    }
}
