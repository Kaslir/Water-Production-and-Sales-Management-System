@extends('layouts.app')
@section('page-title', 'New customer order')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.orders.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="customer-id">Customer</label><select class="form-select" id="customer-id" name="customer_id" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer->customer_id }}" @selected(old('customer_id') == $customer->customer_id)>{{ $customer->name }}</option>@endforeach</select><div class="tiny">Add a company customer on the Customers page first.</div></div>
        <div class="col-md-6"><label class="form-label" for="delivery-address">Delivery address</label><input class="form-control" id="delivery-address" name="delivery_address" value="{{ old('delivery_address') }}" required></div>
        <div class="col-md-4"><label class="form-label" for="requested-delivery">Requested delivery date</label><input class="form-control" id="requested-delivery" type="date" name="requested_delivery_on" min="{{ today()->toDateString() }}" value="{{ old('requested_delivery_on', today()->toDateString()) }}" required><div class="tiny">Reserved stock must remain unexpired through this date.</div></div>
        @if(auth()->user()->hasRole('administrator', 'manager'))
            <div class="col-md-4"><label class="form-label" for="order-discount">Discount (KSh)</label><input class="form-control" id="order-discount" type="number" name="discount" step="0.01" min="0" value="{{ old('discount', 0) }}"></div>
        @else
            <input type="hidden" name="discount" value="0">
        @endif
        <div class="col-md-4"><label class="form-label" for="delivery-cost">Delivery cost (KSh)</label><input class="form-control" id="delivery-cost" type="number" name="delivery_cost" step="0.01" min="0" value="{{ old('delivery_cost', 0) }}" required></div>
        @if(auth()->user()->hasRole('administrator', 'manager'))
            <div class="col-md-6"><label class="form-label" for="price-reason">Reason for a changed price or discount</label><input class="form-control" id="price-reason" name="price_override_reason" maxlength="500" value="{{ old('price_override_reason') }}"><div class="tiny">Required if a selling price differs from the listed price or a discount is entered.</div></div>
        @endif
        <div class="col-md-6"><label class="form-label" for="order-notes">Notes</label><input class="form-control" id="order-notes" name="notes" value="{{ old('notes') }}"></div>
    </div>
    <hr><strong>Products</strong><div class="tiny mb-2">Enter cases for one or more products. Leave other products at zero.</div>
    @foreach($products as $index => $product)
        <div class="row g-2 align-items-center mb-2">
            <div class="col-md-4"><label class="form-label" for="cases-{{ $product->id }}">{{ $product->name }}</label><input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $product->id }}"></div>
            <div class="col-md-3"><input class="form-control" id="cases-{{ $product->id }}" type="number" name="items[{{ $index }}][cases_ordered]" min="0" value="{{ old('items.'.$index.'.cases_ordered', 0) }}" aria-label="Cases for {{ $product->name }}"></div>
            <div class="col-md-3"><input class="form-control" type="number" name="items[{{ $index }}][price_per_case]" step="0.01" min="0.01" value="{{ old('items.'.$index.'.price_per_case', $product->default_price_per_case) }}" aria-label="Selling price per case for {{ $product->name }}" @unless(auth()->user()->hasRole('administrator', 'manager')) readonly @endunless></div>
            <div class="col-md-2 tiny">cases / selling price per case (KSh)</div>
        </div>
    @endforeach
    <button class="btn btn-brand mt-3">Save draft order</button>
</form>
@endsection
