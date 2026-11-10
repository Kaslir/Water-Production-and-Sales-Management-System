<?php

namespace App\Http\Controllers;

use App\Models\BatchActualCost;
use App\Models\DeliveryFeeCredit;
use App\Models\Invoice;
use App\Models\OperatingExpense;
use App\Models\ProductionBatch;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Services\AuditTrail;
use App\Services\PackagedFinancialSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackagedReportController extends Controller
{
    public function index(Request $request, PackagedFinancialSummary $financial): View
    {
        $dates = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $today = CarbonImmutable::today(config('app.business_timezone'));
        $from = $dates['from'] ?? $today->subDays(29)->toDateString();
        $to = $dates['to'] ?? $today->toDateString();
        $invoices = Invoice::query()->with(['order.customer', 'order.items.product', 'order.dispatch.lines', 'activePayments', 'deliveryFeeCredit'])->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to)->orderByDesc('issued_on')->get();
        $returns = SalesReturn::query()->with(['invoice.order.customer', 'line'])->whereDate('returned_on', '>=', $from)->whereDate('returned_on', '<=', $to)->get();
        $utcStart = CarbonImmutable::parse($from, config('app.business_timezone'))->startOfDay()->utc();
        $utcEnd = CarbonImmutable::parse($to, config('app.business_timezone'))->endOfDay()->utc();
        $deliveryFeeCredits = DeliveryFeeCredit::query()->with('invoice.order.customer')->whereBetween('created_at', [$utcStart, $utcEnd])->get();
        $expenses = OperatingExpense::query()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)->orderByDesc('expense_date')->get();
        $summary = $financial->forPeriod($from, $to, $invoices, $returns, $deliveryFeeCredits, $expenses);
        $actualCostRows = BatchActualCost::query()->with('batch.product')->whereHas('batch', fn ($query) => $query->whereDate('produced_on', '>=', $from)->whereDate('produced_on', '<=', $to))->get();
        $actualCostVariance = (float) $actualCostRows->sum(fn (BatchActualCost $cost) => (float) $cost->actual_total - (float) $cost->batch->total_cost);
        $nearExpiry = ProductionBatch::query()->with('product')->where('cases_on_hand', '>', 0)->whereDate('expires_on', '<=', CarbonImmutable::today(config('app.business_timezone'))->addDays(30))->orderBy('expires_on')->get();
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
        foreach ($deliveryFeeCredits as $credit) {
            $customer = $credit->invoice->order->customer;
            $customerPerformance[$customer->customer_id] ??= ['name' => $customer->name, 'revenue' => 0.0, 'cost' => 0.0, 'delivery' => 0.0];
            $customerPerformance[$customer->customer_id]['revenue'] -= (float) $credit->amount;
        }

        return view('packaged.reports', $summary + compact('from', 'to', 'invoices', 'returns', 'actualCostVariance', 'actualCostRows', 'expenses', 'nearExpiry', 'productPerformance', 'customerPerformance'));
    }

    public function expense(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['expense_date' => ['required', 'date', 'before_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()], 'category' => ['required', 'string', 'max:80'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $expense = OperatingExpense::create($data + ['recorded_by' => $request->user()->user_id]);
        $auditTrail->record('expense.recorded', $expense, $request->user()->user_id, [], ['amount' => $expense->amount, 'category' => $expense->category]);

        return back()->with('status', 'Operating expense recorded.');
    }

    public function reverseExpense(Request $request, OperatingExpense $expense, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if($expense->reversed_at !== null, 422, 'Expense has already been reversed.');
        $expense->update(['reversed_at' => now(), 'reversed_by' => $request->user()->user_id, 'reversal_reason' => $data['reason']]);
        $auditTrail->record('expense.reversed', $expense, $request->user()->user_id, ['amount' => $expense->amount], ['reversed_at' => $expense->reversed_at], $data['reason']);

        return back()->with('status', 'Expense reversed. The original entry remains in the audit trail.');
    }
}
