<?php

namespace Tests\Feature;

use App\Mail\AccessApprovedMail;
use App\Mail\AccessVerificationMail;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccessRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-long-enough', 'role' => 'administrator', 'is_active' => true]);
    }

    private function apply(string $email = 'applicant@example.test'): ?AccessRequest
    {
        $this->post(route('access.store'), [
            'name' => 'Applicant Name',
            'email' => $email,
            'phone_number' => '0712345678',
            'password' => 'long-test-password',
            'password_confirmation' => 'long-test-password',
        ])->assertRedirect(route('login'));

        return AccessRequest::where('email', $email)->first();
    }

    public function test_verified_request_requires_admin_approval_before_login(): void
    {
        Mail::fake();
        $this->get(route('login'))->assertOk()->assertSee('Request access');
        $this->get(route('access.create'))->assertOk()->assertSee('PHONE NUMBER');
        $accessRequest = $this->apply();
        $this->assertNotNull($accessRequest);
        $this->assertDatabaseMissing('users', ['email' => $accessRequest->email]);
        $this->assertTrue(Hash::check('long-test-password', $accessRequest->password));
        Mail::assertSentCount(1);
        Mail::assertSent(AccessVerificationMail::class, fn (AccessVerificationMail $mail) => $mail->hasTo($accessRequest->email));
        (new AccessVerificationMail('https://example.test/verify'))->assertSeeInText('https://example.test/verify');

        $administrator = $this->administrator();
        $this->actingAs($administrator)->post(route('access.reviews.approve', $accessRequest), ['role' => 'inventory_staff'])->assertStatus(422);
        $this->actingAs($administrator)->get(route('users.index'))->assertOk()->assertSee('Pending requests');
        $this->actingAs($administrator)->get(route('access.reviews.index'))->assertOk()->assertSee('Awaiting email verification');
        $verificationUrl = URL::temporarySignedRoute('access.verify', now()->addDay(), ['accessRequest' => $accessRequest->id, 'nonce' => $accessRequest->verification_nonce]);
        $this->get($verificationUrl)->assertRedirect(route('login'));
        $this->assertNotNull($accessRequest->fresh()->email_verified_at);
        $this->actingAs($administrator)->get(route('access.reviews.index'))->assertOk()->assertSee('Pending approval');

        $this->actingAs($administrator)->post(route('access.reviews.approve', $accessRequest), ['role' => 'inventory_staff'])->assertRedirect(route('access.reviews.index'));
        $user = User::where('email', $accessRequest->email)->firstOrFail();
        $this->assertSame('inventory_staff', $user->role);
        $this->assertSame('0712345678', $user->phone_number);
        $this->assertEquals(1, $user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('long-test-password', $user->password));
        $this->assertSame('approved', $accessRequest->fresh()->status);
        Mail::assertSentCount(2);
        Mail::assertSent(AccessApprovedMail::class, fn (AccessApprovedMail $mail) => $mail->hasTo($accessRequest->email));
        (new AccessApprovedMail)->assertSeeInText('approved');
        $this->assertDatabaseHas('audit_logs', ['event' => 'access_request.approved', 'actor_id' => $administrator->user_id]);
        $this->actingAs($administrator)->post(route('access.reviews.approve', $accessRequest), ['role' => 'manager'])->assertStatus(422);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'long-test-password'])->assertRedirect(route('security.form'));
    }

    public function test_only_admin_can_review_and_rejected_request_can_be_resubmitted(): void
    {
        Mail::fake();
        $accessRequest = $this->apply();
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'password-long-enough', 'role' => 'sales_staff', 'is_active' => true]);
        $this->actingAs($staff)->get(route('access.reviews.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('access.reviews.approve', $accessRequest), ['role' => 'sales_staff'])->assertForbidden();
        $administrator = $this->administrator();
        $this->actingAs($administrator)->post(route('access.reviews.reject', $accessRequest))->assertRedirect();
        $this->assertSame('rejected', $accessRequest->fresh()->status);
        $this->assertDatabaseMissing('users', ['email' => $accessRequest->email]);
        $this->app['auth']->forgetGuards();
        $newRequest = $this->apply();
        $this->assertSame($accessRequest->id, $newRequest->id);
        $this->assertSame('pending', $newRequest->status);
        $this->assertNull($newRequest->email_verified_at);
    }

    public function test_old_verification_link_cannot_verify_a_new_request(): void
    {
        Mail::fake();
        $accessRequest = $this->apply();
        $oldUrl = URL::temporarySignedRoute('access.verify', now()->addDay(), ['accessRequest' => $accessRequest->id, 'nonce' => $accessRequest->verification_nonce]);
        $accessRequest->update(['status' => 'rejected']);
        $newRequest = $this->apply();
        $this->get($oldUrl)->assertForbidden();
        $this->assertNull($newRequest->fresh()->email_verified_at);
    }

    public function test_existing_email_receives_generic_response_without_new_request(): void
    {
        Mail::fake();
        $this->administrator();
        $this->apply('admin@example.test');
        $this->assertDatabaseCount('access_requests', 0);
        Mail::assertNothingSent();
    }
}
