<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPhoneDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_phone_is_displayed_as_text_with_leading_zero(): void
    {
        $administrator = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'password-long-enough',
            'role' => 'administrator',
            'is_active' => true,
        ]);
        Customer::create(['name' => 'Example Customer', 'phone_number' => '0712345678']);

        $this->actingAs($administrator)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('0712345678')
            ->assertDontSee('712,345,678.00');
    }

    public function test_customer_pagination_uses_bootstrap_markup_without_oversized_svg_arrows(): void
    {
        $administrator = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'password-long-enough',
            'role' => 'administrator',
            'is_active' => true,
        ]);
        foreach (range(1, 13) as $number) {
            Customer::create(['name' => 'Customer '.$number, 'phone_number' => '070000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT)]);
        }

        $this->actingAs($administrator)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('class="pagination"', false)
            ->assertSee('class="page-link"', false)
            ->assertDontSee('<svg', false);
    }
}
