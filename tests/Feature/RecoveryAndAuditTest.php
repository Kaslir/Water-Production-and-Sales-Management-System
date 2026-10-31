<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PasswordResetCode;
use App\Models\TechnicalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecoveryAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_code_is_single_use_and_audited(): void
    {
        $user = User::create(['name' => 'Tester', 'email' => 'tester@example.test', 'password' => 'old-password-long', 'role' => 'sales_staff', 'is_active' => true]);
        PasswordResetCode::create(['user_id' => $user->user_id, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
        $this->post(route('password.reset_code'), ['email' => $user->email, 'code' => '123456', 'password' => 'new-password-long', 'password_confirmation' => 'new-password-long'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password-long', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_reset', 'actor_id' => $user->user_id]);
        $this->post(route('password.reset_code'), ['email' => $user->email, 'code' => '123456', 'password' => 'another-password-long', 'password_confirmation' => 'another-password-long'])->assertSessionHasErrors('code');
    }

    public function test_auditor_can_read_but_not_change_operational_records(): void
    {
        $auditor = User::create(['name' => 'Auditor', 'email' => 'auditor@example.test', 'password' => 'password-long-enough', 'role' => 'auditor', 'is_active' => true]);
        AuditLog::create(['actor_id' => $auditor->user_id, 'event' => 'batch.released', 'subject_type' => 'ProductionBatch', 'subject_id' => 12]);
        $this->actingAs($auditor)->get(route('audit.index'))->assertRedirect(route('logs.index', ['tab' => 'audit']));
        $this->actingAs($auditor)->get(route('logs.index'))->assertOk()->assertSee('batch.released')->assertSee(now()->format('d M Y'))
            ->assertDontSee('System events');
        $this->actingAs($auditor)->get(route('logs.index', ['tab' => 'system']))->assertForbidden();
        $this->actingAs($auditor)->get(route('technical-events.index'))->assertForbidden();
        $this->actingAs($auditor)->post(route('packaged.expenses.store'), ['expense_date' => today()->toDateString(), 'category' => 'Transport', 'amount' => 100])->assertForbidden();
    }

    public function test_administrator_can_view_system_event_metadata(): void
    {
        $administrator = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
        TechnicalEvent::create(['event_type' => 'ProductForecastProcessFailure', 'source' => 'product-forecast', 'occurred_at' => now()]);
        $this->actingAs($administrator)->get(route('technical-events.index'))->assertRedirect(route('logs.index', ['tab' => 'system']));
        $this->actingAs($administrator)->get(route('logs.index', ['tab' => 'system']))->assertOk()
            ->assertSee('ProductForecastProcessFailure')->assertSee('Audit trail')->assertSee('System events');
    }

    public function test_deactivated_account_cannot_use_an_existing_session(): void
    {
        $user = User::create(['name' => 'Inactive', 'email' => 'inactive@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => false]);
        $this->actingAs($user)->get(route('packaged.reports.index'))->assertRedirect(route('login'));
    }
}
