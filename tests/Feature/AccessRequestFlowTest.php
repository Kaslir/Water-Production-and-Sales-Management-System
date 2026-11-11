<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
    }

    private function invite(User $administrator, string $role = 'production_staff'): array
    {
        Mail::fake();
        $this->actingAs($administrator)->post(route('users.store'), [
            'name' => 'New Employee', 'email' => 'employee@example.test', 'phone_number' => '0712345678', 'role' => $role,
        ])->assertRedirect(route('users.index', ['status' => 'pending']));
        $employee = User::where('email', 'employee@example.test')->firstOrFail();
        $invitation = $employee->latestInvitation()->firstOrFail();
        $url = '';
        Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use (&$url): bool {
            $url = $mail->activationUrl;

            return $mail->hasTo('employee@example.test');
        });
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $parameters);

        return [$employee, $invitation, $url, $parameters['token']];
    }

    public function test_public_access_requests_are_disabled_and_history_is_preserved(): void
    {
        AccessRequest::create(['name' => 'Old applicant', 'email' => 'old@example.test', 'phone_number' => '0712345678', 'password' => 'password-long-enough', 'verification_nonce' => 'historical-nonce', 'status' => 'pending']);
        $this->get(route('login'))->assertOk()->assertSee('Contact your system administrator.')->assertDontSee('Request access');
        $this->get('/request-access')->assertNotFound();
        $this->post('/request-access', ['email' => 'visitor@example.test'])->assertNotFound();
        $this->get('/request-access/verify/1')->assertNotFound();
        $this->post(route('users.store'), ['email' => 'visitor@example.test'])->assertRedirect(route('login'));
        $this->assertDatabaseHas('access_requests', ['email' => 'old@example.test', 'status' => 'pending']);
        $this->assertDatabaseMissing('users', ['email' => 'visitor@example.test']);
    }

    public function test_only_an_administrator_can_invite_and_assign_an_employee_role(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);
        $payload = ['name' => 'New Employee', 'email' => 'employee@example.test', 'phone_number' => '0712345678', 'role' => 'manager'];
        $this->actingAs($staff)->post(route('users.store'), $payload)->assertForbidden();
        $administrator = $this->administrator();
        $this->actingAs($administrator)->post(route('users.store'), [...$payload, 'role' => 'auditor'])->assertSessionHasErrors('role');
        [$employee] = $this->invite($administrator, 'manager');
        $this->actingAs($staff)->post(route('users.invitation.resend', $employee))->assertForbidden();
        $this->actingAs($staff)->post(route('users.invitation.revoke', $employee))->assertForbidden();
        $this->assertSame('manager', $employee->role);
        $this->assertFalse((bool) $employee->is_active);
        $this->assertSame('0712345678', $employee->phone_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.invited', 'actor_id' => $administrator->user_id]);
        $this->actingAs($administrator)->post(route('users.store'), $payload)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('employee_invitations', 1);
    }

    public function test_inventory_staff_cannot_be_newly_assigned_but_existing_accounts_can_be_edited(): void
    {
        $administrator = $this->administrator();
        $legacy = User::create(['name' => 'Existing Inventory Staff', 'email' => 'inventory@example.test', 'phone_number' => '0712345678', 'password' => 'password-long-enough', 'role' => 'inventory_staff', 'is_active' => true]);
        $sales = User::create(['name' => 'Sales Staff', 'email' => 'sales@example.test', 'phone_number' => '0712345679', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($administrator)->get(route('users.create'))->assertOk()->assertDontSee('value="inventory_staff"', false);
        $this->post(route('users.store'), ['name' => 'New Inventory Staff', 'email' => 'new-inventory@example.test', 'phone_number' => '0712345680', 'role' => 'inventory_staff'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'new-inventory@example.test']);

        $this->get(route('users.edit', $sales))->assertOk()->assertDontSee('value="inventory_staff"', false);
        $this->put(route('users.update', $sales), ['name' => $sales->name, 'email' => $sales->email, 'phone_number' => $sales->phone_number, 'role' => 'inventory_staff', 'is_active' => 1])
            ->assertSessionHasErrors('role');
        $this->assertSame('sales_staff', $sales->fresh()->role);

        $this->get(route('users.edit', $legacy))->assertOk()->assertSee('value="inventory_staff"', false);
        $this->put(route('users.update', $legacy), ['name' => 'Existing Inventory Employee', 'email' => $legacy->email, 'phone_number' => $legacy->phone_number, 'role' => 'inventory_staff', 'is_active' => 1])
            ->assertRedirect(route('users.index'));
        $this->assertSame('inventory_staff', $legacy->fresh()->role);
        $this->assertSame('Existing Inventory Employee', $legacy->fresh()->name);
    }

    public function test_invited_employee_activates_once_and_then_uses_the_normal_security_code_login(): void
    {
        [$employee, $invitation, $url, $token] = $this->invite($this->administrator());
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.submit'), ['email' => $employee->email, 'password' => 'chosen-password-long'])->assertSessionHasErrors('email');
        $this->get($url)->assertOk()->assertSee('Activate Your Account')->assertSee($employee->name)->assertSee('Production Staff');
        $this->post(route('invitations.accept', $invitation), ['token' => $token, 'role' => 'administrator', 'email' => 'changed@example.test', 'password' => 'chosen-password-long', 'password_confirmation' => 'chosen-password-long'])->assertRedirect(route('login'));
        $employee->refresh();
        $this->assertSame('production_staff', $employee->role);
        $this->assertSame('employee@example.test', $employee->email);
        $this->assertTrue((bool) $employee->is_active);
        $this->assertNotNull($employee->activated_at);
        $this->assertNotNull($employee->email_verified_at);
        $this->assertTrue(Hash::check('chosen-password-long', $employee->password));
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->get($url)->assertSee('already been used');
        $this->post(route('invitations.accept', $invitation), ['token' => $token, 'password' => 'another-password-long', 'password_confirmation' => 'another-password-long'])->assertStatus(422);
        $this->post(route('login.submit'), ['email' => $employee->email, 'password' => 'chosen-password-long'])->assertRedirect(route('security.form'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.activated', 'actor_id' => $employee->user_id]);
    }

    public function test_expired_invalid_and_revoked_invitations_do_not_activate_accounts(): void
    {
        [$employee, $invitation, $url, $token] = $this->invite($this->administrator());
        $this->post(route('logout'));
        $this->get(route('invitations.show', ['invitation' => $invitation, 'token' => str_repeat('x', 64)]))->assertSee('invalid or has been revoked')->assertDontSee($employee->email);
        $this->post(route('invitations.accept', $invitation), ['token' => str_repeat('x', 64), 'password' => 'chosen-password-long', 'password_confirmation' => 'chosen-password-long'])->assertStatus(422);
        $invitation->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertSee('This invitation has expired');
        $this->post(route('invitations.accept', $invitation), ['token' => $token, 'password' => 'chosen-password-long', 'password_confirmation' => 'chosen-password-long'])->assertStatus(422);
        $invitation->update(['expires_at' => now()->addDay()]);
        $this->actingAs($this->administratorFromDatabase())->post(route('users.invitation.revoke', $employee))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.invitation_revoked', 'actor_id' => $this->administratorFromDatabase()->user_id]);
        $this->post(route('logout'));
        $this->get($url)->assertSee('invalid or has been revoked');
        $this->post(route('invitations.accept', $invitation), ['token' => $token, 'password' => 'chosen-password-long', 'password_confirmation' => 'chosen-password-long'])->assertStatus(422);
        $this->assertFalse((bool) $employee->fresh()->is_active);
    }

    public function test_resend_revokes_old_link_and_correcting_email_sends_a_new_one(): void
    {
        $administrator = $this->administrator();
        [$employee, $oldInvitation, $oldUrl, $oldToken] = $this->invite($administrator);
        $this->actingAs($administrator)->post(route('users.invitation.resend', $employee))->assertRedirect();
        $this->assertSame('revoked', $oldInvitation->fresh()->status);
        $this->assertSame('pending', $employee->latestInvitation()->first()->status);
        $this->post(route('logout'));
        $this->get($oldUrl)->assertSee('invalid or has been revoked');
        $this->post(route('invitations.accept', $oldInvitation), ['token' => $oldToken, 'password' => 'chosen-password-long', 'password_confirmation' => 'chosen-password-long'])->assertStatus(422);
        $this->actingAs($administrator)->put(route('users.update', $employee), ['name' => 'Corrected Name', 'email' => 'corrected@example.test', 'phone_number' => '0712345678', 'role' => 'production_staff'])->assertRedirect();
        $this->assertSame('corrected@example.test', $employee->fresh()->email);
        $this->assertDatabaseCount('employee_invitations', 3);
    }

    public function test_last_administrator_and_existing_employee_access_are_preserved(): void
    {
        $administrator = $this->administrator();
        $existing = User::create(['name' => 'Existing', 'email' => 'existing@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => true]);
        $this->actingAs($administrator)->delete(route('users.destroy', $administrator))->assertStatus(422);
        $this->actingAs($administrator)->put(route('users.update', $administrator), ['name' => $administrator->name, 'email' => $administrator->email, 'phone_number' => '0712345678', 'role' => 'manager', 'is_active' => 1])->assertStatus(422);
        $this->actingAs($existing)->get(route('packaged.reports.index'))->assertOk();
    }

    public function test_initial_administrator_can_only_be_bootstrapped_into_an_empty_installation(): void
    {
        $this->artisan('app:bootstrap-administrator')
            ->expectsQuestion('Full name', 'First Admin')
            ->expectsQuestion('Email address', 'first@example.test')
            ->expectsQuestion('Phone number (0712345678)', '0712345678')
            ->expectsQuestion('Password (at least 12 characters)', 'first-password-long')
            ->expectsQuestion('Confirm password', 'first-password-long')
            ->assertExitCode(0);
        $this->assertDatabaseHas('users', ['email' => 'first@example.test', 'role' => 'administrator', 'is_active' => true]);
        $this->artisan('app:bootstrap-administrator')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    private function administratorFromDatabase(): User
    {
        return User::where('role', 'administrator')->firstOrFail();
    }
}
