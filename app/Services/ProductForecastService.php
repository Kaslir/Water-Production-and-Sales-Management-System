<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductForecast;
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
        $eligibleStock = (int) DB::table('production_batches')
            ->where('product_id', $productId)
            ->where('status', 'released')
            ->whereDate('expires_on', '>', CarbonImmutable::today('Africa/Nairobi')->addDays($horizon)->toDateString())
            ->sum('cases_on_hand');
        $confirmedCases = (int) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.status', 'confirmed')
            ->sum('sales_order_items.cases_ordered');
        $demandToCover = max((float) $forecast->predicted_cases, $confirmedCases);

        return ['eligible_stock' => $eligibleStock, 'confirmed_cases' => $confirmedCases, 'recommended_cases' => max(0, (int) ceil($demandToCover - $eligibleStock))];
    }

    public function run(Product $product): array
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
        if (CarbonImmutable::parse($rows->last()->order_day)->diffInDays(CarbonImmutable::today('Africa/Nairobi')) > 14) {
            return ['available' => false, 'reason' => 'Recent product-level orders are required before a current forecast can be evaluated.'];
        }
        $byDate = $rows->pluck('cases', 'order_day');
        $date = CarbonImmutable::parse($rows->first()->order_day);
        $today = CarbonImmutable::today('Africa/Nairobi');
        $dailyCases = [];
        while ($date->lte($today)) {
            $dailyCases[] = (float) ($byDate[$date->toDateString()] ?? 0);
            $date = $date->addDay();
        }
        $process = new Process([config('services.analytics.python'), base_path('analytics/product_forecast.py')]);
        $process->setInput(json_encode(['daily_cases' => $dailyCases], JSON_THROW_ON_ERROR));
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
                    ['product_id' => $product->id, 'origin_date' => $today->toDateString(), 'horizon_days' => (int) $horizon, 'method' => $method],
                    ['predicted_cases' => $values['predicted_cases'], 'mae_cases' => $values['mae_cases'], 'bias_cases' => $values['bias_cases'], 'evaluation' => ['backtest_points' => $values['backtest_points'], 'selected' => $method === $report['best_method'], 'source' => 'recorded packaged-water orders']]
                );
            }
        }

        return $result;
    }
}
