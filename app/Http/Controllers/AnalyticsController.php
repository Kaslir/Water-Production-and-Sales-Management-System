<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $period = $request->query('period', 'all');
        if (! in_array($period, ['week', 'month', '3months', '6months', 'year', 'all'], true)) {
            $period = 'all';
        }

        $latestRecorded = collect([
            DB::table('sales_orders')->max('ordered_on'),
            DB::table('invoices')->max('issued_on'),
            DB::table('dispatches')->max('dispatched_on'),
        ])->filter()->max();
        $latestDate = $latestRecorded ? CarbonImmutable::parse($latestRecorded) : null;
        $startDate = $latestDate ? match ($period) {
            'week' => $latestDate->subDays(6),
            'month' => $latestDate->subMonthNoOverflow(),
            '3months' => $latestDate->subMonthsNoOverflow(3),
            '6months' => $latestDate->subMonthsNoOverflow(6),
            'year' => $latestDate->subYearNoOverflow(),
            default => null,
        } : null;

        $demandQuery = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->join('products', 'sales_order_items.product_id', '=', 'products.id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw('sales_orders.ordered_on as day, products.id as product_id, products.name as product_name, SUM(sales_order_items.cases_ordered) as cases, SUM(sales_order_items.cases_ordered * products.bottles_per_case * products.volume_ml / 1000.0) as litres')
            ->groupBy('sales_orders.ordered_on', 'products.id', 'products.name')
            ->orderBy('sales_orders.ordered_on');
        if ($startDate) {
            $demandQuery->whereDate('sales_orders.ordered_on', '>=', $startDate->toDateString());
        }
        $demandByProduct = $demandQuery->get();
        $dailyDemand = $demandByProduct->groupBy('day')->map(fn ($rows, $day) => [
            'day' => $day,
            'cases' => $rows->sum('cases'),
            'litres' => $rows->sum('litres'),
        ])->values();
        $productDemand = $demandByProduct->groupBy('product_id')->map(fn ($rows) => [
            'name' => $rows->first()->product_name,
            'cases' => $rows->sum('cases'),
            'litres' => $rows->sum('litres'),
        ])->values();

        $revenueQuery = DB::table('invoices')->selectRaw('issued_on as day, SUM(amount) as revenue')->groupBy('issued_on')->orderBy('issued_on');
        $dispatchQuery = DB::table('dispatch_lines')->join('dispatches', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
            ->selectRaw('dispatches.dispatched_on as day, SUM(dispatch_lines.cases) as cases')
            ->groupBy('dispatches.dispatched_on')->orderBy('dispatches.dispatched_on');
        if ($startDate) {
            $revenueQuery->whereDate('issued_on', '>=', $startDate->toDateString());
            $dispatchQuery->whereDate('dispatches.dispatched_on', '>=', $startDate->toDateString());
        }
        $dailyRevenue = $revenueQuery->get();
        $dailyDispatches = $dispatchQuery->get();

        return view('analytics.index', [
            'period' => $period,
            'latestDate' => $latestDate,
            'dailyDemand' => $dailyDemand,
            'dailyRevenue' => $dailyRevenue,
            'dailyDispatches' => $dailyDispatches,
            'productDemand' => $productDemand,
            'totalCasesOrdered' => $dailyDemand->sum('cases'),
            'totalLitresOrdered' => $dailyDemand->sum('litres'),
            'totalInvoiced' => $dailyRevenue->sum('revenue'),
            'averageCasesPerRecordedDay' => $dailyDemand->count() ? $dailyDemand->sum('cases') / $dailyDemand->count() : 0,
        ]);
    }
}
