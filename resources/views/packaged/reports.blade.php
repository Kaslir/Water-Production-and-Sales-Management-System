@extends('layouts.app')
@section('page-title', 'Profit & expiry')
@section('content')
<div class="panel mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1 class="h4 mb-1">Profit and stock expiry</h1>
            <p class="small text-muted mb-0">Management estimates for packaged-water operations, not audited financial statements. Legacy aggregate-litre transactions are excluded.</p>
        </div>
        @if(auth()->user()->hasRole('administrator', 'manager'))
            <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#recordExpenseModal">Record Expense</button>
        @endif
    </div>
    <form class="row g-2 align-items-end mt-2" method="get" action="{{ route('packaged.reports.index') }}">
        <div class="col-sm-auto">
            <label class="form-label" for="report-from">From</label>
            <input class="form-control" id="report-from" name="from" type="date" value="{{ $from }}">
        </div>
        <div class="col-sm-auto">
            <label class="form-label" for="report-to">To</label>
            <input class="form-control" id="report-to" name="to" type="date" value="{{ $to }}">
        </div>
        <div class="col-sm-auto"><button class="btn btn-brand" type="submit">Apply period</button></div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="panel h-100 d-flex flex-column">
            <div class="small text-uppercase fw-semibold text-muted">Total invoiced revenue after credits</div>
            <div class="small text-muted mb-3">Selected period</div>
            <div class="fs-4 fw-bold mt-auto">KSh {{ number_format($revenue, 2) }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="panel h-100 d-flex flex-column">
            <div class="small text-uppercase fw-semibold text-muted">Gross Profit</div>
            <div class="small text-muted mb-3">Selected period · management estimate</div>
            <div class="fs-4 fw-bold mt-auto">KSh {{ number_format($grossProfit, 2) }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="panel h-100 d-flex flex-column">
            <div class="small text-uppercase fw-semibold text-muted">Operating Profit</div>
            <div class="small text-muted mb-3">Selected period · management estimate</div>
            <div class="fs-4 fw-bold mt-auto">KSh {{ number_format($operatingProfit, 2) }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="panel h-100 d-flex flex-column">
            <div class="small text-uppercase fw-semibold text-muted">Outstanding Payments</div>
            <div class="small text-muted mb-3">All invoices, not limited to selected period</div>
            <div class="fs-4 fw-bold mt-auto">KSh {{ number_format($outstanding, 2) }}</div>
            <div class="small text-muted mt-1">Customer credits awaiting refund: KSh {{ number_format($customerCreditDue, 2) }}</div>
        </div>
    </div>
</div>

<section class="panel mb-3" aria-labelledby="financial-breakdown-heading">
    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
        <h2 class="h5 mb-0" id="financial-breakdown-heading">Financial Breakdown</h2>
        <span class="small text-muted">Selected period: {{ \Carbon\Carbon::parse($from)->format('d M Y') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</span>
    </div>
    <div class="row g-0">
        <div class="col-lg-6 pe-lg-3">
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Bottled-water sales after product credits</span><strong>KSh {{ number_format($bottledRevenue, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Customer delivery fees after approved credits</span><strong>KSh {{ number_format($deliveryFeeRevenue, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Customer return credits</span><strong>KSh {{ number_format($credits, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Approved delivery-fee credits</span><strong>KSh {{ number_format($deliveryFeeCreditsTotal, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Cost of goods sold</span><strong>KSh {{ number_format($costOfGoodsSold, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Delivery trip expenses</span><strong>KSh {{ number_format($tripExpenses, 2) }}</strong></div>
            @if($deliveryCost > 0)<div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Historical order delivery expenses</span><strong>KSh {{ number_format($deliveryCost, 2) }}</strong></div>@endif
        </div>
        <div class="col-lg-6 ps-lg-3">
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Other operating expense</span><strong>KSh {{ number_format($operatingExpenses, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Expiry and disposal loss</span><strong>KSh {{ number_format($disposalLoss, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Physical stock-count shortage loss</span><strong>KSh {{ number_format($stockCountLoss, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Cash collected during period</span><strong>KSh {{ number_format($cashCollected, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Customer refunds paid</span><strong>KSh {{ number_format($refundsPaid, 2) }}</strong></div>
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span>Actual cost variance for batches with a closeout</span><strong>KSh {{ number_format($actualCostVariance, 2) }}</strong></div>
        </div>
    </div>
    <p class="small text-muted mt-2 mb-0">Delivery fees are revenue; each trip's fuel and other expenses are counted once. Historical order delivery expenses are separate. Actual cost variance is shown for review and is not included again in estimated profit. Refunds change cash and receivables; approved credits already reduce revenue.</p>
    @if(auth()->user()->hasRole('administrator','manager') && $expenses->isNotEmpty())
    <details class="mt-3"><summary>Recorded operating expenses and corrections</summary>
        @foreach($expenses as $expense)<div class="border-top py-2">{{ $expense->expense_date->format('d M Y') }} · {{ $expense->category }} · KSh {{ number_format($expense->amount, 2) }} @if($expense->reversed_at)<span class="badge bg-secondary">Reversed</span> {{ $expense->reversal_reason }}@else<form class="d-flex gap-2 mt-2" method="post" action="{{ route('packaged.expenses.reverse', $expense) }}">@csrf<input class="form-control" name="reason" placeholder="Correction reason" required><button class="btn btn-outline-danger" onclick="return confirm('Reverse this expense?')">Reverse</button></form>@endif</div>@endforeach
    </details>
    @endif
</section>

<section class="panel mb-3" aria-labelledby="stock-expiry-heading">
    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
        <h2 class="h5 mb-0" id="stock-expiry-heading">Stock Expiry Monitoring</h2>
        <span class="small text-muted">Expired stock and batches due within 30 days</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Batch</th><th>Product</th><th>Cases on hand</th><th>Expiry date</th><th>Warning</th></tr></thead>
            <tbody>
                @forelse($nearExpiry as $batch)
                    <tr>
                        <td><a href="{{ route('packaged.batches.show', $batch) }}">{{ $batch->batch_code }}</a></td>
                        <td>{{ $batch->product->name }}</td>
                        <td>{{ number_format($batch->cases_on_hand) }}</td>
                        <td>{{ $batch->expires_on->format('d M Y') }}</td>
                        <td>
                            @if($batch->expires_on->lt(today()))
                                <span class="badge text-bg-danger">Expired — do not dispatch</span>
                            @elseif($batch->expires_on->toDateString() === today()->toDateString())
                                <span class="badge text-bg-danger">Expires today — do not dispatch</span>
                            @elseif($batch->expires_on->lte(today()->addDays(7)))
                                <span class="badge text-bg-warning">Expires within 7 days</span>
                            @else
                                <span class="badge text-bg-secondary">Expires within 30 days</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No batches are expired or nearing expiry.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel" aria-labelledby="detailed-reports-heading">
    <h2 class="h5 mb-3" id="detailed-reports-heading">Detailed Reports</h2>
    <ul class="nav nav-tabs" id="profit-report-tabs" role="tablist" aria-label="Detailed reports">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="invoices-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#invoices-report" aria-controls="invoices-report" aria-selected="true">Invoices</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="products-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#products-report" aria-controls="products-report" aria-selected="false">Profit by Product</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="customers-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#customers-report" aria-controls="customers-report" aria-selected="false">Customer Contribution</button></li>
    </ul>
    <div class="tab-content pt-3" id="profit-report-content">
        <div class="tab-pane fade show active" id="invoices-report" role="tabpanel" aria-labelledby="invoices-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Invoice</th><th>Customer</th><th>Issued</th><th>Water after discount (KSh)</th><th>Delivery fee (KSh)</th><th>Total (KSh)</th><th>Paid (KSh)</th></tr></thead>
                    <tbody>
                        @forelse($invoices as $invoice)
                            <tr><td><a href="{{ route('packaged.orders.show', $invoice->order) }}">{{ $invoice->invoice_number }}</a></td><td>{{ $invoice->order->customer->name }}</td><td>{{ $invoice->issued_on->format('d M Y') }}</td><td>{{ number_format((float)$invoice->amount - (float)$invoice->order->delivery_fee, 2) }}</td><td>{{ number_format($invoice->order->delivery_fee, 2) }}@if($invoice->deliveryFeeCredit)<div class="tiny">Credited KSh {{ number_format($invoice->deliveryFeeCredit->amount, 2) }}</div>@endif</td><td>{{ number_format($invoice->amount, 2) }}</td><td>{{ number_format($invoice->activePayments->sum('amount'), 2) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No invoices were issued in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="products-report" role="tabpanel" aria-labelledby="products-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Product</th><th>Net invoiced cases after credited returns</th><th>Net bottled-water revenue (KSh)</th><th>Gross profit estimate (KSh)</th></tr></thead>
                    <tbody>
                        @forelse($productPerformance as $row)
                            <tr><td>{{ $row['name'] }}</td><td>{{ $row['cases'] }}</td><td>{{ number_format($row['revenue'], 2) }}</td><td>{{ number_format($row['revenue'] - $row['cost'], 2) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No invoiced products in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="customers-report" role="tabpanel" aria-labelledby="customers-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Customer</th><th>Invoiced revenue after credits (KSh)</th><th>Historical order delivery expense (KSh)</th><th>Contribution before shared trip expense (KSh)</th></tr></thead>
                    <tbody>
                        @forelse($customerPerformance as $row)
                            <tr><td>{{ $row['name'] }}</td><td>{{ number_format($row['revenue'], 2) }}</td><td>{{ number_format($row['delivery'], 2) }}</td><td>{{ number_format($row['revenue'] - $row['cost'] - $row['delivery'], 2) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No invoiced customers in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">Shared truck expenses are not allocated to individual customers. The trip total appears once in the financial breakdown.</p>
        </div>
    </div>
</section>

@if(auth()->user()->hasRole('administrator', 'manager'))
    <div class="modal fade" id="recordExpenseModal" tabindex="-1" aria-labelledby="recordExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="recordExpenseModalLabel">Record operating expense</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="{{ route('packaged.expenses.store') }}">
                    @csrf
                    <input type="hidden" name="_expense_form" value="1">
                    <div class="modal-body">
                        <label class="form-label" for="expense-date">Date</label>
                        <input class="form-control mb-3" id="expense-date" type="date" name="expense_date" value="{{ old('expense_date', today()->toDateString()) }}" required>
                        <label class="form-label" for="expense-category">Category</label>
                        <input class="form-control mb-3" id="expense-category" name="category" value="{{ old('category') }}" required>
                        <label class="form-label" for="expense-amount">Amount (KSh)</label>
                        <input class="form-control mb-3" id="expense-amount" type="number" name="amount" min="0.01" step="0.01" value="{{ old('amount') }}" required>
                        <label class="form-label" for="expense-notes">Notes</label>
                        <textarea class="form-control" id="expense-notes" name="notes" rows="3">{{ old('notes') }}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-brand" type="submit">Record expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @if(old('_expense_form'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('recordExpenseModal')).show();
            });
        </script>
    @endif
@endif
@endsection
