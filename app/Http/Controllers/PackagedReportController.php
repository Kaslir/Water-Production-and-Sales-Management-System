<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\ProductionBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PackagedReportController extends Controller
{
    public function index(Request $request): View
    {
        $dates = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $dates['from'] ?? today()->startOfMonth()->toDateString();
        $to = $dates['to'] ?? today()->toDateString();
        $invoices = Invoice::query()->with(['order.customer', 'order.items.product', 'order.dispatch.lines', 'payments'])->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to)->orderByDesc('issued_on')->get();
        $returns = SalesReturn::query()->with(['invoice.order.customer', 'line'])->whereDate('returned_on', '>=', $from)->whereDate('returned_on', '<=', $to)->get();
        $credits = (float) $returns->sum('credit_amount');
        $revenue = (float) $invoices->sum('amount') - $credits;
        $costOfGoodsSold = (float) DB::table('dispatch_lines')
            ->join('dispatches', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
            ->join('invoices', 'dispatches.sales_order_id', '=', 'invoices.sales_order_id')
            ->whereDate('invoices.issued_on', '>=', $from)->whereDate('invoices.issued_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(dispatch_lines.cases * dispatch_lines.cost_per_case), 0) total')
            ->value('total');
        $deliveryCost = (float) SalesOrder::query()->whereHas('invoice', fn ($query) => $query->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to))->sum('delivery_cost');
        $operatingExpenses = (float) OperatingExpense::query()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)->sum('amount');
        $disposalLoss = (float) DB::table('batch_disposals')
            ->join('production_batches', 'batch_disposals.production_batch_id', '=', 'production_batches.id')
            ->whereDate('batch_disposals.disposed_on', '>=', $from)->whereDate('batch_disposals.disposed_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(batch_disposals.cases * production_batches.cost_per_case), 0) total')
            ->value('total');
        $cashCollected = (float) Payment::query()->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->sum('amount');
        $outstanding = (float) Invoice::sum('amount') - (float) SalesReturn::sum('credit_amount') - (float) Payment::sum('amount');
        $grossProfit = $revenue - $costOfGoodsSold;
        $operatingProfit = $grossProfit - $deliveryCost - $operatingExpenses - $disposalLoss;
        $nearExpiry = ProductionBatch::query()->with('product')->where('cases_on_hand', '>', 0)->whereDate('expires_on', '<=', today()->addDays(30))->orderBy('expires_on')->get();
        $productPerformance = [];
        $customerPerformance = [];
        foreach ($invoices as $invoice) {
            $order = $invoice->order;
            $subtotal = (float) $order->items->sum('line_total');
            $customerId = $order->customer_id;
            $customerPerformance[$customerId] ??= ['name' => $order->customer->name, 'revenue' => 0.0, 'cost' => 0.0, 'delivery' => 0.0];
            $customerPerformance[$customerId]['revenue'] += (float) $invoice->amount;
            $customerPerformance[$customerId]['delivery'] += (float) $order->delivery_cost;
            foreach ($order->items as $item) {
                $productPerformance[$item->product_id] ??= ['name' => $item->product->name, 'cases' => 0, 'revenue' => 0.0, 'cost' => 0.0];
                $itemRevenue = (float) $item->line_total - ($subtotal > 0 ? (float) $order->discount * (float) $item->line_total / $subtotal : 0);
                $itemCost = (float) $order->dispatch->lines->where('sales_order_item_id', $item->id)->sum(fn ($line) => $line->cases * $line->cost_per_case);
                $productPerformance[$item->product_id]['cases'] += $item->cases_ordered;
                $productPerformance[$item->product_id]['revenue'] += $itemRevenue;
                $productPerformance[$item->product_id]['cost'] += $itemCost;
                $customerPerformance[$customerId]['cost'] += $itemCost;
            }
        }
        foreach ($returns as $return) {
            $item = SalesOrderItem::findOrFail($return->line->sales_order_item_id);
            $productId = $item->product_id;
            $customerId = $return->invoice->order->customer_id;
            $productPerformance[$productId] ??= ['name' => $item->product->name, 'cases' => 0, 'revenue' => 0.0, 'cost' => 0.0];
            $customerPerformance[$customerId] ??= ['name' => $return->invoice->order->customer->name, 'revenue' => 0.0, 'cost' => 0.0, 'delivery' => 0.0];
            $productPerformance[$productId]['cases'] -= $return->cases;
            $productPerformance[$productId]['revenue'] -= (float) $return->credit_amount;
            $customerPerformance[$customerId]['revenue'] -= (float) $return->credit_amount;
        }

        return view('packaged.reports', compact('from', 'to', 'invoices', 'returns', 'credits', 'revenue', 'costOfGoodsSold', 'deliveryCost', 'operatingExpenses', 'disposalLoss', 'cashCollected', 'outstanding', 'grossProfit', 'operatingProfit', 'nearExpiry', 'productPerformance', 'customerPerformance'));
    }

    public function expense(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['expense_date' => ['required', 'date', 'before_or_equal:today'], 'category' => ['required', 'string', 'max:80'], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $expense = OperatingExpense::create($data + ['recorded_by' => $request->user()->user_id]);
        $auditTrail->record('expense.recorded', $expense, $request->user()->user_id, [], ['amount' => $expense->amount, 'category' => $expense->category]);

        return back()->with('status', 'Operating expense recorded.');
    }
}
