@extends('layouts.app')
@section('page-title', ucfirst($type))
@section('content')
<div class="row g-3 mb-3">
    @if($type === 'production')
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">TOTAL CASES PRODUCED</span><span class="metric-value">{{ number_format($productionSummary['all_time_cases']) }} cases</span><span class="tiny">All time</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">CASES PRODUCED THIS MONTH</span><span class="metric-value">{{ number_format($productionSummary['month_cases']) }} cases</span><span class="tiny">{{ $productionSummary['month_label'] }} to date</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">AWAITING QUALITY APPROVAL</span><span class="metric-value">{{ number_format($productionSummary['pending_batches']) }} batches</span><span class="tiny">Current quality holds</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="toolbar h-100 justify-content-sm-end">@if(auth()->user()->hasRole('administrator', 'manager', 'production_staff'))<a class="btn btn-brand btn-sm" href="{{ route('packaged.batches.create') }}">Create batch</a>@endif</div></div>
    @else
    <div class="col-md-4"><div class="metric"><span class="metric-label">{{ strtoupper($summary['label']) }}</span><span class="metric-value">{{ $summary['suffix'] === 'KSh' ? 'KSh ' : '' }}{{ number_format($summary['value']) }}{{ $summary['suffix'] === 'cases' ? ' cases' : '' }}</span></div></div>
    <div class="col-md-8"><div class="toolbar h-100 justify-content-end">
        @if($type === 'inventory')<a class="btn btn-outline-brand w-auto mt-0" href="{{ route('packaged.batches.index') }}">Batches &amp; quality</a>@endif
        @if(($type === 'distribution' || $type === 'sales') && auth()->user()->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff'))<a class="btn btn-outline-brand w-auto mt-0" href="{{ route('packaged.orders.index') }}">Customer orders</a>@endif
        @if($type === 'distribution' && auth()->user()->hasRole('administrator', 'manager', 'distribution_staff'))<a class="btn btn-brand btn-sm" href="{{ route('packaged.trips.create') }}">Plan truck trip</a>@endif
        @if($type === 'sales' && auth()->user()->hasRole('administrator', 'manager', 'sales_staff'))<a class="btn btn-brand btn-sm" href="{{ route('packaged.orders.create') }}">Create order</a>@endif
    </div></div>
    @endif
</div>
@if($type === 'sales' && auth()->user()->hasRole('administrator', 'manager', 'sales_staff'))
<details class="panel mb-3"><summary class="fw-semibold">Confirm a day with no customer orders</summary><p class="small mt-2">Use this only after checking the sales register. Forecasts treat a confirmed zero-order day differently from a missing record.</p><form class="d-flex gap-2 align-items-end flex-wrap" method="post" action="{{ route('packaged.orders.close-day') }}">@csrf<div><label class="form-label" for="zero-order-day">Date checked</label><input class="form-control" id="zero-order-day" name="order_day" type="date" max="{{ today()->toDateString() }}" required></div><button class="btn btn-outline-primary">Confirm zero orders</button></form></details>
@endif
@if($type === 'inventory')
<div class="row g-3 mb-3">@foreach($stockByProduct as $stock)<div class="col-md-3"><div class="metric"><span class="metric-label">{{ $stock->name }}</span><span class="metric-value">{{ number_format($stock->cases) }} cases</span></div></div>@endforeach</div>
<p class="tiny">Available stock includes only released, unexpired cases not reserved for confirmed orders. Stock leaves inventory once, at dispatch.</p>
@endif
@if(in_array($type, ['production', 'inventory', 'distribution', 'sales'], true))
<form class="toolbar mb-3" method="get" action="{{ route($type.'.index') }}">
    <div><label class="form-label" for="record-filter">{{ match($type) {'distribution' => 'Order number', 'sales' => 'Invoice number', default => 'Batch code'} }}</label><input class="form-control" id="record-filter" name="{{ match($type) {'distribution' => 'order', 'sales' => 'invoice', default => 'batch'} }}" value="{{ match($type) {'distribution' => $filters['order'] ?? '', 'sales' => $filters['invoice'] ?? '', default => $filters['batch'] ?? ''} }}" placeholder="{{ match($type) {'distribution' => 'Search order number', 'sales' => 'Search invoice number', default => 'Search batch code'} }}"></div>
    <div><label class="form-label" for="record-date-from">{{ match($type) {'production' => 'Produced', 'inventory' => 'Movement', 'distribution' => 'Dispatched', 'sales' => 'Issued'} }} from</label><input class="form-control" id="record-date-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
    <div><label class="form-label" for="record-date-to">{{ match($type) {'production' => 'Produced', 'inventory' => 'Movement', 'distribution' => 'Dispatched', 'sales' => 'Issued'} }} to</label><input class="form-control" id="record-date-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
    @if($type === 'production')<div><label class="form-label" for="production-product">Product</label><select class="form-select" id="production-product" name="product_id"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>@endif
    @if($type === 'sales')<div><label class="form-label" for="invoice-status">Status</label><select class="form-select" id="invoice-status" name="status"><option value="">All statuses</option>@foreach(['unpaid' => 'Unpaid', 'part_paid' => 'Part paid', 'paid' => 'Paid', 'credited' => 'Credited', 'settled' => 'Settled', 'credit_due' => 'Credit due'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>@endif
    <div class="align-self-end d-flex gap-2"><button class="btn btn-brand" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route($type.'.index') }}">Clear</a></div>
</form>
@endif
@if($type === 'distribution')
<div class="panel mb-3"><div class="d-flex justify-content-between align-items-center mb-2"><strong>Shared truck trips</strong><span class="tiny">Latest 20 trips</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Delivery date</th><th>Truck</th><th>Driver</th><th>Customer orders</th><th>Recorded trip expense</th></tr></thead><tbody>@forelse($trips as $trip)<tr><td>{{ $trip->delivery_on->format('d M Y') }}</td><td><a href="{{ route('packaged.trips.show', $trip) }}">{{ $trip->truck_identifier }}</a></td><td>{{ $trip->driver_name }}</td><td>{{ $trip->orders_count }}</td><td>KSh {{ number_format($trip->totalExpense(), 2) }}</td></tr>@empty<tr><td colspan="5" class="text-muted">No shared truck trips recorded yet.</td></tr>@endforelse</tbody></table></div></div>
@endif
<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ match($type) {'production' => 'Production batches', 'inventory' => 'Stock movements', 'distribution' => 'Dispatches', 'sales' => 'Invoices'} }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr>
            @if($type === 'production')<th class="px-3">Batch Code</th><th>Product</th><th>Production Date</th><th>Expiry Date</th><th>Cases Produced</th><th>Cases On Hand</th><th>Status</th>
            @elseif($type === 'inventory')<th class="px-3">Date</th><th>Batch</th><th>Product</th><th>Movement</th><th>Cases</th><th>Notes</th>
            @elseif($type === 'distribution')<th class="px-3">Date</th><th>Order</th><th>Truck</th><th>Customer</th><th>Destination</th><th>Cases</th><th>Status</th>
            @else<th class="px-3">Invoice</th><th>Issued</th><th>Order</th><th>Customer</th><th>Cases ordered</th><th>Amount</th><th>Paid</th><th>Status</th>@endif
        </tr></thead>
        <tbody>@forelse($rows as $row)<tr>
            @if($type === 'production')<td class="px-3"><a href="{{ route('packaged.batches.show', $row) }}">{{ $row->batch_code }}</a></td><td>{{ $row->product->name }}</td><td>{{ $row->produced_on->format('d M Y') }}</td><td>{{ $row->expires_on->format('d M Y') }} @if($row->expires_on->lt($businessToday))<span class="badge text-bg-danger ms-1">Expired</span>@elseif($row->expires_on->isSameDay($businessToday))<span class="badge text-bg-warning ms-1">Expires today</span>@endif</td><td>{{ number_format($row->cases_produced) }} cases</td><td>{{ number_format($row->cases_on_hand) }} cases</td><td><span class="badge {{ match($row->status) {'quality_hold' => 'text-bg-warning', 'released' => 'text-bg-success', 'quarantined', 'recalled', 'rejected' => 'text-bg-danger', default => 'text-bg-secondary'} }}">{{ ucwords(str_replace('_', ' ', $row->status)) }}</span></td>
            @elseif($type === 'inventory')<td class="px-3">{{ $row->created_at->format('d M Y') }}</td><td><a href="{{ route('packaged.batches.show', $row->batch) }}">{{ $row->batch->batch_code }}</a></td><td>{{ $row->batch->product->name }}</td><td>{{ str_replace('_', ' ', ucfirst($row->reason)) }}</td><td>{{ $row->signed_cases > 0 ? '+' : '' }}{{ number_format($row->signed_cases) }} cases</td><td>{{ $row->notes ?: '—' }}</td>
            @elseif($type === 'distribution')<td class="px-3">{{ $row->dispatched_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $row->order) }}">{{ $row->order->order_number }}</a></td><td>@if($row->order->deliveryTrips->isNotEmpty())<a href="{{ route('packaged.trips.show', $row->order->deliveryTrips->first()) }}">{{ $row->order->deliveryTrips->first()->truck_identifier }}</a>@else Historical dispatch @endif</td><td>{{ $row->order->customer->name }}</td><td>{{ $row->order->delivery_address }}</td><td>{{ number_format($row->lines->sum('cases')) }} cases</td><td>{{ ucfirst($row->status) }}</td>
            @else<td class="px-3">{{ $row->invoice_number }}</td><td>{{ $row->issued_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $row->order) }}">{{ $row->order->order_number }}</a></td><td>{{ $row->order->customer->name }}</td><td>{{ number_format($row->order->items->sum('cases_ordered')) }} cases</td><td>KSh {{ number_format($row->amount, 2) }}</td><td>KSh {{ number_format($row->activePayments->sum('amount'), 2) }}</td><td>{{ ucfirst($row->status) }}</td>@endif
        </tr>@empty<tr><td colspan="{{ $type === 'sales' ? 8 : 7 }}" class="p-5 text-center text-muted">{{ $type === 'production' ? (array_filter($filters) ? 'No production batches match these filters.' : 'No production batches recorded yet.') : (in_array($type, ['inventory', 'distribution', 'sales'], true) && array_filter($filters) ? 'No '.$type.' records match these filters.' : 'No '.$type.' records yet.') }}</td></tr>@endforelse</tbody>
    </table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
