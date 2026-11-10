@extends('layouts.app')
@section('page-title', 'Distribution')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">CASES DISPATCHED</span><span class="metric-value">{{ number_format($summary['cases']) }} cases</span><span class="tiny">All time</span></div></div>
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">ORDERS AWAITING DISPATCH</span><span class="metric-value">{{ number_format($summary['awaiting']) }} orders</span><span class="tiny">Current</span></div></div>
    <div class="col-sm-6 col-xl-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">ACTIVE DELIVERY TRIPS</span><span class="metric-value">{{ number_format($summary['active_trips']) }} trips</span><span class="tiny">Planned or out for delivery</span></div></div>
</div>

<div class="panel mb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <nav class="nav nav-tabs border-0" aria-label="Distribution sections">
        @foreach(['awaiting' => 'Awaiting Dispatch', 'trips' => 'Delivery Trips', 'history' => 'Dispatch History'] as $key => $label)
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" @if($tab === $key) aria-current="page" @endif href="{{ route('distribution.index', ['tab' => $key]) }}">{{ $label }}</a>
        @endforeach
    </nav>
    @if($tab === 'trips')<a class="btn btn-brand btn-sm" href="{{ route('packaged.trips.create') }}">Plan Delivery Trip</a>@endif
</div>

@if($tab !== 'trips')
<form class="toolbar mb-3" method="get" action="{{ route('distribution.index') }}">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div><label class="form-label" for="distribution-order">Order number</label><input class="form-control" id="distribution-order" name="order" value="{{ $filters['order'] ?? '' }}" placeholder="Search order"></div>
    <div><label class="form-label" for="distribution-customer">Customer</label><input class="form-control" id="distribution-customer" name="customer" value="{{ $filters['customer'] ?? '' }}" placeholder="Search customer"></div>
    @if($tab === 'awaiting')<div><label class="form-label" for="distribution-zone">Delivery zone</label><select class="form-select" id="distribution-zone" name="delivery_zone_id"><option value="">All zones</option>@foreach($zones as $zone)<option value="{{ $zone->id }}" @selected((string) ($filters['delivery_zone_id'] ?? '') === (string) $zone->id)>{{ $zone->code }}</option>@endforeach</select></div>@endif
    @if($tab === 'history')<div><label class="form-label" for="distribution-status">Delivery status</label><select class="form-select" id="distribution-status" name="status"><option value="">All statuses</option><option value="dispatched" @selected(($filters['status'] ?? '') === 'dispatched')>Dispatched</option><option value="delivered" @selected(($filters['status'] ?? '') === 'delivered')>Delivered</option><option value="delivery_failed" @selected(($filters['status'] ?? '') === 'delivery_failed')>Delivery failed</option></select></div>@endif
    <div><label class="form-label" for="distribution-from">{{ $tab === 'awaiting' ? 'Ordered' : 'Dispatched' }} from</label><input class="form-control" id="distribution-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
    <div><label class="form-label" for="distribution-to">{{ $tab === 'awaiting' ? 'Ordered' : 'Dispatched' }} to</label><input class="form-control" id="distribution-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
    <div class="align-self-end d-flex gap-2"><button class="btn btn-brand">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('distribution.index', ['tab' => $tab]) }}">Clear</a></div>
</form>
@endif

<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ ['awaiting' => 'Orders ready for distribution', 'trips' => 'Delivery trips', 'history' => 'Dispatch records'][$tab] }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        @if($tab === 'awaiting')
        <thead><tr><th class="px-3">Order</th><th>Customer</th><th>Delivery area</th><th>Zone</th><th>Cases to dispatch</th><th>Order date</th><th>Status</th><th>Trip</th></tr></thead>
        <tbody>@forelse($rows as $order)<tr><td class="px-3"><a href="{{ route('packaged.orders.show', $order) }}">{{ $order->order_number }}</a></td><td>{{ $order->customer->name }}</td><td>{{ $order->deliveryArea?->name }}<span class="d-block tiny">{{ $order->delivery_address }}</span></td><td>{{ $order->deliveryZone?->code ?? 'Not recorded' }}</td><td>{{ number_format($order->items->sum('cases_ordered')) }} cases</td><td>{{ $order->ordered_on->format('d M Y') }}</td><td><span class="badge text-bg-primary">Confirmed</span></td><td>@if($order->deliveryTrips->isNotEmpty())<a href="{{ route('packaged.trips.show', $order->deliveryTrips->first()) }}">View trip</a>@else<a class="btn btn-outline-brand btn-sm" href="{{ route('packaged.trips.create', ['order_id' => $order->id]) }}">Assign to Trip</a>@endif</td></tr>@empty<tr><td colspan="8" class="p-5 text-center text-muted">No confirmed orders match these filters.</td></tr>@endforelse</tbody>
        @elseif($tab === 'trips')
        <thead><tr><th class="px-3">Trip reference</th><th>Delivery date</th><th>Customer orders</th><th>Status</th><th>Recorded expenses</th></tr></thead>
        <tbody>@forelse($rows as $trip)@php($tripStatus = $trip->status ? ucfirst($trip->status) : 'Historical')<tr><td class="px-3"><a href="{{ route('packaged.trips.show', $trip) }}">{{ $trip->trip_reference ?? 'Trip #'.$trip->id }}</a></td><td>{{ $trip->delivery_on->format('d M Y') }}</td><td>{{ number_format($trip->orders_count) }}</td><td><span class="badge {{ match($trip->status) {'planned' => 'text-bg-primary', 'dispatched' => 'text-bg-warning', 'completed' => 'text-bg-success', default => 'text-bg-secondary'} }}">{{ $tripStatus }}</span></td><td>KSh {{ number_format($trip->totalExpense(), 2) }}</td></tr>@empty<tr><td colspan="5" class="p-5 text-center text-muted">No delivery trips have been planned yet.</td></tr>@endforelse</tbody>
        @else
        <thead><tr><th class="px-3">Dispatch date</th><th>Order</th><th>Customer</th><th>Destination</th><th>Cases</th><th>Delivery status</th></tr></thead>
        <tbody>@forelse($rows as $dispatch)<tr><td class="px-3">{{ $dispatch->dispatched_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $dispatch->order) }}">{{ $dispatch->order->order_number }}</a></td><td>{{ $dispatch->order->customer->name }}</td><td>{{ $dispatch->order->deliveryArea?->name ?? $dispatch->order->delivery_address ?? 'Not recorded' }}</td><td>{{ number_format($dispatch->lines->sum('cases')) }} cases</td><td><span class="badge {{ $dispatch->status === 'delivered' ? 'text-bg-success' : ($dispatch->status === 'delivery_failed' ? 'text-bg-danger' : 'text-bg-primary') }}">{{ ucfirst(str_replace('_', ' ', $dispatch->status)) }}</span></td></tr>@empty<tr><td colspan="6" class="p-5 text-center text-muted">No dispatch records match these filters.</td></tr>@endforelse</tbody>
        @endif
    </table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
