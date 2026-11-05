@extends('layouts.app')
@section('page-title', 'Plan truck trip')
@section('content')
<div class="panel col-xl-9"><h1 class="h5">Shared delivery trip</h1><p class="small text-muted">Assign one or more confirmed customer orders to the same truck. Stock remains reserved until each order is dispatched.</p>
<form method="post" action="{{ route('packaged.trips.store') }}">@csrf
    <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label" for="truck">Truck identifier</label><input class="form-control" id="truck" name="truck_identifier" value="{{ old('truck_identifier') }}" required></div><div class="col-md-4"><label class="form-label" for="driver">Driver</label><input class="form-control" id="driver" name="driver_name" value="{{ old('driver_name') }}" required></div><div class="col-md-4"><label class="form-label" for="trip-date">Delivery date</label><input class="form-control" id="trip-date" name="delivery_on" type="date" min="{{ today()->toDateString() }}" value="{{ old('delivery_on', today()->toDateString()) }}" required></div></div>
    <h2 class="h6">Confirmed orders awaiting a truck</h2>
    <div class="table-responsive"><table class="table"><thead><tr><th>Select</th><th>Order</th><th>Customer</th><th>Destination</th><th>Delivery fee</th></tr></thead><tbody>@forelse($orders as $order)<tr><td><input class="form-check-input" type="checkbox" name="order_ids[]" value="{{ $order->id }}" @checked(in_array($order->id, old('order_ids', []))) aria-label="Assign {{ $order->order_number }}"></td><td>{{ $order->order_number }}</td><td>{{ $order->customer->name }}</td><td>{{ $order->deliveryArea->name }} · {{ $order->delivery_address }}</td><td>KSh {{ number_format($order->delivery_fee, 2) }}</td></tr>@empty<tr><td colspan="5" class="text-muted">No confirmed, unassigned orders have a Nairobi delivery area.</td></tr>@endforelse</tbody></table></div>
    @if($orders->isNotEmpty())<button class="btn btn-brand">Create trip</button>@endif <a class="btn btn-light" href="{{ route('distribution.index') }}">Back to distribution</a>
</form></div>
@endsection
