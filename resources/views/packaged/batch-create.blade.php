@extends('layouts.app')
@section('page-title', 'Record production batch')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.batches.store') }}">@csrf
<p>A new batch remains on quality hold until its test result is approved. The batch code, expiry date, and production costs are calculated from the approved product settings.</p>
<div class="row g-3">
<div class="col-md-4"><label class="form-label">Product</label><select class="form-select" name="product_id" required><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }} ({{ $product->bottles_per_case }} bottles / case)</option>@endforeach</select></div>
<div class="col-md-4"><label class="form-label">Produced on</label><input class="form-control" name="produced_on" type="date" value="{{ old('produced_on',today()->toDateString()) }}" required></div>
<div class="col-md-4"><label class="form-label">Cases produced</label><input class="form-control" name="cases_produced" type="number" min="1" value="{{ old('cases_produced') }}" required></div>
<div class="col-md-4"><label class="form-label" for="water-source">Water source reference</label><input class="form-control" id="water-source" name="water_source_reference" maxlength="255" value="{{ old('water_source_reference') }}" required><div class="tiny">Record the municipal intake meter or supply record used for this batch.</div></div>
<div class="col-md-4"><label class="form-label" for="water-input">Water input (L)</label><input class="form-control" id="water-input" name="water_input_litres" type="number" step="0.01" min="0.01" value="{{ old('water_input_litres') }}" required><div class="tiny">Must cover the volume bottled; treatment losses may make it higher.</div></div>
<div class="col-md-4"><label class="form-label" for="treatment-log">Treatment log reference</label><input class="form-control" id="treatment-log" name="treatment_log_reference" maxlength="255" value="{{ old('treatment_log_reference') }}" required></div>
@if(auth()->user()->hasRole('administrator','manager'))
<div class="col-12"><label class="form-label" for="unplanned-reason">Reason for unplanned production, if no approved plan covers this batch</label><input class="form-control" id="unplanned-reason" name="unplanned_reason" maxlength="500" value="{{ old('unplanned_reason') }}"><div class="tiny">An approved plan is linked automatically when it matches the product, date and quantity. A manager must document any exception.</div></div>
@else
<div class="col-12 tiny">Production staff can record a batch only when an approved plan covers the product, date and number of cases.</div>
@endif
</div><button class="btn btn-brand mt-4">Save on quality hold</button></form>
@endsection
