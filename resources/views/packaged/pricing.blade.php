@extends('layouts.app')
@section('page-title', 'Products & pricing')
@section('content')
<style>
    .pricing-page .selling-price{font-size:1.6rem;font-weight:700;line-height:1.2;color:#173a57}
    .pricing-page .price-meta{font-size:.8rem;color:#6f7f8e}
</style>
<div class="pricing-page">
    <div class="panel mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Default selling prices</strong>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#priceHistory" aria-expanded="{{ request()->has('page') ? 'true' : 'false' }}" aria-controls="priceHistory">Price History</button>
        </div>
        <p class="mb-0 mt-2">Prices are for one case. A changed price is used for new orders; existing orders keep the price already recorded on each order line.</p>
    </div>

    <section id="priceHistory" class="panel collapse {{ request()->has('page') ? 'show' : '' }} mb-3" aria-labelledby="price-history-title">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div><h2 id="price-history-title" class="h5 mb-1">Price History</h2><p class="small text-secondary mb-0">Saved changes to default selling prices. Historical customer orders keep their original line prices.</p></div>
            <span class="badge text-bg-light border">{{ $priceHistory->total() }} changes</span>
        </div>
        @if ($priceHistory->isEmpty())
            <div class="text-secondary small border rounded p-3">No selling price changes have been recorded yet.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">Product</th><th scope="col">Previous price</th><th scope="col">New price</th><th scope="col">Changed on</th><th scope="col">Changed by</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($priceHistory as $change)
                            @php
                                $changedProduct = $products->firstWhere('id', $change->subject_id);
                            @endphp
                            <tr>
                                <th scope="row">{{ $changedProduct?->name ?? 'Product record unavailable' }}</th>
                                <td>KSh {{ number_format((float) ($change->before_values['price'] ?? 0), 2) }}</td>
                                <td class="fw-semibold">KSh {{ number_format((float) ($change->after_values['price'] ?? 0), 2) }}</td>
                                <td>{{ $change->created_at?->format('d M Y, H:i') ?? 'Date unavailable' }}</td>
                                <td>{{ $change->actor?->name ?? 'User record unavailable' }}</td>
                                <td class="text-break">{{ $change->reason ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $priceHistory->links() }}</div>
        @endif
    </section>

    <div class="row g-3">
        @foreach ($products as $product)
            @php
                $sellingPrice = (float) $product->default_price_per_case;
                $showOldInput = (int) old('editing_product_id') === $product->id;
            @endphp
            <div class="col-lg-6">
                <div class="panel h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 mb-1">{{ $product->name }}</h2>
                            <div class="price-meta">{{ $product->bottles_per_case }} {{ $product->bottles_per_case === 1 ? 'bottle' : 'bottles' }} per case</div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-price-{{ $product->id }}" aria-expanded="{{ $showOldInput ? 'true' : 'false' }}" aria-controls="edit-price-{{ $product->id }}">Edit Price</button>
                    </div>

                    <div class="mt-3">
                        <div class="price-meta">Current selling price</div>
                        <div class="selling-price">KSh {{ number_format($sellingPrice, 2) }} <span class="fs-6 fw-normal text-secondary">/ case</span></div>
                    </div>

                    <div id="edit-price-{{ $product->id }}" class="collapse {{ $showOldInput ? 'show' : '' }} mt-3 border-top pt-3">
                        <form method="post" action="{{ route('packaged.products.price', $product) }}" data-price-form data-current-price="{{ $product->default_price_per_case }}" data-product-name="{{ $product->name }}">
                            @csrf
                            <input type="hidden" name="editing_product_id" value="{{ $product->id }}">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="price-{{ $product->id }}">Selling price per case (KSh)</label>
                                    <input class="form-control {{ $showOldInput && $errors->has('default_price_per_case') ? 'is-invalid' : '' }}" id="price-{{ $product->id }}" name="default_price_per_case" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" value="{{ $showOldInput ? old('default_price_per_case') : $product->default_price_per_case }}" required aria-describedby="price-help-{{ $product->id }}">
                                    <div id="price-help-{{ $product->id }}" class="form-text">Enter a positive amount with no more than two decimal places.</div>
                                    @if ($showOldInput) @error('default_price_per_case')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="price-reason-{{ $product->id }}">Reason (optional)</label>
                                    <input class="form-control {{ $showOldInput && $errors->has('reason') ? 'is-invalid' : '' }}" id="price-reason-{{ $product->id }}" name="reason" maxlength="500" value="{{ $showOldInput ? old('reason') : '' }}" aria-describedby="reason-help-{{ $product->id }}">
                                    <div id="reason-help-{{ $product->id }}" class="form-text">For example, a documented price review or contract update.</div>
                                    @if ($showOldInput) @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-brand" type="submit">Save</button>
                                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-price-{{ $product->id }}" aria-controls="edit-price-{{ $product->id }}">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

</div>

<div class="modal fade" id="confirmPriceModal" tabindex="-1" aria-labelledby="confirmPriceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="confirmPriceTitle">Confirm selling price change</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="mb-3">Review the price for <strong id="confirmProductName"></strong> before saving.</p>
                <div class="d-flex justify-content-between border-bottom py-2"><span>Previous price</span><strong id="confirmOldPrice"></strong></div>
                <div class="d-flex justify-content-between py-2"><span>Proposed new price</span><strong id="confirmNewPrice"></strong></div>
                <p class="small text-secondary mb-0 mt-2">The new price applies to new orders only.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-brand" id="confirmPriceSave">Confirm and save</button></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('confirmPriceModal');
    const save = document.getElementById('confirmPriceSave');
    const money = value => `KSh ${new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
    let pendingForm = null;
    document.querySelectorAll('[data-price-form]').forEach(form => {
        form.addEventListener('submit', event => {
            event.preventDefault();
            const proposed = Number(form.elements.default_price_per_case.value);
            const previous = Number(form.dataset.currentPrice);
            if (!Number.isFinite(proposed) || proposed <= 0 || !Number.isFinite(previous)) return;
            document.getElementById('confirmProductName').textContent = form.dataset.productName;
            document.getElementById('confirmOldPrice').textContent = money(previous);
            document.getElementById('confirmNewPrice').textContent = money(proposed);
            pendingForm = form;
            if (window.bootstrap?.Modal) {
                bootstrap.Modal.getOrCreateInstance(dialog).show();
            } else if (window.confirm(`Change ${form.dataset.productName} from ${money(previous)} to ${money(proposed)}?`)) {
                form.submit();
            }
        });
    });
    save.addEventListener('click', () => pendingForm?.submit());
    dialog.addEventListener('hidden.bs.modal', () => { pendingForm = null; });
});
</script>
@endsection
