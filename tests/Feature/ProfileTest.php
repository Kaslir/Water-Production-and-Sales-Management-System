<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use App\Models\ProfileEmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'sales_staff'): User
    {
        return User::create(['name' => 'Test Person', 'email' => 'person@example.test', 'password' => 'old-password-long', 'role' => $role, 'is_active' => true]);
    }

    public function test_regular_user_sees_read_only_details_and_can_open_reset_page(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Test Person')
            ->assertSee('person@example.test')
            ->assertSee('Contact an administrator if you need to change your name, email address, or role.')
            ->assertSee('Reset password')
            ->assertSee('If you need help changing your password, contact an administrator.')
            ->assertDontSee('Save name')
            ->assertDontSee('New email');
        $this->actingAs($user)->get(route('password.request'))->assertOk()->assertSee('person@example.test');
        $this->actingAs($user)->get(route('password.reset_form'))->assertOk();
    }

    public function test_regular_user_cannot_change_name_or_email_by_posting_directly(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'role' => 'administrator'])->assertForbidden();
        $this->actingAs($user)->post(route('profile.email.request'), ['new_email' => 'new@example.test', 'current_password' => 'old-password-long'])->assertForbidden();
        $this->actingAs($user)->post(route('profile.email.verify'), ['code' => '123456'])->assertForbidden();
        $this->actingAs($user)->delete(route('profile.email.cancel'))->assertForbidden();
        $this->assertSame('Test Person', $user->fresh()->name);
        $this->assertSame('person@example.test', $user->fresh()->email);
        $this->assertSame('sales_staff', $user->fresh()->role);
        $this->assertDatabaseCount('profile_email_changes', 0);
    }

    public function test_signed_in_user_can_reset_password_with_emailed_code(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->actingAs($user)->post(route('password.email_code'), ['email' => $user->email])
            ->assertRedirect(route('password.reset_form'));

        $resetCode = PasswordResetCode::where('user_id', $user->user_id)->firstOrFail();
        $resetCode->update(['code_hash' => Hash::make('123456')]);

        $this->actingAs($user)->post(route('password.reset_code'), [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'new-password-long',
            'password_confirmation' => 'new-password-long',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-long', $user->fresh()->password));
        $this->assertNotNull($resetCode->fresh()->used_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_reset', 'actor_id' => $user->user_id]);
    }

    public function test_administrator_can_update_own_name_without_changing_role(): void
    {
        $user = $this->user('administrator');
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'role' => 'administrator'])->assertRedirect();
        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('administrator', $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.profile_updated', 'actor_id' => $user->user_id]);
    }

    public function test_email_changes_only_after_code_verification(): void
    {
        Mail::fake();
        $user = $this->user('administrator');
        $this->actingAs($user)->post(route('profile.email.request'), ['new_email' => 'new@example.test', 'current_password' => 'bad'])->assertSessionHasErrors('current_password');
        $this->assertDatabaseCount('profile_email_changes', 0);
        $this->actingAs($user)->post(route('profile.email.request'), ['new_email' => 'new@example.test', 'current_password' => 'old-password-long'])->assertSessionHasNoErrors();
        $this->assertSame('person@example.test', $user->fresh()->email);
        $pending = ProfileEmailChange::where('user_id', $user->user_id)->firstOrFail();
        $pending->update(['code_hash' => Hash::make('123456')]);
        $this->actingAs($user)->post(route('profile.email.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('profile.email.verify'), ['code' => '123456'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertDatabaseCount('profile_email_changes', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.email_changed', 'actor_id' => $user->user_id]);
    }

    public function test_password_change_requires_current_password(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'bad', 'password' => 'new-password-long', 'password_confirmation' => 'new-password-long'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'old-password-long', 'password' => 'new-password-long', 'password_confirmation' => 'new-password-long'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-long', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_changed', 'actor_id' => $user->user_id]);
    }
}
