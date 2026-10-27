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
    public function index(ProductForecastService $service): View
    {
        $products = Product::query()->orderBy('volume_ml')->get();
        $latestForecasts = ProductForecast::query()
            ->whereIn('method', ['previous_week', 'random_forest', 'gradient_boosting'])
            ->orderByDesc('origin_date')->get()->groupBy('product_id')->map(function ($rows) {
                $latestOrigin = $rows->first()->origin_date->toDateString();

                return $rows->filter(fn ($row) => $row->origin_date->toDateString() === $latestOrigin);
            });
        $latestOrderDate = SalesOrder::max('ordered_on');
        $stock = ProductionBatch::query()->where('status', 'released')->whereDate('expires_on', '>', today())->get()->groupBy('product_id')->map(fn ($batches) => $batches->sum(fn ($batch) => $batch->cases_on_hand - $batch->cases_reserved));
        $nearExpiry = ProductionBatch::query()->with('product')->where('status', 'released')->where('cases_on_hand', '>', 0)
            ->whereDate('expires_on', '>=', today())->whereDate('expires_on', '<=', today()->addDays(30))->orderBy('expires_on')->get();
        $planning = [];
        foreach ($latestForecasts as $productId => $forecasts) {
            foreach ([7, 30] as $days) {
                $selected = $forecasts->first(fn ($row) => $row->horizon_days === $days && ($row->evaluation['selected'] ?? false));
                if ($days === 7 && $selected && ($selected->evaluation['mode'] ?? null) === 'current' && CarbonImmutable::parse($selected->origin_date)->addDays(7)->gte(today())) {
                    $planning[$productId][$days] = $service->recommendation($selected);
                }
            }
        }
        $completedEvaluations = ProductForecast::query()->with('product')
            ->where('horizon_days', 7)->orderByDesc('origin_date')->limit(80)->get()
            ->filter(fn ($forecast) => ($forecast->evaluation['selected'] ?? false)
                && ($forecast->evaluation['mode'] ?? null) === 'current'
                && ($forecast->evaluation['week_start'] ?? null)
                && CarbonImmutable::parse($forecast->origin_date)->addDays(7)->lt(today()))
            ->map(function ($forecast) {
                $weekStart = CarbonImmutable::parse($forecast->origin_date)->addDay();
                $weekEnd = $weekStart->addDays(6);
                $hasWeeklyOrders = SalesOrder::query()->where('status', '!=', 'cancelled')
                    ->whereDate('ordered_on', '>=', $weekStart->toDateString())
                    ->whereDate('ordered_on', '<=', $weekEnd->toDateString())->exists();
                if (! $hasWeeklyOrders) {
                    return null;
                }
                $actualCases = (int) DB::table('sales_order_items')
                    ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                    ->where('sales_order_items.product_id', $forecast->product_id)
                    ->where('sales_orders.status', '!=', 'cancelled')
                    ->whereDate('sales_orders.ordered_on', '>=', $weekStart->toDateString())
                    ->whereDate('sales_orders.ordered_on', '<=', $weekEnd->toDateString())
                    ->sum('sales_order_items.cases_ordered');

                return ['product' => $forecast->product->name, 'week_start' => $weekStart, 'week_end' => $weekEnd, 'predicted_cases' => (float) $forecast->predicted_cases, 'actual_cases' => $actualCases, 'error_cases' => (float) $forecast->predicted_cases - $actualCases];
            })->filter()->take(12);

        return view('packaged.forecasts', compact('products', 'latestForecasts', 'latestOrderDate', 'stock', 'nearExpiry', 'planning', 'completedEvaluations'));
    }

    public function run(Request $request, ProductForecastService $service, AuditTrail $auditTrail, AppAlertService $alerts): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id'], 'mode' => ['required', 'in:current,historical_demo']]);
        $product = Product::findOrFail($data['product_id']);
        try {
            $result = $service->run($product, $data['mode'] === 'historical_demo');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['forecast' => $exception->getMessage()]);
        }
        if (! collect($result)->contains(fn ($report) => $report['available'] ?? false)) {
            $reason = $result['reason'] ?? ($result['7']['reason'] ?? 'More dated product orders are needed before this forecast can be evaluated.');
            throw ValidationException::withMessages(['forecast' => $reason]);
        }
        $auditTrail->record('forecast.generated', $product, $request->user()->user_id, [], ['horizons' => [7, 30]]);
        if ($data['mode'] === 'current') {
            $alerts->scanIfDue(true);
        }

        return back()->with('status', ($data['mode'] === 'historical_demo' ? 'Historical demonstration' : 'Current forecast').' and chronological model comparison updated for '.$product->name.'.');
    }

    public function decision(Request $request, ProductForecast $forecast, AuditTrail $auditTrail, ProductForecastService $service, AppAlertService $alerts): RedirectResponse
    {
        abort_unless(
            $forecast->horizon_days === 7
            && in_array($forecast->method, ['previous_week', 'random_forest', 'gradient_boosting'], true)
            && (bool) ($forecast->evaluation['selected'] ?? false)
            && ($forecast->evaluation['mode'] ?? null) === 'current'
            && $forecast->origin_date->lt(today())
            && CarbonImmutable::parse($forecast->origin_date)->addDays(7)->gte(today()),
            422
        );
        $data = $request->validate([
            'approved_cases' => ['required', 'integer', 'min:0'],
            'planned_for' => ['required', 'date', 'after_or_equal:'.today()->toDateString(), 'before_or_equal:'.CarbonImmutable::parse($forecast->origin_date)->addDays(7)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $currentRecommendation = $service->recommendation($forecast);
        $data['recommended_cases'] = $currentRecommendation['recommended_cases'] + $currentRecommendation['planned_cases'];
        if ($data['recommended_cases'] !== (int) $data['approved_cases']) {
            $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        }
        DB::transaction(function () use ($data, $forecast, $request, $auditTrail, $alerts): void {
            ProductionForecastDecision::query()->where('plan_status', 'planned')
                ->whereHas('forecast', fn ($query) => $query->where('product_id', $forecast->product_id))
                ->update(['plan_status' => 'superseded']);
            $decision = ProductionForecastDecision::create($data + ['product_forecast_id' => $forecast->id, 'decided_by' => $request->user()->user_id, 'plan_status' => 'planned']);
            $auditTrail->record('forecast.decision_recorded', $decision, $request->user()->user_id, [], ['recommended_cases' => $decision->recommended_cases, 'approved_cases' => $decision->approved_cases, 'planned_for' => $decision->planned_for->toDateString()], $decision->reason);
            $alerts->publish('plan:approved:'.$decision->id, 'plan', 'warning', 'Production plan approved', $forecast->product->name.' has an approved plan for '.$decision->approved_cases.' cases on '.$decision->planned_for->format('d M Y').'.', 'plan', $decision->id, ['administrator', 'manager', 'production_staff']);
        });

        return back()->with('status', 'Production decision recorded. Record the resulting batch after production.');
    }
}
