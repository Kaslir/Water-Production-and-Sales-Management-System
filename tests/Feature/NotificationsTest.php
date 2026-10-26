<?php

namespace Tests\Feature;

use App\Models\AppAlert;
use App\Models\Product;
use App\Models\User;
use App\Services\AppAlertService;
use App\Services\PackagedWaterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::create(['name' => $role, 'email' => $email, 'password' => 'password-long-enough', 'role' => $role, 'is_active' => true]);
    }

    public function test_bell_and_notification_page_are_private_and_read_state_is_independent(): void
    {
        $manager = $this->user('manager', 'manager@example.test');
        $other = $this->user('inventory_staff', 'inventory@example.test');
        $alerts = app(AppAlertService::class);
        $alerts->publish('test:batch:1', 'quality', 'warning', 'Batch needs review', 'Check the batch.', 'batch', 1, ['manager', 'inventory_staff']);
        $alerts->publish('test:batch:1', 'quality', 'warning', 'Batch needs review', 'Check the batch.', 'batch', 1, ['manager', 'inventory_staff']);

        $this->assertSame(2, AppAlert::count());
        $managerAlert = $manager->appAlerts()->firstOrFail();
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Notifications, 1 unread')->assertSee('Batch needs review');
        $this->actingAs($manager)->get(route('notifications.index'))->assertOk()->assertSee('Batch needs review');
        $this->actingAs($other)->post(route('notifications.read', $managerAlert))->assertNotFound();
        $this->actingAs($manager)->post(route('notifications.read', $managerAlert))->assertRedirect();
        $this->assertNotNull($managerAlert->fresh()->read_at);
        $this->assertNull($managerAlert->fresh()->resolved_at);
        $this->assertNull($other->appAlerts()->firstOrFail()->read_at);
    }

    public function test_quality_expiry_and_recall_alerts_follow_batch_state(): void
    {
        Mail::fake();
        $manager = $this->user('manager', 'manager@example.test');
        $inventory = $this->user('inventory_staff', 'inventory@example.test');
        $product = Product::where('sku', 'WATER-500ML')->firstOrFail();
        $service = app(PackagedWaterService::class);
        $batch = $service->createBatch([
            'product_id' => $product->id,
            'batch_code' => 'ALERT-BATCH-1',
            'produced_on' => today()->subDay()->toDateString(),
            'expires_on' => today()->addDays(5)->toDateString(),
            'cases_produced' => 10,
            'water_treatment_cost' => 10,
            'bottle_cost' => 10,
            'cap_label_cost' => 10,
            'labour_cost' => 10,
            'electricity_cost' => 10,
            'other_production_cost' => 10,
        ], $manager->user_id);

        $this->assertDatabaseHas('app_alerts', ['user_id' => $inventory->user_id, 'alert_key' => 'batch:quality:'.$batch->id]);
        $service->releaseBatch($batch, $manager->user_id, 'Passed');
        $this->assertNotNull($inventory->appAlerts()->where('alert_key', 'batch:quality:'.$batch->id)->firstOrFail()->resolved_at);

        $alerts = app(AppAlertService::class);
        $alerts->scanIfDue(true);
        $alerts->scanIfDue(true);
        $this->assertSame(1, $manager->appAlerts()->where('alert_key', 'batch:expiry:'.$batch->id.':7-days')->count());

        $service->recallBatch($batch, $manager->user_id, 'Quality concern');
        $this->assertDatabaseHas('app_alerts', ['user_id' => $manager->user_id, 'alert_key' => 'batch:recalled:'.$batch->id]);
        $alerts->scanIfDue(true);
        $this->assertNotNull($manager->appAlerts()->where('alert_key', 'batch:expiry:'.$batch->id.':7-days')->firstOrFail()->resolved_at);
    }
}
