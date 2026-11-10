<?php

namespace App\Services;

use App\Models\CustomerRefund;
use App\Models\DeliveryFeeCredit;
use App\Models\DeliveryTrip;
use App\Models\Invoice;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackagedFinancialSummary
{
    public function forPeriod(string $from, string $to, ?Collection $invoices = null, ?Collection $returns = null, ?Collection $deliveryFeeCredits = null, ?Collection $expenses = null): array
    {
        $utcStart = CarbonImmutable::parse($from, config('app.business_timezone'))->startOfDay()->utc();
        $utcEnd = CarbonImmutable::parse($to, config('app.business_timezone'))->endOfDay()->utc();
        $invoices ??= Invoice::query()->with('order')->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to)->get();
        $returns ??= SalesReturn::query()->whereDate('returned_on', '>=', $from)->whereDate('returned_on', '<=', $to)->get();
        $deliveryFeeCredits ??= DeliveryFeeCredit::query()->whereBetween('created_at', [$utcStart, $utcEnd])->get();
        $expenses ??= OperatingExpense::query()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)->get();

        $credits = (float) $returns->sum('credit_amount');
        $deliveryFeeCreditsTotal = (float) $deliveryFeeCredits->sum('amount');
        $deliveryFeeRevenue = (float) $invoices->sum(fn (Invoice $invoice) => (float) $invoice->order->delivery_fee) - $deliveryFeeCreditsTotal;
        $bottledRevenue = (float) $invoices->sum(fn (Invoice $invoice) => (float) $invoice->amount - (float) $invoice->order->delivery_fee) - $credits;
        $revenue = $bottledRevenue + $deliveryFeeRevenue;
        $costOfGoodsSold = (float) DB::table('dispatch_lines')
            ->join('dispatches', 'dispatch_lines.dispatch_id', '=', 'dispatches.id')
            ->join('invoices', 'dispatches.sales_order_id', '=', 'invoices.sales_order_id')
            ->whereDate('invoices.issued_on', '>=', $from)->whereDate('invoices.issued_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(dispatch_lines.cases * dispatch_lines.cost_per_case), 0) total')->value('total');
        $deliveryCost = (float) SalesOrder::query()->whereHas('invoice', fn ($query) => $query->whereDate('issued_on', '>=', $from)->whereDate('issued_on', '<=', $to))->sum('delivery_cost');
        $tripExpenses = (float) DeliveryTrip::query()->whereDate('delivery_on', '>=', $from)->whereDate('delivery_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(fuel_expense + other_expense), 0) total')->value('total');
        $operatingExpenses = (float) $expenses->whereNull('reversed_at')->sum('amount');
        $disposalLoss = (float) DB::table('batch_disposals')
            ->join('production_batches', 'batch_disposals.production_batch_id', '=', 'production_batches.id')
            ->whereDate('batch_disposals.disposed_on', '>=', $from)->whereDate('batch_disposals.disposed_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(batch_disposals.cases * production_batches.cost_per_case), 0) total')->value('total');
        $disposalLoss += (float) DB::table('failed_delivery_lines')
            ->join('dispatch_lines', 'failed_delivery_lines.dispatch_line_id', '=', 'dispatch_lines.id')
            ->where('failed_delivery_lines.status', 'disposed')
            ->whereDate('failed_delivery_lines.inspected_on', '>=', $from)->whereDate('failed_delivery_lines.inspected_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(failed_delivery_lines.cases * dispatch_lines.cost_per_case), 0) total')->value('total');
        $cashCollected = (float) Payment::query()->whereNull('reversed_at')->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->sum('amount');
        $refundsPaid = (float) CustomerRefund::query()->whereDate('refunded_on', '>=', $from)->whereDate('refunded_on', '<=', $to)->sum('amount');
        $returnTotals = DB::table('sales_returns')->select('invoice_id')->selectRaw('SUM(credit_amount) as total')->groupBy('invoice_id');
        $paymentTotals = DB::table('payments')->whereNull('reversed_at')->select('invoice_id')->selectRaw('SUM(amount) as total')->groupBy('invoice_id');
        $refundTotals = DB::table('customer_refunds')->select('invoice_id')->selectRaw('SUM(amount) as total')->groupBy('invoice_id');
        $balances = DB::table('invoices')
            ->leftJoinSub($returnTotals, 'return_totals', 'invoices.id', '=', 'return_totals.invoice_id')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'invoices.id', '=', 'payment_totals.invoice_id')
            ->leftJoinSub($refundTotals, 'refund_totals', 'invoices.id', '=', 'refund_totals.invoice_id')
            ->leftJoin('delivery_fee_credits', 'invoices.id', '=', 'delivery_fee_credits.invoice_id')
            ->select('invoices.amount')->selectRaw('COALESCE(return_totals.total, 0) as return_credit, COALESCE(payment_totals.total, 0) as paid, COALESCE(refund_totals.total, 0) as refunded, COALESCE(delivery_fee_credits.amount, 0) as delivery_credit')->get();
        $outstanding = (float) $balances->sum(fn ($invoice) => max(0, (float) $invoice->amount - (float) $invoice->return_credit - (float) $invoice->delivery_credit - (float) $invoice->paid + (float) $invoice->refunded));
        $customerCreditDue = (float) $balances->sum(fn ($invoice) => max(0, (float) $invoice->paid - ((float) $invoice->amount - (float) $invoice->return_credit - (float) $invoice->delivery_credit) - (float) $invoice->refunded));
        $stockCountLoss = (float) DB::table('stock_movements')->join('production_batches', 'stock_movements.production_batch_id', '=', 'production_batches.id')
            ->where('stock_movements.reason', 'stocktake_adjustment')->where('stock_movements.signed_cases', '<', 0)
            ->whereBetween('stock_movements.created_at', [$utcStart, $utcEnd])
            ->selectRaw('COALESCE(SUM(-stock_movements.signed_cases * production_batches.cost_per_case), 0) total')->value('total');

        $grossProfit = $revenue - $costOfGoodsSold;

        return compact('credits', 'deliveryFeeCreditsTotal', 'deliveryFeeRevenue', 'bottledRevenue', 'revenue', 'costOfGoodsSold', 'deliveryCost', 'tripExpenses', 'operatingExpenses', 'disposalLoss', 'cashCollected', 'refundsPaid', 'outstanding', 'customerCreditDue', 'stockCountLoss', 'grossProfit')
            + ['operatingProfit' => $grossProfit - $deliveryCost - $tripExpenses - $operatingExpenses - $disposalLoss - $stockCountLoss];
    }
}
