<?php

namespace App\Http\Controllers;

use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Services\PackagedFinancialSummary;
use App\Services\ProductForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PackagedFinancialSummary $financial, ProductForecastService $forecasts): View
    {
        $today = CarbonImmutable::today(config('app.business_timezone'));
        $from = $today->subDays(29)->toDateString();
        $to = $today->toDateString();
        $periodLabel = 'Last 30 days';
        $canSeeFinance = $request->user()->hasRole('administrator', 'manager', 'auditor');
        $canSeeOrders = $request->user()->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff');
        $canSeeBatches = $request->user()->hasRole('administrator', 'manager', 'production_staff', 'inventory_staff');
        $canSeePlanning = $request->user()->hasRole('administrator', 'manager', 'auditor');

        $dailyInvoices = $canSeeFinance
            ? Invoice::query()->selectRaw('issued_on, SUM(amount) as total')->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to)
                ->groupBy('issued_on')->orderBy('issued_on')->get()
            : collect();
        $invoiceTotalsByDay = $dailyInvoices->keyBy(fn ($row) => CarbonImmutable::parse($row->issued_on)->toDateString());
        $salesChart = collect();
        for ($date = $today->subDays(29); $date->lte($today); $date = $date->addDay()) {
            $salesChart->push(['label' => $date->format('d M Y'), 'total' => (float) ($invoiceTotalsByDay->get($date->toDateString())?->total ?? 0)]);
        }

        $data = [
            'availableCases' => ProductionBatch::query()->where('status', 'released')->whereDate('expires_on', '>', $today->toDateString())
                ->selectRaw('COALESCE(SUM(cases_on_hand - cases_reserved), 0) as total')->value('total'),
            'producedCases' => ProductionBatch::query()->whereDate('produced_on', '>=', $from)->whereDate('produced_on', '<=', $to)->sum('cases_produced'),
            'dispatchedCases' => DispatchLine::query()->join('dispatches', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
                ->whereDate('dispatches.dispatched_on', '>=', $from)->whereDate('dispatches.dispatched_on', '<=', $to)->sum('dispatch_lines.cases'),
            'revenue' => $canSeeFinance ? $dailyInvoices->sum('total') : null,
            'orders' => $canSeeOrders ? SalesOrder::query()->with('customer')->orderByDesc('ordered_on')->orderByDesc('id')->take(6)->get() : collect(),
            'recentBatches' => $canSeeBatches ? ProductionBatch::query()->with('product')->orderByDesc('produced_on')->orderByDesc('id')->take(4)->get() : collect(),
            'salesChart' => $salesChart,
            'hasRecentInvoices' => $dailyInvoices->isNotEmpty(),
            'hasDemonstrationInvoices' => $canSeeFinance && Invoice::query()->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to)
                ->whereHas('order', fn ($query) => $query->where('data_origin', 'synthetic'))->exists(),
            'productStock' => DB::table('products')->leftJoin('production_batches', function ($join) use ($today) {
                $join->on('products.id', '=', 'production_batches.product_id')
                    ->where('production_batches.status', '=', 'released')
                    ->where('production_batches.expires_on', '>', $today->toDateString());
            })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')
                ->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get(),
        ];

        $attention = [];
        if ($request->user()->hasRole('administrator', 'manager', 'inventory_staff')) {
            $attention[] = ['label' => 'Batches approaching expiry', 'count' => ProductionBatch::query()->where('status', 'released')->where('cases_on_hand', '>', 0)->whereDate('expires_on', '>', $today->toDateString())->whereDate('expires_on', '<=', $today->addDays(30)->toDateString())->count(), 'route' => route('inventory.index')];
        }
        if ($canSeeBatches) {
            $attention[] = ['label' => 'Batches awaiting quality approval', 'count' => ProductionBatch::query()->where('status', 'quality_hold')->count(), 'route' => route('packaged.batches.index')];
        }
        if ($canSeeOrders) {
            $attention[] = ['label' => 'Confirmed orders awaiting dispatch', 'count' => SalesOrder::query()->where('status', 'confirmed')->count(), 'route' => route('distribution.index')];
        }

        $financialSummary = $canSeeFinance ? $financial->forPeriod($from, $to) : null;
        $planning = collect();
        if ($canSeePlanning) {
            $planning = ProductForecast::query()->with('product')->where('method', 'random_forest')->where('horizon_days', 7)
                ->whereDate('origin_date', '<', $today->toDateString())->whereDate('origin_date', '>=', $today->subDays(7)->toDateString())
                ->orderByDesc('origin_date')->get()
                ->filter(fn (ProductForecast $forecast) => ($forecast->evaluation['selected'] ?? false)
                    && ($forecast->evaluation['mode'] ?? null) === 'current'
                    && $forecast->origin_date->addDays(7)->gte($today))
                ->unique('product_id')->map(fn (ProductForecast $forecast) => [
                    'product' => $forecast->product->name,
                    'recommendedCases' => $forecasts->recommendation($forecast)['recommended_cases'],
                ])->values();
        }

        return view('dashboard', compact('data', 'periodLabel', 'financialSummary', 'attention', 'planning', 'canSeeFinance', 'canSeeOrders', 'canSeeBatches', 'canSeePlanning'));
    }
}
