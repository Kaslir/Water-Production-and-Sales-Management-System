<?php

namespace Tests\Feature;

use App\Models\OperatingExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitExpiryDefaultPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_last_thirty_business_calendar_days_and_custom_dates_still_work(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30:00', 'UTC'));
        $manager = User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'password-long-enough', 'role' => 'manager', 'is_active' => true]);
        OperatingExpense::create(['expense_date' => '2026-10-06', 'category' => 'Utilities', 'amount' => 10, 'recorded_by' => $manager->user_id]);
        OperatingExpense::create(['expense_date' => '2026-10-07', 'category' => 'Utilities', 'amount' => 20, 'recorded_by' => $manager->user_id]);

        $default = $this->actingAs($manager)->get(route('packaged.reports.index'))->assertOk();
        $this->assertSame('2026-10-07', $default->viewData('from'));
        $this->assertSame('2026-11-05', $default->viewData('to'));
        $this->assertSame(20.0, $default->viewData('operatingExpenses'));
        $default->assertSee('value="2026-10-07"', false)->assertSee('value="2026-11-05"', false);

        $custom = $this->actingAs($manager)->get(route('packaged.reports.index', ['from' => '2026-10-06', 'to' => '2026-11-05']))->assertOk();
        $this->assertSame('2026-10-06', $custom->viewData('from'));
        $this->assertSame(30.0, $custom->viewData('operatingExpenses'));
    }
}
