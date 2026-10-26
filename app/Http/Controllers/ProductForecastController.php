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
            ->whereIn('method', ['random_forest', 'gradient_boosting'])
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
                if ($selected && $selected->origin_date->isToday() && ($selected->evaluation['mode'] ?? null) === 'current') {
                    $planning[$productId][$days] = $service->recommendation($selected);
                }
            }
        }

        return view('packaged.forecasts', compact('products', 'latestForecasts', 'latestOrderDate', 'stock', 'nearExpiry', 'planning'));
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
            in_array($forecast->method, ['random_forest', 'gradient_boosting'], true)
            && (bool) ($forecast->evaluation['selected'] ?? false)
            && ($forecast->evaluation['mode'] ?? null) === 'current'
            && $forecast->origin_date->isToday(),
            422
        );
        $data = $request->validate([
            'approved_cases' => ['required', 'integer', 'min:0'],
            'planned_for' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays($forecast->horizon_days)->toDateString()],
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
