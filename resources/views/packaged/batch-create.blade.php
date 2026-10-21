@extends('layouts.app')
@section('page-title', 'Record production batch')
@section('content')
<form class="panel" method="post" action="{{ route('packaged.batches.store') }}">@csrf
<p>A new batch remains on quality hold until its test result is approved. Production costs are recorded for the whole batch.</p>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Product</label><select class="form-select" name="product_id" required><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->bottles_per_case }} bottles / case)</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Approved production plan (optional)</label><select class="form-select" name="production_forecast_decision_id"><option value="">Not part of an approved plan</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected(old('production_forecast_decision_id') == $plan->id)>{{ $plan->forecast->product->name }} · {{ $plan->planned_for->format('d M Y') }} · {{ number_format($plan->approved_cases - $plan->cases_completed) }} cases remaining</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Batch code</label><input class="form-control" name="batch_code" value="{{ old('batch_code') }}" required></div>
<div class="col-md-4"><label class="form-label">Produced on</label><input class="form-control" name="produced_on" type="date" value="{{ old('produced_on',today()->toDateString()) }}" required></div>
<div class="col-md-4"><label class="form-label">Expires on</label><input class="form-control" name="expires_on" type="date" value="{{ old('expires_on') }}" required></div>
<div class="col-md-4"><label class="form-label">Cases produced</label><input class="form-control" name="cases_produced" type="number" min="1" value="{{ old('cases_produced') }}" required></div>
@foreach(['water_treatment_cost'=>'Treatment','bottle_cost'=>'Bottles','inbound_transport_cost'=>'Inbound packaging transport','cap_label_cost'=>'Caps and labels','labour_cost'=>'Labour','electricity_cost'=>'Electricity','other_production_cost'=>'Other production'] as $field=>$label)
<div class="col-md-4"><label class="form-label">{{ $label }} cost (KSh)</label><input class="form-control" name="{{ $field }}" type="number" min="0" step="0.01" value="{{ old($field,0) }}" required></div>
@endforeach
</div><button class="btn btn-brand mt-4">Save on quality hold</button></form>
@endsection
