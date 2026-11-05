<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_and_reports_share_one_insights_navigation(): void
    {
        $manager = User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => true]);

        $this->actingAs($manager)->get(route('analytics.index'))->assertOk()
            ->assertSee('Insights sections')->assertSee('Customer order demand')
            ->assertSee(route('reports.index'));

        $this->actingAs($manager)->get(route('reports.index', ['type' => 'inventory']))->assertOk()
            ->assertSee('Insights sections')->assertSee('Stock movements')
            ->assertSee(route('analytics.index'));
    }
}
