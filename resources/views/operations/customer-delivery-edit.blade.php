@extends('layouts.app')
@section('page-title', 'Customer delivery location')
@section('content')
<div class="panel col-lg-8">
    <h1 class="h5 mb-2">{{ $customer->name }}</h1>
    <p class="small text-muted">Set a default Nairobi delivery area. Orders may use another supported area. Existing orders retain their saved fees.</p>
    <form method="post" action="{{ route('customers.delivery.update', $customer) }}">@csrf
        <label class="form-label" for="delivery-area">Delivery area</label>
        <select class="form-select mb-3" id="delivery-area" name="delivery_area_id" required><option value="">Select area</option>@foreach($areas as $area)<option value="{{ $area->id }}" @selected(old('delivery_area_id', $customer->delivery_area_id) == $area->id)>{{ $area->name }} · Zone {{ $area->zone->code }}</option>@endforeach</select>
        <label class="form-label" for="delivery-address">Specific address or landmark</label>
        <input class="form-control mb-3" id="delivery-address" name="address" value="{{ old('address', $customer->address) }}" required>
        <button class="btn btn-brand">Save location</button> <a class="btn btn-light" href="{{ route('customers.show', $customer) }}">Cancel</a>
    </form>
</div>
@endsection
