@extends('layouts.app')
@section('page-title', 'Record production batch')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.batches.store') }}">@csrf
<p>A new batch remains on quality hold until a manager approves it for sale.</p>
<div class="row g-3">
<div class="col-md-4"><label class="form-label">Product</label><select class="form-select" name="product_id" required><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }} ({{ $product->bottles_per_case }} bottles / case)</option>@endforeach</select></div>
<div class="col-md-4"><label class="form-label">Produced on</label><input class="form-control" name="produced_on" type="date" value="{{ old('produced_on',today()->toDateString()) }}" required></div>
<div class="col-md-4"><label class="form-label">Cases produced</label><input class="form-control" name="cases_produced" type="number" min="1" value="{{ old('cases_produced') }}" required></div>
@if(auth()->user()->hasRole('administrator','manager'))
<fieldset class="col-12"><legend class="form-label fs-6">Approve production without a matching plan?</legend><div class="d-flex gap-4"><div class="form-check"><input class="form-check-input" type="radio" name="unplanned_approval" id="unplanned-no" value="0" @checked(old('unplanned_approval', '0') === '0')><label class="form-check-label" for="unplanned-no">No</label></div><div class="form-check"><input class="form-check-input" type="radio" name="unplanned_approval" id="unplanned-yes" value="1" @checked(old('unplanned_approval') === '1')><label class="form-check-label" for="unplanned-yes">Yes</label></div></div><div class="tiny">A matching approved plan is linked automatically. Select Yes only to approve production outside a plan.</div></fieldset>
@else
<div class="col-12 tiny">Production staff can record a batch only when an approved plan covers the product, date and number of cases.</div>
@endif
</div><button class="btn btn-brand mt-4">Save</button></form>
@endsection
