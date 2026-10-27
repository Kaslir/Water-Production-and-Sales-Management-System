<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionForecastDecision;
use App\Models\TechnicalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProductForecastService
{
    public function recommendation(ProductForecast $forecast): array
    {
        $productId = $forecast->product_id;
        $horizon = $forecast->horizon_days;
        $today = CarbonImmutable::today('Africa/Nairobi');
        $windowStart = CarbonImmutable::parse($forecast->origin_date)->addDay();
        $windowEnd = CarbonImmutable::parse($forecast->origin_date)->addDays($horizon);
        $eligibleStock = (int) DB::table('production_batches')
            ->where('product_id', $productId)
            ->where('status', 'released')
            ->whereDate('expires_on', '>', $windowEnd->toDateString())
            ->sum('cases_on_hand');
        $confirmedCases = (int) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.status', 'confirmed')
            ->sum('sales_order_items.cases_ordered');
        $recordedCases = (int) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.status', '!=', 'cancelled')
            ->whereDate('sales_orders.ordered_on', '>=', $windowStart->toDateString())
            ->whereDate('sales_orders.ordered_on', '<=', min($today->toDateString(), $windowEnd->toDateString()))
            ->sum('sales_order_items.cases_ordered');
        $remainingForecast = max(0, (float) $forecast->predicted_cases - $recordedCases);
        $bufferCases = (int) ceil(max(0, (float) $forecast->mae_cases));
        $demandToCover = max($remainingForecast + $bufferCases, $confirmedCases);
        $activePlan = ProductionForecastDecision::query()
            ->whereHas('forecast', fn ($query) => $query->where('product_id', $productId))
            ->where('plan_status', 'planned')
            ->whereDate('planned_for', '>=', $today->toDateString())
            ->whereDate('planned_for', '<=', $windowEnd->toDateString())
            ->latest('id')->first();
        $plannedCases = $activePlan ? max(0, $activePlan->approved_cases - $activePlan->cases_completed) : 0;

        return ['eligible_stock' => $eligibleStock, 'confirmed_cases' => $confirmedCases, 'recorded_cases' => $recordedCases, 'remaining_forecast_cases' => $remainingForecast, 'buffer_cases' => $bufferCases, 'planned_cases' => $plannedCases, 'recommended_cases' => max(0, (int) ceil($demandToCover - $eligibleStock - $plannedCases))];
    }

    public function run(Product $product, bool $historical = false): array
    {
        $rows = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $product->id)
            ->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw('DATE(sales_orders.ordered_on) order_day, SUM(sales_order_items.cases_ordered) cases')
            ->groupByRaw('DATE(sales_orders.ordered_on)')
            ->orderBy('order_day')
            ->get();
        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'No dated orders have been recorded for this product.'];
        }
        $lastOrderDate = CarbonImmutable::parse($rows->last()->order_day, 'Africa/Nairobi')->startOfDay();
        if (! $historical && $lastOrderDate->diffInDays(CarbonImmutable::today('Africa/Nairobi')) > 14) {
            return ['available' => false, 'reason' => 'The latest order is too old for a current forecast. Use the historical demonstration or record recent genuine orders.'];
        }
        $byDate = $rows->pluck('cases', 'order_day');
        $date = CarbonImmutable::parse($rows->first()->order_day, 'Africa/Nairobi')->startOfDay();
        $today = CarbonImmutable::today('Africa/Nairobi');
        $asOfDate = $historical ? $lastOrderDate : $today;
        $daysSinceSunday = $asOfDate->dayOfWeek === 0 && ! $historical ? 7 : $asOfDate->dayOfWeek;
        $originDate = $asOfDate->subDays($daysSinceSunday);
        if ($originDate->lt($date)) {
            return ['available' => false, 'reason' => 'A complete Sunday history is needed for weekly evaluation.'];
        }
        $dailyCases = [];
        while ($date->lte($originDate)) {
            $dailyCases[] = (float) ($byDate[$date->toDateString()] ?? 0);
            $date = $date->addDay();
        }
        $process = new Process([config('services.analytics.python'), base_path('analytics/product_forecast.py')]);
        $process->setInput(json_encode(['daily_cases' => $dailyCases, 'start_date' => CarbonImmutable::parse($rows->first()->order_day)->toDateString()], JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            TechnicalEvent::create(['event_type' => 'ProductForecastProcessFailure', 'source' => 'product-forecast', 'occurred_at' => now()]);
            throw new RuntimeException('Product forecasting could not run. Check Python and analytics/requirements.txt.');
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($result as $horizon => $report) {
            if (! ($report['available'] ?? false)) {
                continue;
            }
            foreach ($report['methods'] as $method => $values) {
                ProductForecast::updateOrCreate(
                    ['product_id' => $product->id, 'origin_date' => $originDate->toDateString(), 'horizon_days' => (int) $horizon, 'method' => $method],
                    ['predicted_cases' => $values['predicted_cases'], 'mae_cases' => $values['mae_cases'], 'bias_cases' => $values['bias_cases'], 'evaluation' => ['backtest_points' => $values['backtest_points'], 'selected' => $method === $report['best_method'], 'mode' => $historical ? 'historical_demo' : 'current', 'source' => 'recorded packaged-water orders', 'correction_cases' => $values['correction_cases'] ?? 0, 'week_start' => $report['week_start'] ?? null, 'week_end' => $report['week_end'] ?? null]]
                );
            }
        }

        return $result;
    }
}
