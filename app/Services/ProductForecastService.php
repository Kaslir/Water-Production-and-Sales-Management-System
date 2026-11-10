<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionForecastDecision;
use App\Models\TechnicalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProductForecastService
{
    public function recommendation(ProductForecast $forecast): array
    {
        $productId = $forecast->product_id;
        $horizon = $forecast->horizon_days;
        $today = CarbonImmutable::today(config('app.business_timezone'));
        $windowStart = CarbonImmutable::parse($forecast->origin_date)->addDay();
        $windowEnd = CarbonImmutable::parse($forecast->origin_date)->addDays($horizon);
        $eligibleStock = (int) DB::table('production_batches')
            ->where('product_id', $productId)
            ->where('status', 'released')
            ->whereDate('expires_on', '>', $windowEnd->toDateString())
            ->selectRaw('COALESCE(SUM(cases_on_hand - cases_reserved), 0) available')->value('available');
        $confirmedCases = (int) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.status', 'confirmed')
            ->where(fn ($query) => $query->whereNull('sales_orders.requested_delivery_on')->orWhereBetween('sales_orders.requested_delivery_on', [$windowStart->toDateString(), $windowEnd->toDateString()]))
            ->sum('sales_order_items.cases_ordered');
        $reservedConfirmedCases = (int) DB::table('batch_reservations')
            ->join('sales_order_items', 'batch_reservations.sales_order_item_id', '=', 'sales_order_items.id')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.status', 'confirmed')
            ->where(fn ($query) => $query->whereNull('sales_orders.requested_delivery_on')->orWhereBetween('sales_orders.requested_delivery_on', [$windowStart->toDateString(), $windowEnd->toDateString()]))
            ->sum('batch_reservations.cases_reserved');
        $unreservedConfirmedCases = max(0, $confirmedCases - $reservedConfirmedCases);
        $recordedCases = (int) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $productId)
            ->whereIn('sales_orders.status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
            ->whereDate('sales_orders.ordered_on', '>=', $windowStart->toDateString())
            ->whereDate('sales_orders.ordered_on', '<=', min($today->toDateString(), $windowEnd->toDateString()))
            ->sum('sales_order_items.cases_ordered');
        $remainingForecast = max(0, (float) $forecast->predicted_cases - $recordedCases);
        $bufferCases = (int) ceil(max(0, (float) $forecast->mae_cases));
        $demandToCover = $remainingForecast + $bufferCases + $unreservedConfirmedCases;
        $plannedCases = ProductionForecastDecision::query()
            ->whereHas('forecast', fn ($query) => $query->where('product_id', $productId))
            ->where('plan_status', 'planned')
            ->whereDate('planned_for', '>=', $today->toDateString())
            ->whereDate('planned_for', '<=', $windowEnd->toDateString())
            ->get()->sum(fn (ProductionForecastDecision $plan) => max(0, $plan->approved_cases - $plan->cases_completed));

        return ['eligible_stock' => $eligibleStock, 'confirmed_cases' => $confirmedCases, 'unreserved_confirmed_cases' => $unreservedConfirmedCases, 'recorded_cases' => $recordedCases, 'remaining_forecast_cases' => $remainingForecast, 'buffer_cases' => $bufferCases, 'planned_cases' => $plannedCases, 'recommended_cases' => max(0, (int) ceil($demandToCover - $eligibleStock - $plannedCases))];
    }

    public function run(Product $product, bool $historical = false): array
    {
        $prepared = $this->prepare($product, $historical);
        if (! $prepared['available']) {
            return $prepared;
        }

        $result = $this->execute([
            'daily_cases' => $prepared['daily_cases'],
            'start_date' => $prepared['start_date'],
        ], [$product->id]);
        $this->store($product, $result, $prepared['origin_date'], $historical, $prepared['data_origin']);

        return $result;
    }

    public function runAll(iterable $products, bool $historical = false): array
    {
        $preparedProducts = [];
        $payloads = [];
        $results = [];
        foreach ($products as $product) {
            $prepared = $this->prepare($product, $historical);
            if (! $prepared['available']) {
                $results[$product->id] = $prepared;

                continue;
            }

            $preparedProducts[$product->id] = ['product' => $product, 'origin_date' => $prepared['origin_date'], 'data_origin' => $prepared['data_origin']];
            $payloads[$product->id] = [
                'daily_cases' => $prepared['daily_cases'],
                'start_date' => $prepared['start_date'],
            ];
        }

        if ($preparedProducts !== []) {
            $reports = $this->execute(['products' => $payloads], array_keys($preparedProducts));
            foreach ($preparedProducts as $productId => $prepared) {
                $report = $reports[$productId] ?? ['available' => false, 'reason' => 'Python did not return a forecast for this product.'];
                if (isset($reports[$productId])) {
                    $this->store($prepared['product'], $report, $prepared['origin_date'], $historical, $prepared['data_origin']);
                }
                $results[$productId] = $report;
            }
        }

        return $results;
    }

    private function prepare(Product $product, bool $historical): array
    {
        $rows = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $product->id)
            ->whereIn('sales_orders.status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
            ->selectRaw('DATE(sales_orders.ordered_on) order_day, SUM(sales_order_items.cases_ordered) cases')
            ->groupByRaw('DATE(sales_orders.ordered_on)')
            ->orderBy('order_day')
            ->get();
        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'No dated orders have been recorded for this product.'];
        }
        $originTypes = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_order_items.product_id', $product->id)
            ->whereIn('sales_orders.status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
            ->distinct()->pluck('sales_orders.data_origin')->all();
        $dataOrigin = count($originTypes) === 1 ? $originTypes[0] : 'mixed';
        $lastOrderDate = CarbonImmutable::parse($rows->last()->order_day, config('app.business_timezone'))->startOfDay();
        if (! $historical && $lastOrderDate->diffInDays(CarbonImmutable::today(config('app.business_timezone'))) > 14) {
            return ['available' => false, 'reason' => 'The latest order is too old for a current forecast. Use the historical demonstration or record recent genuine orders.'];
        }
        $byDate = $rows->pluck('cases', 'order_day');
        $date = CarbonImmutable::parse($rows->first()->order_day, config('app.business_timezone'))->startOfDay();
        $today = CarbonImmutable::today(config('app.business_timezone'));
        $asOfDate = $historical ? $lastOrderDate : $today;
        $daysSinceSunday = $asOfDate->dayOfWeek === 0 && ! $historical ? 7 : $asOfDate->dayOfWeek;
        $originDate = $asOfDate->subDays($daysSinceSunday);
        if ($originDate->lt($date)) {
            return ['available' => false, 'reason' => 'A complete Sunday history is needed for weekly evaluation.'];
        }
        if (! $historical) {
            $recentStart = $originDate->subDays(13)->max($date);
            $recordedDays = DB::table('sales_orders')
                ->whereIn('status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
                ->whereDate('ordered_on', '>=', $recentStart->toDateString())
                ->whereDate('ordered_on', '<=', $originDate->toDateString())
                ->distinct()->pluck('ordered_on')->map(fn ($day) => CarbonImmutable::parse($day)->toDateString())->flip();
            $closedDays = DB::table('daily_order_closures')
                ->whereDate('order_day', '>=', $recentStart->toDateString())
                ->whereDate('order_day', '<=', $originDate->toDateString())
                ->pluck('order_day')->map(fn ($day) => CarbonImmutable::parse($day)->toDateString())->flip();
            for ($reviewDay = $recentStart; $reviewDay->lte($originDate); $reviewDay = $reviewDay->addDay()) {
                if ($reviewDay->isSunday()) {
                    continue;
                }
                $dayKey = $reviewDay->toDateString();
                if (! $recordedDays->has($dayKey) && ! $closedDays->has($dayKey)) {
                    return ['available' => false, 'reason' => 'Sales records for '.$reviewDay->format('d M Y').' are not closed. Record orders or confirm that there were no orders that day.'];
                }
            }
        }
        $dailyCases = [];
        while ($date->lte($originDate)) {
            $dailyCases[] = (float) ($byDate[$date->toDateString()] ?? 0);
            $date = $date->addDay();
        }

        return [
            'available' => true,
            'origin_date' => $originDate->toDateString(),
            'start_date' => CarbonImmutable::parse($rows->first()->order_day)->toDateString(),
            'daily_cases' => $dailyCases,
            'data_origin' => $dataOrigin,
        ];
    }

    private function execute(array $payload, array $productIds): array
    {
        $processEnvironment = PHP_OS_FAMILY === 'Windows'
            ? ['SystemRoot' => getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows']
            : null;
        $process = new Process([config('services.analytics.python'), base_path('analytics/product_forecast.py')], null, $processEnvironment);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            Log::error('Product forecasting process failed.', [
                'product_ids' => $productIds,
                'python' => config('services.analytics.python'),
                'exit_code' => $process->getExitCode(),
                'stderr' => substr(trim($process->getErrorOutput()), 0, 4000),
            ]);
            TechnicalEvent::create(['event_type' => 'ProductForecastProcessFailure', 'source' => 'product-forecast', 'occurred_at' => now()]);
            throw new RuntimeException('Product forecasting could not run. Check the Laravel log for the Python process error.');
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function store(Product $product, array $result, string $originDate, bool $historical, string $dataOrigin): void
    {
        foreach ($result as $horizon => $report) {
            if (! ($report['available'] ?? false)) {
                continue;
            }
            foreach ($report['methods'] as $method => $values) {
                ProductForecast::create([
                    'product_id' => $product->id, 'origin_date' => $originDate, 'horizon_days' => (int) $horizon, 'method' => $method,
                    'predicted_cases' => $values['predicted_cases'], 'mae_cases' => $values['mae_cases'], 'bias_cases' => $values['bias_cases'],
                    'evaluation' => ['backtest_points' => $values['backtest_points'], 'selected' => $method === $report['best_method'], 'mode' => $historical ? 'historical_demo' : 'current', 'source' => 'recorded packaged-water orders', 'data_origin' => $dataOrigin, 'correction_cases' => $values['correction_cases'] ?? 0, 'week_start' => $report['week_start'] ?? null, 'week_end' => $report['week_end'] ?? null],
                ]);
            }
        }
    }
}
