<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppAlertService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use DatabaseMigrations;

    public function test_critical_alert_email_is_sent_only_after_commit(): void
    {
        Mail::fake();
        User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => true]);
        $alerts = app(AppAlertService::class);

        DB::transaction(function () use ($alerts): void {
            $alerts->publish('critical:1', 'shortage', 'high', 'Critical stock shortage', 'More cases are needed.', 'stock', 1, ['manager'], ['manager']);
            Mail::assertNothingSent();
        });

        Mail::assertSentCount(1);
        $this->assertDatabaseHas('app_alerts', ['alert_key' => 'critical:1']);
    }
}
