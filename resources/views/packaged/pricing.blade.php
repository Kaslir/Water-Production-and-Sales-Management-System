@extends('layouts.app')
@section('page-title', 'Products & pricing')
@section('content')
<div class="panel mb-3">
    <strong>Default selling prices</strong>
    <p class="mb-0 mt-2">Set the selling price for one case of each product. New orders start with this price. Prices already recorded on existing orders stay the same.</p>
</div>
<div class="row g-3">
    @foreach($products as $product)
    <div class="col-xl-6">
        <div class="panel h-100">
            <h5>{{ $product->name }}</h5>
            <p class="tiny">{{ $product->bottles_per_case }} bottles per case · Current selling price: KSh {{ number_format($product->default_price_per_case, 2) }} per case</p>
            <form method="post" action="{{ route('packaged.products.price', $product) }}">@csrf
                <label class="form-label" for="price-{{ $product->id }}">Selling price per case (KSh)</label>
                <div class="d-flex gap-2">
                    <input class="form-control" id="price-{{ $product->id }}" name="default_price_per_case" type="number" min="0.01" step="0.01" value="{{ $product->default_price_per_case }}" required>
                    <button class="btn btn-brand" type="submit">Save price</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endsection
