@extends('layouts.app')
@section('page-title', 'Manufacturing settings')
@section('content')
<div class="panel mb-3">
    <strong>Approved product settings</strong>
    <p class="mb-0 mt-2">Set a documented shelf life and estimated cost per case for each product. Use the company's approved shelf-life study or specification as the evidence reference. These settings apply to new batches from the effective date; existing batches retain their original expiry and costs.</p>
</div>
@foreach ($products as $product)
@php($latest = $policies->get($product->id)?->first())
<div class="panel mb-3">
    <h5>{{ $product->name }}</h5>
    @if ($latest)
    <p class="tiny">Latest approved version: {{ $latest->effective_from->format('d M Y') }} · shelf life {{ $latest->shelf_life_days }} days · evidence: {{ $latest->shelf_life_evidence }}</p>
    @else
    <p class="tiny">No approved settings yet. Production staff cannot record a new batch of this product until settings are approved.</p>
    @endif
    <form method="post" action="{{ route('packaged.manufacturing-settings.store', $product) }}">@csrf
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Effective from</label><input class="form-control" type="date" name="effective_from" value="{{ old('effective_from', today()->toDateString()) }}" required></div>
            <div class="col-md-4"><label class="form-label">Shelf life (days)</label><input class="form-control" type="number" min="1" max="3650" name="shelf_life_days" value="{{ old('shelf_life_days', $latest?->shelf_life_days) }}" required></div>
            <div class="col-md-4"><label class="form-label">Shelf-life evidence or document reference</label><input class="form-control" name="shelf_life_evidence" maxlength="500" value="{{ old('shelf_life_evidence', $latest?->shelf_life_evidence) }}" required></div>
            @foreach (['water_treatment_per_case' => 'Treatment', 'bottle_per_case' => 'Bottles', 'inbound_transport_per_case' => 'Inbound packaging transport', 'cap_label_per_case' => 'Caps and labels', 'labour_per_case' => 'Labour', 'electricity_per_case' => 'Electricity', 'other_production_per_case' => 'Other production'] as $field => $label)
            <div class="col-md-4"><label class="form-label">{{ $label }} per case (KSh)</label><input class="form-control" type="number" min="0" step="0.01" name="{{ $field }}" value="{{ old($field, $latest?->{$field} ?? 0) }}" required></div>
            @endforeach
        </div>
        <button class="btn btn-brand mt-3">Approve new settings</button>
    </form>
</div>
@endforeach
@endsection
