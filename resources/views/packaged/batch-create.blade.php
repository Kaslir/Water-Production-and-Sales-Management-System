@extends('layouts.app')
@section('page-title', 'Record production batch')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.batches.store') }}">@csrf
<p>A new batch remains on quality hold until its test result is approved. The batch code, expiry date, and production costs are calculated from the approved product settings.</p>
<div class="row g-3">
<div class="col-md-4"><label class="form-label">Product</label><select class="form-select" name="product_id" required><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }} ({{ $product->bottles_per_case }} bottles / case)</option>@endforeach</select></div>
<div class="col-md-4"><label class="form-label">Produced on</label><input class="form-control" name="produced_on" type="date" value="{{ old('produced_on',today()->toDateString()) }}" required></div>
<div class="col-md-4"><label class="form-label">Cases produced</label><input class="form-control" name="cases_produced" type="number" min="1" value="{{ old('cases_produced') }}" required></div>
</div><button class="btn btn-brand mt-4">Save on quality hold</button></form>
@endsection
