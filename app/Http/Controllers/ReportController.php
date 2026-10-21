<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:sales,production,inventory,distribution,customers,costs,expiry,disposals'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $type = $filters['type'] ?? 'sales';
        $lastRecord = collect([
            DB::table('invoices')->max('issued_on'),
            DB::table('production_batches')->max('produced_on'),
            DB::table('dispatches')->max('dispatched_on'),
        ])->filter()->max();
        $to = $filters['to'] ?? ($lastRecord ?: today()->toDateString());
        $from = $filters['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();

        [$query, $columns, $summaryFields] = match ($type) {
            'production' => [
                DB::table('production_batches')->join('products', 'production_batches.product_id', '=', 'products.id')
                    ->whereBetween('production_batches.produced_on', [$from, $to])
                    ->select('production_batches.id', 'production_batches.batch_code', 'products.name as product', 'production_batches.produced_on', 'production_batches.expires_on', 'production_batches.cases_produced', 'production_batches.status'),
                ['batch_code' => 'Batch', 'product' => 'Product', 'produced_on' => 'Produced', 'expires_on' => 'Expires', 'cases_produced' => 'Cases', 'status' => 'Status'],
                ['cases_produced' => ['Cases produced', 'cases']],
            ],
            'inventory' => [
                DB::table('stock_movements')->join('production_batches', 'stock_movements.production_batch_id', '=', 'production_batches.id')
                    ->join('products', 'production_batches.product_id', '=', 'products.id')
                    ->whereDate('stock_movements.created_at', '>=', $from)->whereDate('stock_movements.created_at', '<=', $to)
                    ->select('stock_movements.id', 'production_batches.batch_code', 'products.name as product', 'stock_movements.created_at', 'stock_movements.reason', 'stock_movements.signed_cases'),
                ['created_at' => 'Recorded', 'batch_code' => 'Batch', 'product' => 'Product', 'reason' => 'Reason', 'signed_cases' => 'Cases (+/-)'],
                ['signed_cases' => ['Net stock movement', 'cases']],
            ],
            'distribution' => [
                DB::table('dispatches')->join('sales_orders', 'dispatches.sales_order_id', '=', 'sales_orders.id')
                    ->join('customers', 'sales_orders.customer_id', '=', 'customers.customer_id')
                    ->join('dispatch_lines', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
                    ->whereBetween('dispatches.dispatched_on', [$from, $to])
                    ->select('dispatches.id', 'dispatches.dispatched_on', 'dispatches.status', 'sales_orders.order_number', 'customers.name as customer')
                    ->selectRaw('SUM(dispatch_lines.cases) as cases')->groupBy('dispatches.id', 'dispatches.dispatched_on', 'dispatches.status', 'sales_orders.order_number', 'customers.name'),
                ['dispatched_on' => 'Dispatched', 'order_number' => 'Order', 'customer' => 'Customer', 'cases' => 'Cases', 'status' => 'Status'],
                ['cases' => ['Cases dispatched', 'cases']],
            ],
            'customers' => [
                DB::table('customers')->leftJoin('sales_orders', function ($join) use ($from, $to) {
                    $join->on('customers.customer_id', '=', 'sales_orders.customer_id')->whereBetween('sales_orders.ordered_on', [$from, $to]);
                })->leftJoin('invoices', 'sales_orders.id', '=', 'invoices.sales_order_id')
                    ->select('customers.customer_id as id', 'customers.name as customer', 'customers.phone_number')
                    ->selectRaw('COUNT(sales_orders.id) as orders, COALESCE(SUM(invoices.amount), 0) as invoiced')
                    ->groupBy('customers.customer_id', 'customers.name', 'customers.phone_number'),
                ['customer' => 'Customer', 'phone_number' => 'Phone', 'orders' => 'Orders', 'invoiced' => 'Invoiced (KSh)'],
                ['orders' => ['Orders placed', 'orders'], 'invoiced' => ['Invoiced sales', 'KSh']],
            ],
            'costs' => [
                DB::table('production_batches')->join('products', 'production_batches.product_id', '=', 'products.id')
                    ->whereBetween('production_batches.produced_on', [$from, $to])
                    ->select('production_batches.id', 'production_batches.batch_code', 'products.name as product', 'production_batches.produced_on', 'production_batches.cases_produced', 'production_batches.total_cost', 'production_batches.cost_per_case'),
                ['batch_code' => 'Batch', 'product' => 'Product', 'produced_on' => 'Produced', 'cases_produced' => 'Cases', 'total_cost' => 'Batch cost (KSh)', 'cost_per_case' => 'Cost / case (KSh)'],
                ['total_cost' => ['Production batch costs', 'KSh']],
            ],
            'expiry' => [
                DB::table('production_batches')->join('products', 'production_batches.product_id', '=', 'products.id')
                    ->whereBetween('production_batches.expires_on', [$from, $to])
                    ->select('production_batches.id', 'production_batches.batch_code', 'products.name as product', 'production_batches.expires_on', 'production_batches.cases_on_hand', 'production_batches.status'),
                ['batch_code' => 'Batch', 'product' => 'Product', 'expires_on' => 'Expires', 'cases_on_hand' => 'Cases on hand', 'status' => 'Status'],
                ['cases_on_hand' => ['Cases still on hand', 'cases']],
            ],
            'disposals' => [
                DB::table('batch_disposals')->join('production_batches', 'batch_disposals.production_batch_id', '=', 'production_batches.id')
                    ->join('products', 'production_batches.product_id', '=', 'products.id')
                    ->whereBetween('batch_disposals.disposed_on', [$from, $to])
                    ->select('batch_disposals.id', 'production_batches.batch_code', 'products.name as product', 'batch_disposals.disposed_on', 'batch_disposals.reason', 'batch_disposals.cases')
                    ->selectRaw('batch_disposals.cases * production_batches.cost_per_case as loss'),
                ['disposed_on' => 'Disposed', 'batch_code' => 'Batch', 'product' => 'Product', 'reason' => 'Reason', 'cases' => 'Cases', 'loss' => 'Cost loss (KSh)'],
                ['cases' => ['Cases disposed', 'cases'], 'loss' => ['Disposal cost loss', 'KSh']],
            ],
            default => [
                DB::table('invoices')->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
                    ->join('customers', 'sales_orders.customer_id', '=', 'customers.customer_id')
                    ->whereBetween('invoices.issued_on', [$from, $to])
                    ->select('invoices.id', 'invoices.invoice_number', 'invoices.issued_on', 'invoices.amount', 'invoices.status', 'sales_orders.order_number', 'customers.name as customer'),
                ['invoice_number' => 'Invoice', 'issued_on' => 'Issued', 'order_number' => 'Order', 'customer' => 'Customer', 'amount' => 'Amount (KSh)', 'status' => 'Status'],
                ['amount' => ['Invoiced sales', 'KSh']],
            ],
        };

        $summaryRows = (clone $query)->get();
        $totals = [];
        foreach ($summaryFields as $field => [$label, $unit]) {
            $totals[] = ['label' => $label, 'value' => $summaryRows->sum($field), 'unit' => $unit];
        }
        $orderColumn = match ($type) {
            'production', 'costs', 'expiry' => 'production_batches.id',
            'inventory' => 'stock_movements.id',
            'distribution' => 'dispatches.id',
            'customers' => 'customers.customer_id',
            'disposals' => 'batch_disposals.id',
            default => 'invoices.id',
        };
        $rows = $query->orderByDesc($orderColumn)->paginate(30)->withQueryString();

        return view('reports.index', compact('type', 'from', 'to', 'rows', 'columns', 'totals'));
    }
}
