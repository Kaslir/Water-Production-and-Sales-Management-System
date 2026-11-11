<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Models\SalesOrder;
use App\Services\AppAlertService;
use App\Services\AuditTrail;
use App\Services\ProductForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class ProductForecastController extends Controller
{
    public function index(Request $request, ProductForecastService $service): View
    {
        $tab = $request->validate(['tab' => ['nullable', 'in:forecasts,plans']])['tab'] ?? 'forecasts';
        if ($tab === 'plans' && ! $request->user()->hasRole('administrator', 'manager')) {
            $tab = 'forecasts';
        }
        $products = Product::query()->orderBy('volume_ml')->get();
        $latestForecasts = ProductForecast::query()
            ->where('method', 'random_forest')
            ->orderByDesc('origin_date')->orderByDesc('id')->get()->groupBy('product_id')->map(function ($rows) {
                $latestOrigin = $rows->first()->origin_date->toDateString();

                return $rows->filter(fn ($row) => $row->origin_date->toDateString() === $latestOrigin);
            });
        $latestOrderDate = SalesOrder::query()->whereIn('status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])->max('ordered_on');
        $stock = ProductionBatch::query()->where('status', 'released')->whereDate('expires_on', '>', CarbonImmutable::today(config('app.business_timezone')))->get()->groupBy('product_id')->map(fn ($batches) => $batches->sum(fn ($batch) => $batch->cases_on_hand - $batch->cases_reserved));
        $planning = [];
        foreach ($latestForecasts as $productId => $forecasts) {
            foreach ([7, 30] as $days) {
                $selected = $forecasts->first(fn ($row) => $row->horizon_days === $days && ($row->evaluation['selected'] ?? false));
                if ($days === 7 && $selected && ($selected->evaluation['mode'] ?? null) === 'current' && CarbonImmutable::parse($selected->origin_date)->addDays(7)->gte(CarbonImmutable::today(config('app.business_timezone')))) {
                    $planning[$productId][$days] = $service->recommendation($selected);
                }
            }
        }
        $completedEvaluations = ProductForecast::query()->with('product')
            ->where('horizon_days', 7)->where('method', 'random_forest')->orderByDesc('origin_date')->orderByDesc('id')->limit(80)->get()
            ->unique(fn ($forecast) => $forecast->product_id.'-'.$forecast->origin_date->toDateString())
            ->filter(fn ($forecast) => ($forecast->evaluation['selected'] ?? false)
                && ($forecast->evaluation['mode'] ?? null) === 'current'
                && ($forecast->evaluation['week_start'] ?? null)
                && CarbonImmutable::parse($forecast->origin_date)->addDays(7)->lt(CarbonImmutable::today(config('app.business_timezone'))))
            ->map(function ($forecast) {
                $weekStart = CarbonImmutable::parse($forecast->origin_date)->addDay();
                $weekEnd = $weekStart->addDays(6);
                $hasWeeklyOrders = SalesOrder::query()->whereIn('status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
                    ->whereDate('ordered_on', '>=', $weekStart->toDateString())
                    ->whereDate('ordered_on', '<=', $weekEnd->toDateString())->exists();
                if (! $hasWeeklyOrders) {
                    return null;
                }
                $actualCases = (int) DB::table('sales_order_items')
                    ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                    ->where('sales_order_items.product_id', $forecast->product_id)
                    ->whereIn('sales_orders.status', ['confirmed', 'dispatched', 'delivery_failed', 'delivered', 'invoiced', 'paid'])
                    ->whereDate('sales_orders.ordered_on', '>=', $weekStart->toDateString())
                    ->whereDate('sales_orders.ordered_on', '<=', $weekEnd->toDateString())
                    ->sum('sales_order_items.cases_ordered');

                return ['product' => $forecast->product->name, 'week_start' => $weekStart, 'week_end' => $weekEnd, 'predicted_cases' => (float) $forecast->predicted_cases, 'actual_cases' => $actualCases, 'error_cases' => (float) $forecast->predicted_cases - $actualCases];
            })->filter()->take(12);

        return view('packaged.forecasts', compact('tab', 'products', 'latestForecasts', 'latestOrderDate', 'stock', 'planning', 'completedEvaluations'));
    }

    public function run(Request $request, ProductForecastService $service, AuditTrail $auditTrail, AppAlertService $alerts): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:current,historical_demo']]);
        $products = Product::query()->orderBy('volume_ml')->get();
        set_time_limit(max(120, (int) config('services.analytics.timeout', 120) + 60));
        try {
            $results = $service->runAll($products, $data['mode'] === 'historical_demo');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['forecast' => $exception->getMessage()]);
        }
        $failures = [];
        $generated = 0;
        foreach ($products as $product) {
            $result = $results[$product->id] ?? [];
            $available = collect($result)->contains(fn ($report) => is_array($report) && ($report['available'] ?? false));
            if ($available) {
                $generated++;
                $auditTrail->record('forecast.generated', $product, $request->user()->user_id, [], ['horizons' => [7, 30]]);
            }
            if (! $available) {
                $failures[] = $product->name.': '.($result['reason'] ?? $result['7']['reason'] ?? 'More dated product orders are needed.');
            }
        }
        if ($generated > 0 && $data['mode'] === 'current') {
            $alerts->scanIfDue(true);
        }

        $response = redirect()->route('packaged.forecasts.index', ['tab' => 'forecasts']);

        if ($failures !== [] || $generated === 0) {
            return $response->withErrors(['forecast' => $generated.' of '.$products->count().' forecasts updated. '.implode(' ', $failures)]);
        }

        return $response->with('status', 'Success!');
    }

    public function decision(Request $request, ProductForecast $forecast, AuditTrail $auditTrail, ProductForecastService $service, AppAlertService $alerts): RedirectResponse
    {
        abort_unless(
            $forecast->horizon_days === 7
            && $forecast->method === 'random_forest'
            && (bool) ($forecast->evaluation['selected'] ?? false)
            && ($forecast->evaluation['mode'] ?? null) === 'current'
            && $forecast->origin_date->lt(CarbonImmutable::today(config('app.business_timezone')))
            && CarbonImmutable::parse($forecast->origin_date)->addDays(7)->gte(CarbonImmutable::today(config('app.business_timezone'))),
            422
        );
        $data = $request->validate([
            'approved_cases' => ['required', 'integer', 'min:0'],
            'planned_for' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString(), 'before_or_equal:'.CarbonImmutable::parse($forecast->origin_date)->addDays(7)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($data, $forecast, $request, $auditTrail, $alerts, $service): void {
            // Serialize decisions for the same product, including approvals of different forecast runs.
            DB::table('products')->where('id', $forecast->product_id)->lockForUpdate()->first();
            $forecast = ProductForecast::query()->lockForUpdate()->findOrFail($forecast->id);
            if (ProductionForecastDecision::query()->where('product_forecast_id', $forecast->id)->exists()) {
                throw ValidationException::withMessages(['forecast' => 'This forecast has already been approved. Generate a new forecast to revise the plan.']);
            }
            $currentRecommendation = $service->recommendation($forecast);
            $data['recommended_cases'] = $currentRecommendation['recommended_cases'] + $currentRecommendation['planned_cases'];
            ProductionForecastDecision::query()->where('plan_status', 'planned')
                ->whereHas('forecast', fn ($query) => $query->where('product_id', $forecast->product_id))
                ->update(['plan_status' => 'superseded']);
            $decision = ProductionForecastDecision::create($data + ['product_forecast_id' => $forecast->id, 'decided_by' => $request->user()->user_id, 'plan_status' => 'planned', 'forecast_predicted_cases' => $forecast->predicted_cases, 'forecast_mae_cases' => $forecast->mae_cases, 'forecast_bias_cases' => $forecast->bias_cases, 'forecast_evaluation' => $forecast->evaluation]);
            $auditTrail->record('forecast.decision_recorded', $decision, $request->user()->user_id, [], ['recommended_cases' => $decision->recommended_cases, 'approved_cases' => $decision->approved_cases, 'planned_for' => $decision->planned_for->toDateString()], $decision->reason);
            $alerts->publish('plan:approved:'.$decision->id, 'plan', 'warning', 'Production plan approved', $forecast->product->name.' has an approved plan for '.$decision->approved_cases.' cases on '.$decision->planned_for->format('d M Y').'.', 'plan', $decision->id, ['administrator', 'manager', 'production_staff']);
        });

        return back()->with('status', 'Production decision recorded. Record the resulting batch after production.');
    }
}
