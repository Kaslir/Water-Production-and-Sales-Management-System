<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_users_and_open_pending_requests_from_top_button(): void
    {
        $admin = User::create(['name' => 'Administrator', 'email' => 'admin@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
        User::create(['name' => 'Mary Sales', 'email' => 'mary@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);
        User::create(['name' => 'Synthetic Production', 'email' => 'synthetic@example.test', 'password' => 'password-long-enough', 'role' => 'production_staff', 'is_active' => false]);
        AccessRequest::create(['name' => 'New Applicant', 'email' => 'applicant@example.test', 'phone_number' => '0712345678', 'password' => 'password-long-enough', 'verification_nonce' => 'a-random-verification-nonce', 'email_verified_at' => now(), 'status' => 'pending']);

        $this->actingAs($admin)->get(route('users.index'))->assertOk()
            ->assertSee('Pending requests')->assertSee(route('access.reviews.index'))
            ->assertDontSee('Accounts that can sign in')->assertDontSee('Accounts with access disabled')
            ->assertSee('Mary Sales')->assertDontSee('Synthetic Production')
            ->assertSee('Staff accounts')->assertSee('Edit')->assertSee('Deactivate');

        $this->actingAs($admin)->get(route('users.index', ['status' => 'inactive']))->assertOk()
            ->assertSee('Synthetic Production')->assertDontSee('Mary Sales');

        $this->actingAs($admin)->get(route('users.index', ['status' => 'all', 'role' => 'sales_staff', 'search' => 'Mary']))->assertOk()
            ->assertSee('Mary Sales')->assertDontSee('Synthetic Production')->assertDontSee('admin@example.test');

        $this->actingAs($admin)->get(route('users.index', ['role' => 'administrator;drop']))->assertSessionHasErrors('role');
    }

    public function test_non_admin_cannot_open_user_management(): void
    {
        $staff = User::create(['name' => 'Sales Staff', 'email' => 'sales@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
    }
}
