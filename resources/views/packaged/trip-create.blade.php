@extends('layouts.app')
@section('page-title', isset($trip) ? 'Edit delivery trip' : 'Plan delivery trip')
@section('content')
<div class="panel col-xl-9"><h1 class="h5">{{ isset($trip) ? 'Edit '.$trip->trip_reference : 'Plan a delivery trip' }}</h1><p class="small text-muted">Select one or more confirmed orders and review their destinations. Stock remains reserved until the trip is dispatched.</p>
<form method="post" action="{{ isset($trip) ? route('packaged.trips.update', $trip) : route('packaged.trips.store') }}">@csrf @if(isset($trip))@method('PATCH')@endif
    <div class="mb-3 col-md-4"><label class="form-label" for="trip-date">Planned delivery date</label><input class="form-control" id="trip-date" name="delivery_on" type="date" min="{{ today()->toDateString() }}" value="{{ old('delivery_on', isset($trip) ? $trip->delivery_on->toDateString() : today()->toDateString()) }}" required></div>
    <h2 class="h6">Confirmed orders and delivery locations</h2>
    <p class="tiny">Select every stop for this trip. One order is enough.</p>
    <div class="table-responsive"><table class="table"><thead><tr><th>Select</th><th>Order</th><th>Customer</th><th>Destination</th><th>Delivery fee</th></tr></thead><tbody>@forelse($orders as $order)<tr><td><input class="form-check-input" type="checkbox" name="order_ids[]" value="{{ $order->id }}" @checked(in_array($order->id, old('order_ids', isset($trip) ? $trip->orders->pluck('id')->all() : ($selectedOrderId ? [$selectedOrderId] : [])))) aria-label="Assign {{ $order->order_number }}"></td><td>{{ $order->order_number }}</td><td>{{ $order->customer->name }}</td><td>{{ $order->deliveryArea->name }} · {{ $order->delivery_address }}</td><td>KSh {{ number_format($order->delivery_fee, 2) }}</td></tr>@empty<tr><td colspan="5" class="text-muted">No confirmed, unassigned orders have a Nairobi delivery area.</td></tr>@endforelse</tbody></table></div>
    @if($orders->isNotEmpty())<button class="btn btn-brand">{{ isset($trip) ? 'Save trip' : 'Plan delivery trip' }}</button>@endif <a class="btn btn-light" href="{{ isset($trip) ? route('packaged.trips.show', $trip) : route('distribution.index') }}">Cancel</a>
</form></div>
@endsection
