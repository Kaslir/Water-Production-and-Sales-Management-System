@extends('layouts.app')
@section('page-title', 'Sales')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">INVOICED SALES</span><span class="metric-value">KSh {{ number_format($summary['gross_invoiced'], 2) }}</span><span class="tiny">All time · Gross invoice amount, including delivery fees</span></div></div>
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">OUTSTANDING CUSTOMER PAYMENTS</span><span class="metric-value">KSh {{ number_format($summary['outstanding'], 2) }}</span><span class="tiny">All time · After credits, active payments and refunds</span></div></div>
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">ORDERS AWAITING CONFIRMATION</span><span class="metric-value">{{ number_format($summary['awaiting_confirmation']) }} orders</span><span class="tiny">Current draft orders</span></div></div>
</div>
<div class="panel mb-3 small">All-time invoice breakdown: Gross invoices <strong>KSh {{ number_format($summary['gross_invoiced'], 2) }}</strong> · Credits <strong>KSh {{ number_format($summary['credits'], 2) }}</strong> · Net invoiced <strong>KSh {{ number_format($summary['net_invoiced'], 2) }}</strong> · Payments received <strong>KSh {{ number_format($summary['payments'], 2) }}</strong> · Refunds paid <strong>KSh {{ number_format($summary['refunds'], 2) }}</strong>. Payments are collections, not another sale.</div>

<div class="panel mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <nav class="nav nav-tabs border-0" aria-label="Sales sections"><a class="nav-link {{ $tab === 'orders' ? 'active' : '' }}" @if($tab === 'orders') aria-current="page" @endif href="{{ route('sales.index', ['tab' => 'orders']) }}">Customer Orders</a><a class="nav-link {{ $tab === 'invoices' ? 'active' : '' }}" @if($tab === 'invoices') aria-current="page" @endif href="{{ route('sales.index', ['tab' => 'invoices']) }}">Invoices</a></nav>
    <a class="btn btn-brand btn-sm" href="{{ route('packaged.orders.create') }}">Create Order</a>
</div>

<form class="toolbar mb-3" method="get" action="{{ route('sales.index') }}">
    <input type="hidden" name="tab" value="{{ $tab }}">
    @if($tab === 'orders')
        <div><label class="form-label" for="sales-order-search">Order number</label><input class="form-control" id="sales-order-search" name="order" value="{{ $filters['order'] ?? '' }}" placeholder="Search order"></div>
        <div><label class="form-label" for="sales-customer-search">Customer</label><input class="form-control" id="sales-customer-search" name="customer" value="{{ $filters['customer'] ?? '' }}" placeholder="Search customer"></div>
        <div><label class="form-label" for="sales-order-status">Order status</label><select class="form-select" id="sales-order-status" name="order_status"><option value="">All statuses</option>@foreach(['draft','confirmed','dispatched','delivery_failed','delivered','invoiced','paid','cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['order_status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
    @else
        <div><label class="form-label" for="sales-invoice-search">Invoice number</label><input class="form-control" id="sales-invoice-search" name="invoice" value="{{ $filters['invoice'] ?? '' }}" placeholder="Search invoice"></div>
        <div><label class="form-label" for="sales-invoice-status">Invoice status</label><select class="form-select" id="sales-invoice-status" name="status"><option value="">All statuses</option>@foreach(['unpaid' => 'Unpaid', 'part_paid' => 'Part paid', 'paid' => 'Paid', 'credited' => 'Credited', 'settled' => 'Settled', 'credit_due' => 'Credit due'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    @endif
    <div><label class="form-label" for="sales-from">{{ $tab === 'orders' ? 'Ordered' : 'Issued' }} from</label><input class="form-control" id="sales-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
    <div><label class="form-label" for="sales-to">{{ $tab === 'orders' ? 'Ordered' : 'Issued' }} to</label><input class="form-control" id="sales-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
    <div class="align-self-end d-flex gap-2"><button class="btn btn-brand">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('sales.index', ['tab' => $tab]) }}">Clear</a></div>
</form>

<div class="panel p-0 overflow-hidden"><div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ $tab === 'orders' ? 'Customer orders' : 'Invoices' }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    @if($tab === 'orders')
        @include('packaged.partials.orders-table', ['orders' => $rows])
    @else
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="px-3">Invoice</th><th>Issued</th><th>Order</th><th>Customer</th><th>Cases ordered</th><th>Gross amount</th><th>Payments received</th><th>Status</th></tr></thead><tbody>@forelse($rows as $invoice)<tr><td class="px-3">{{ $invoice->invoice_number }}</td><td>{{ $invoice->issued_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $invoice->order) }}">{{ $invoice->order->order_number }}</a></td><td>{{ $invoice->order->customer->name }}</td><td>{{ number_format($invoice->order->items->sum('cases_ordered')) }} cases</td><td>KSh {{ number_format($invoice->amount, 2) }}</td><td>KSh {{ number_format($invoice->activePayments->sum('amount'), 2) }}</td><td><span class="badge {{ match($invoice->status) {'paid','settled' => 'text-bg-success', 'part_paid' => 'text-bg-warning', 'credit_due' => 'text-bg-danger', default => 'text-bg-secondary'} }}">{{ ucwords(str_replace('_', ' ', $invoice->status)) }}</span></td></tr>@empty<tr><td colspan="8" class="p-5 text-center text-muted">No invoices match these filters.</td></tr>@endforelse</tbody></table></div>
    @endif
    <div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
