<?php

namespace App\Http\Controllers;

use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $data = [
            'availableCases' => ProductionBatch::where('status', 'released')->whereDate('expires_on', '>=', today())->selectRaw('COALESCE(SUM(cases_on_hand - cases_reserved), 0) as total')->value('total'),
            'producedCases' => ProductionBatch::sum('cases_produced'),
            'dispatchedCases' => DispatchLine::sum('cases'),
            'revenue' => Invoice::sum('amount'),
            'orders' => SalesOrder::with('customer')->orderByDesc('ordered_on')->orderByDesc('id')->take(6)->get(),
            'recentBatches' => ProductionBatch::with('product')->orderByDesc('produced_on')->orderByDesc('id')->take(4)->get(),
            'salesChart' => Invoice::selectRaw('issued_on, SUM(amount) as total')->groupBy('issued_on')->orderByDesc('issued_on')->limit(30)->get()->sortBy('issued_on')->values(),
            'productStock' => DB::table('products')->leftJoin('production_batches', function ($join) {
                $join->on('products.id', '=', 'production_batches.product_id')
                    ->where('production_batches.status', '=', 'released')
                    ->where('production_batches.expires_on', '>=', today()->toDateString());
            })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get(),
        ];

        return view('dashboard', compact('data'));
    }
}
