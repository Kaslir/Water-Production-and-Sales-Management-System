<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\ProductionForecastDecision;
use App\Services\AuditTrail;
use App\Services\ProductForecastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class ProductForecastController extends Controller
{
    public function index(ProductForecastService $service): View
    {
        $products = Product::query()->orderBy('volume_ml')->get();
        $latestForecasts = ProductForecast::query()->whereDate('origin_date', today())
            ->whereIn('method', ['moving_average', 'random_forest', 'gradient_boosting'])
            ->get()->groupBy('product_id');
        $stock = ProductionBatch::query()->where('status', 'released')->whereDate('expires_on', '>', today())->get()->groupBy('product_id')->map(fn ($batches) => $batches->sum(fn ($batch) => $batch->cases_on_hand - $batch->cases_reserved));
        $nearExpiry = ProductionBatch::query()->with('product')->where('cases_on_hand', '>', 0)->whereDate('expires_on', '<=', today()->addDays(30))->orderBy('expires_on')->get();
        $planning = [];
        foreach ($latestForecasts as $productId => $forecasts) {
            foreach ([7, 30] as $days) {
                $selected = $forecasts->first(fn ($row) => $row->horizon_days === $days && ($row->evaluation['selected'] ?? false));
                if ($selected) {
                    $planning[$productId][$days] = $service->recommendation($selected);
                }
            }
        }

        return view('packaged.forecasts', compact('products', 'latestForecasts', 'stock', 'nearExpiry', 'planning'));
    }

    public function run(Request $request, ProductForecastService $service, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id']]);
        $product = Product::findOrFail($data['product_id']);
        try {
            $result = $service->run($product);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['forecast' => $exception->getMessage()]);
        }
        if (! collect($result)->contains(fn ($report) => $report['available'] ?? false)) {
            $reason = $result['reason'] ?? ($result['7']['reason'] ?? 'More dated product orders are needed before this forecast can be evaluated.');
            throw ValidationException::withMessages(['forecast' => $reason]);
        }
        $auditTrail->record('forecast.generated', $product, $request->user()->user_id, [], ['horizons' => [7, 30]]);

        return back()->with('status', 'Forecast and chronological model comparison updated for '.$product->name.'.');
    }

    public function decision(Request $request, ProductForecast $forecast, AuditTrail $auditTrail, ProductForecastService $service): RedirectResponse
    {
        abort_unless(
            in_array($forecast->method, ['moving_average', 'random_forest', 'gradient_boosting'], true)
            && (bool) ($forecast->evaluation['selected'] ?? false)
            && $forecast->origin_date->isToday(),
            422
        );
        $data = $request->validate([
            'approved_cases' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['recommended_cases'] = $service->recommendation($forecast)['recommended_cases'];
        if ($data['recommended_cases'] !== (int) $data['approved_cases']) {
            $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        }
        $decision = ProductionForecastDecision::create($data + ['product_forecast_id' => $forecast->id, 'decided_by' => $request->user()->user_id]);
        $auditTrail->record('forecast.decision_recorded', $decision, $request->user()->user_id, [], ['recommended_cases' => $decision->recommended_cases, 'approved_cases' => $decision->approved_cases], $decision->reason);

        return back()->with('status', 'Production decision recorded. Record the resulting batch after production.');
    }
}
