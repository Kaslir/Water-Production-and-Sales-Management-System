@extends('layouts.app')
@section('page-title', 'New customer order')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.orders.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="customer-id">Customer</label><select class="form-select" id="customer-id" name="customer_id" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer->customer_id }}" data-area="{{ $customer->delivery_area_id }}" data-address="{{ $customer->address }}" @selected(old('customer_id', $selectedCustomerId) == $customer->customer_id)>{{ $customer->name }}</option>@endforeach</select><div class="tiny"><a href="{{ route('operations.create', ['type' => 'customers']) }}">Register a new customer</a> if needed.</div></div>
        <div class="col-md-6"><label class="form-label" for="delivery-area">Delivery area (Nairobi County)</label><select class="form-select" id="delivery-area" name="delivery_area_id" required><option value="">Select area</option>@foreach($areas as $area)<option value="{{ $area->id }}" data-zone="{{ $area->zone->code }}" data-fee="{{ $area->zone->fixed_fee }}" @selected(old('delivery_area_id') == $area->id)>{{ $area->name }}</option>@endforeach</select><div class="tiny">You may choose another area for this order. The saved fee will not change later.</div></div>
        <div class="col-md-6"><label class="form-label" for="delivery-address">Specific delivery address or landmark</label><input class="form-control" id="delivery-address" name="delivery_address" value="{{ old('delivery_address') }}" required></div>
        <div class="col-md-4"><label class="form-label" for="requested-delivery">Requested delivery date</label><input class="form-control" id="requested-delivery" type="date" name="requested_delivery_on" min="{{ today()->toDateString() }}" value="{{ old('requested_delivery_on', today()->toDateString()) }}" required><div class="tiny">Reserved stock must remain unexpired through this date.</div></div>
        @if(auth()->user()->hasRole('administrator', 'manager'))
            <div class="col-md-4"><label class="form-label" for="order-discount">Discount (KSh)</label><input class="form-control" id="order-discount" type="number" name="discount" step="0.01" min="0" value="{{ old('discount', 0) }}"></div>
        @else
            <input type="hidden" name="discount" value="0">
        @endif
        <div class="col-md-4"><div class="border rounded p-2 h-100"><div class="tiny">Delivery zone and fixed fee</div><strong id="delivery-fee-display">Select a delivery area</strong></div></div>
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
    <div class="border rounded p-3 mt-3" aria-live="polite"><div>Product subtotal: <strong id="order-subtotal">KSh 0.00</strong></div><div>Discount: <strong id="order-discount-display">KSh 0.00</strong></div><div>Delivery fee (once per order): <strong id="order-fee">KSh 0.00</strong></div><div class="fs-5">Final amount payable: <strong id="order-total">KSh 0.00</strong></div><div class="tiny">The final amount is recalculated securely when you save.</div></div>
    <button class="btn btn-brand mt-3">Save draft order</button>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const customer = document.getElementById('customer-id');
    const area = document.getElementById('delivery-area');
    const address = document.getElementById('delivery-address');
    const discount = document.getElementById('order-discount');
    const money = value => 'KSh ' + value.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const refresh = () => {
        const selected = area.selectedOptions[0];
        const fee = Number(selected?.dataset.fee || 0);
        let subtotal = 0;
        document.querySelectorAll('input[name$="[cases_ordered]"]').forEach(quantity => {
            const row = quantity.closest('.row');
            subtotal += Number(quantity.value || 0) * Number(row.querySelector('input[name$="[price_per_case]"]').value || 0);
        });
        const reduction = Number(discount?.value || 0);
        document.getElementById('delivery-fee-display').textContent = selected?.dataset.zone ? 'Zone ' + selected.dataset.zone + ' · ' + money(fee) : 'Select a delivery area';
        document.getElementById('order-subtotal').textContent = money(subtotal);
        document.getElementById('order-discount-display').textContent = money(reduction);
        document.getElementById('order-fee').textContent = money(fee);
        document.getElementById('order-total').textContent = money(Math.max(0, subtotal - reduction + fee));
    };
    customer.addEventListener('change', () => {
        const selected = customer.selectedOptions[0];
        area.value = selected?.dataset.area || '';
        address.value = selected?.dataset.address || '';
        refresh();
    });
    document.querySelector('form[action="{{ route('packaged.orders.store') }}"]').addEventListener('input', refresh);
    area.addEventListener('change', refresh);
    if (!area.value && customer.value) { customer.dispatchEvent(new Event('change')); }
    refresh();
});
</script>
@endsection
