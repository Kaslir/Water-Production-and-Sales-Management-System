@extends('layouts.app')
@section('page-title', 'New customer order')
@section('content')
<form method="post" action="{{ route('packaged.orders.store') }}">
    @csrf
    <section class="panel mb-3" aria-labelledby="customer-delivery-heading">
        <h1 class="h5 mb-3" id="customer-delivery-heading">Customer &amp; Delivery Details</h1>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="customer-id">Customer</label><select class="form-select" id="customer-id" name="customer_id" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer->customer_id }}" data-area="{{ $customer->delivery_area_id }}" data-address="{{ $customer->address }}" @selected(old('customer_id', $selectedCustomerId) == $customer->customer_id)>{{ $customer->name }}</option>@endforeach</select><div class="tiny mt-1"><a href="{{ route('operations.create', ['type' => 'customers']) }}">Register a new customer</a> if needed.</div></div>
            <div class="col-md-6"><label class="form-label" for="delivery-area">Delivery area (Nairobi County)</label><select class="form-select" id="delivery-area" name="delivery_area_id" required><option value="">Select area</option>@foreach($areas as $area)<option value="{{ $area->id }}" data-zone="{{ $area->zone->code }}" data-fee="{{ $area->zone->fixed_fee }}" @selected(old('delivery_area_id') == $area->id)>{{ $area->name }}</option>@endforeach</select><div class="tiny mt-1">The delivery fee is saved with this order and will not change if zone prices change later.</div></div>
            <div class="col-md-7"><label class="form-label" for="delivery-address">Specific delivery address or landmark</label><input class="form-control" id="delivery-address" name="delivery_address" value="{{ old('delivery_address') }}" required></div>
            <div class="col-md-5"><label class="form-label" for="requested-delivery">Requested delivery date</label><input class="form-control" id="requested-delivery" type="date" name="requested_delivery_on" min="{{ today()->toDateString() }}" value="{{ old('requested_delivery_on', today()->toDateString()) }}" required><div class="tiny mt-1">Reserved stock must remain unexpired through this date.</div></div>
            <div class="col-md-8"><label class="form-label" for="order-notes">Order notes (optional)</label><input class="form-control" id="order-notes" name="notes" value="{{ old('notes') }}"></div>
            <div class="col-md-4"><div class="border rounded p-2 h-100"><div class="tiny">Delivery zone and fixed fee</div><strong id="delivery-fee-display">Select a delivery area</strong></div></div>
        </div>
    </section>

    <div class="row g-3 align-items-start">
    <div class="col-xl-8">
    <section class="panel h-100" aria-labelledby="products-heading">
        <h2 class="h5" id="products-heading">Products &amp; Pricing</h2><p class="small text-muted">Enter cases for at least one product. Leave other quantities at zero. Listed prices are taken from Products &amp; Pricing.</p>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Product</th><th>Quantity in cases</th><th>Selling price per case (KSh)</th></tr></thead><tbody>
            @foreach($products as $index => $product)
            <tr data-product-row><td><label class="fw-semibold" for="cases-{{ $product->id }}">{{ $product->name }}</label><input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $product->id }}"></td><td><input class="form-control" id="cases-{{ $product->id }}" type="number" name="items[{{ $index }}][cases_ordered]" min="0" value="{{ old('items.'.$index.'.cases_ordered', 0) }}" aria-label="Cases for {{ $product->name }}"></td><td><input class="form-control" id="price-{{ $product->id }}" type="number" name="items[{{ $index }}][price_per_case]" step="0.01" min="0.01" value="{{ $product->default_price_per_case }}" aria-label="Selling price per case for {{ $product->name }}" readonly></td></tr>
            @endforeach
        </tbody></table></div>
        <input type="hidden" name="discount" value="0">
    </section>
    </div>
    <div class="col-xl-4">
    <section class="panel" aria-labelledby="summary-heading"><h2 class="h5 mb-3" id="summary-heading">Order Summary</h2><div class="d-flex justify-content-between border-bottom py-2 gap-2"><span>Product subtotal</span><strong id="order-subtotal">KSh 0.00</strong></div><div class="d-flex justify-content-between border-bottom py-2 gap-2"><span>Delivery fee (once per order)</span><strong id="order-fee">KSh 0.00</strong></div><div class="d-flex justify-content-between align-items-center py-3 fs-5 gap-2"><span>Final amount payable</span><strong id="order-total" class="text-nowrap">KSh 0.00</strong></div><p class="tiny">The server recalculates the final amount when you save the draft order. Saving a draft does not deduct stock.</p><button class="btn btn-brand">Save draft order</button></section>
    </div>
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action="{{ route('packaged.orders.store') }}"]');
    const customer = document.getElementById('customer-id');
    const area = document.getElementById('delivery-area');
    const address = document.getElementById('delivery-address');
    const money = value => 'KSh ' + value.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const refresh = () => {
        const selected = area.selectedOptions[0];
        const fee = Number(selected?.dataset.fee || 0);
        let subtotal = 0;
        form.querySelectorAll('[data-product-row]').forEach(row => {
            subtotal += Number(row.querySelector('input[name$="[cases_ordered]"]').value || 0) * Number(row.querySelector('input[name$="[price_per_case]"]').value || 0);
        });
        document.getElementById('delivery-fee-display').textContent = selected?.dataset.zone ? 'Zone ' + selected.dataset.zone + ' · ' + money(fee) : 'Select a delivery area';
        document.getElementById('order-subtotal').textContent = money(subtotal);
        document.getElementById('order-fee').textContent = money(fee);
        document.getElementById('order-total').textContent = money(subtotal + fee);
    };
    customer.addEventListener('change', () => {
        const selected = customer.selectedOptions[0];
        area.value = selected?.dataset.area || '';
        address.value = selected?.dataset.address || '';
        refresh();
    });
    form.addEventListener('input', refresh);
    area.addEventListener('change', refresh);
    if (!area.value && customer.value) { customer.dispatchEvent(new Event('change')); }
    refresh();
});
</script>
@endsection
