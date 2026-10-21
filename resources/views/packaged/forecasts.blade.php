@extends('layouts.app')
@section('page-title', 'Production planning')
@section('content')
@php($lastOrder = $latestOrderDate ? \Carbon\Carbon::parse($latestOrderDate) : null)
<div class="alert alert-info">Forecasts compare Random Forest and Gradient Boosting on recorded packaged-water orders. The demonstration dataset is synthetic, so its model scores do not prove accuracy at a real company. @if($lastOrder)Last recorded order: {{ $lastOrder->format('d M Y') }}.@endif</div>
@if($lastOrder && $lastOrder->lt(today()->subDays(14)))<div class="alert alert-warning">There are no recent orders for a current forecast. You may evaluate the historical dataset as of {{ $lastOrder->format('d M Y') }}. Those dated estimates are a demonstration, not a prediction for today.</div>@endif
<div class="row g-3">
@foreach($products as $product)
    @php($group = $latestForecasts[$product->id] ?? collect())
    @php($origin = $group->first()?->origin_date)
    @php($isCurrent = $origin?->isToday() && ($group->first()?->evaluation['mode'] ?? null) === 'current')
    <div class="col-xl-6"><div class="panel h-100">
        <div class="d-flex justify-content-between gap-3"><div><h5>{{ $product->name }}</h5><div class="tiny">{{ $product->bottles_per_case }} bottles per case · Released stock: {{ number_format($stock[$product->id] ?? 0) }} cases</div></div>
            @if(auth()->user()->hasRole('administrator', 'manager'))<form method="post" action="{{ route('packaged.forecasts.run') }}">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="mode" value="{{ $lastOrder && $lastOrder->lt(today()->subDays(14)) ? 'historical_demo' : 'current' }}"><button class="btn btn-brand btn-sm">{{ $lastOrder && $lastOrder->lt(today()->subDays(14)) ? 'Evaluate historical data' : 'Evaluate models' }}</button></form>@endif
        </div><hr>
        @if($origin)<p class="tiny mb-2">{{ $isCurrent ? 'Current forecast' : 'Historical demonstration' }} · Model origin: {{ $origin->format('d M Y') }}</p>@endif
        @foreach([7 => '7-day demand', 30 => '30-day demand'] as $days => $label)
            @php($rows = $group->where('horizon_days', $days))
            @php($chosen = $rows->first(fn ($row) => $row->evaluation['selected'] ?? false))
            @php($plan = $planning[$product->id][$days] ?? null)
            <h6>{{ $label }}</h6>
            @if($chosen)
                <p class="mb-1">Estimated demand: <strong>{{ number_format($chosen->predicted_cases, 0) }} cases</strong> · {{ $origin->addDay()->format('d M Y') }}–{{ $origin->addDays($days)->format('d M Y') }}</p>
                @if($plan)<p class="tiny mb-2">{{ number_format($plan['eligible_stock']) }} eligible cases in stock; {{ number_format($plan['confirmed_cases']) }} cases on confirmed orders; {{ number_format($plan['planned_cases']) }} cases in an approved, unfinished production plan. Additional suggested production: <strong>{{ number_format($plan['recommended_cases']) }} cases</strong>.</p>
                @else<p class="tiny mb-2">This historical result is for model demonstration only. No present-day production quantity is recommended from it.</p>@endif
                <table class="table"><thead><tr><th>Method</th><th>MAE (cases)</th><th>Bias (cases)</th></tr></thead><tbody>@foreach($rows as $row)<tr @if($row->id === $chosen->id) class="table-success" @endif><td>{{ str_replace('_', ' ', ucwords($row->method, '_')) }}</td><td>{{ number_format($row->mae_cases, 2) }}</td><td>{{ number_format($row->bias_cases, 2) }}</td></tr>@endforeach</tbody></table>
            @else<p class="tiny">No evaluated forecast for this period yet.</p>@endif
        @endforeach
    </div></div>
@endforeach
</div>
<div class="panel mt-3"><h6>Stock nearing expiry</h6><table class="table"><thead><tr><th>Batch</th><th>Product</th><th>Expiry</th><th>Cases</th></tr></thead><tbody>@forelse($nearExpiry as $batch)<tr><td>{{ $batch->batch_code }}</td><td>{{ $batch->product->name }}</td><td>{{ $batch->expires_on->format('d M Y') }}</td><td>{{ number_format($batch->cases_on_hand) }}</td></tr>@empty<tr><td colspan="4">No near-expiry batches.</td></tr>@endforelse</tbody></table></div>
@if(auth()->user()->hasRole('administrator', 'manager'))
<div class="panel mt-3"><h6>Approve a current production plan</h6><p class="tiny">Only current forecasts can become plans. A new approval replaces the previous unfinished plan for that product. Link the resulting batch to the plan when recording production.</p>
    @foreach($products as $product)@foreach([7, 30] as $days)
        @php($selected = ($latestForecasts[$product->id] ?? collect())->first(fn ($row) => $row->horizon_days == $days && ($row->evaluation['selected'] ?? false) && $row->origin_date->isToday() && ($row->evaluation['mode'] ?? null) === 'current'))
        @php($plan = $planning[$product->id][$days] ?? null)
        @if($selected && $plan)<form class="row g-2 align-items-end mb-3" method="post" action="{{ route('packaged.forecasts.decision', $selected) }}">@csrf<div class="col-md-3"><strong>{{ $product->name }}</strong><div class="tiny">{{ $days }} days · {{ number_format($plan['recommended_cases']) }} additional cases needed</div></div><div class="col-md-2"><label class="form-label">Total cases to plan</label><input class="form-control" type="number" name="approved_cases" min="0" value="{{ $plan['recommended_cases'] + $plan['planned_cases'] }}" required></div><div class="col-md-2"><label class="form-label">Planned completion</label><input class="form-control" type="date" name="planned_for" min="{{ today()->toDateString() }}" max="{{ today()->addDays($days)->toDateString() }}" value="{{ today()->addDays(min(3, $days))->toDateString() }}" required></div><div class="col-md-3"><label class="form-label">Reason if changing suggestion</label><input class="form-control" name="reason"></div><div class="col-md-2"><button class="btn btn-brand">Approve plan</button></div></form>@endif
    @endforeach
    @endforeach
</div>
@endif
@endsection
