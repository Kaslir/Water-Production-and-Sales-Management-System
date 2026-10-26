<?php

namespace Tests\Feature;

use App\Models\ProfileEmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Test Person', 'email' => 'person@example.test', 'password' => 'old-password-long', 'role' => 'sales_staff', 'is_active' => true]);
    }

    public function test_user_can_view_and_update_own_name_without_changing_role(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('Test Person')->assertSee('person@example.test');
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'role' => 'administrator'])->assertRedirect();
        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('sales_staff', $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.profile_updated', 'actor_id' => $user->user_id]);
    }

    public function test_email_changes_only_after_code_verification(): void
    {
        Mail::fake();
        $user = $this->user();
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
