@extends('layouts.app')
@section('page-title', 'Production planning')
@section('content')
<style>
    .forecast-period-tabs .nav-link { color: #8b1e2d; }
    .forecast-period-tabs .nav-link.active { background: #b42332; color: #fff; }
</style>
@php($lastOrder = $latestOrderDate ? \Carbon\Carbon::parse($latestOrderDate) : null)
@if($lastOrder && $lastOrder->lt(today()->subDays(14)))
    <div class="alert alert-warning">There are no recent orders for a current forecast. You may evaluate the historical dataset as of {{ $lastOrder->format('d M Y') }}. Those dated estimates are a demonstration, not a prediction for today.</div>
@endif

<div class="panel mb-3 pb-0">
    <nav class="nav nav-tabs border-0 align-items-center" aria-label="Production planning sections">
        <a class="nav-link {{ $tab === 'forecasts' ? 'active' : '' }}" href="{{ route('packaged.forecasts.index', ['tab' => 'forecasts']) }}" @if($tab === 'forecasts') aria-current="page" @endif>Forecasts</a>
        @if(auth()->user()->hasRole('administrator', 'manager'))
            <a class="nav-link {{ $tab === 'plans' ? 'active' : '' }}" href="{{ route('packaged.forecasts.index', ['tab' => 'plans']) }}" @if($tab === 'plans') aria-current="page" @endif>Approve a current production plan</a>
        @endif
        @if($tab === 'forecasts' && auth()->user()->hasRole('administrator', 'manager'))
            <form class="ms-auto mb-2" method="post" action="{{ route('packaged.forecasts.run') }}" onsubmit="const button = this.querySelector('button'); button.disabled = true; button.textContent = 'Generating forecasts…';">
                @csrf
                <input type="hidden" name="mode" value="{{ $lastOrder && $lastOrder->lt(today()->subDays(14)) ? 'historical_demo' : 'current' }}">
                <button class="btn btn-brand" type="submit">Generate Forecasts</button>
            </form>
        @endif
    </nav>
</div>

@if($tab === 'forecasts')
    <div class="panel mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1">Customer order forecast</h5>
                <p class="text-muted small mb-0">Choose a period to see expected orders for each bottled-water product.</p>
            </div>
        </div>
        <ul class="nav nav-pills gap-2 forecast-period-tabs" id="forecast-period-tabs" role="tablist" aria-label="Forecast period">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="forecast-week-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#forecast-week" aria-controls="forecast-week" aria-selected="true">Next 7 days</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="forecast-month-tab" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#forecast-month" aria-controls="forecast-month" aria-selected="false">Next 30 days</button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="forecast-period-content">
        <div class="tab-pane fade show active" id="forecast-week" role="tabpanel" aria-labelledby="forecast-week-tab" tabindex="0">
            <div class="row g-3">
                @foreach($products as $product)
                    @php($group = $latestForecasts[$product->id] ?? collect())
                    @php($week = $group->first(fn ($row) => $row->horizon_days === 7 && ($row->evaluation['selected'] ?? false)))
                    @php($plan = $planning[$product->id][7] ?? null)
                    @php($availableStock = $plan['eligible_stock'] ?? ($stock[$product->id] ?? 0))
                    <div class="col-xl-6">
                        <div class="panel h-100">
                            <h5 class="mb-3">{{ $product->name }}</h5>
                            <div class="row g-2">
                                <div class="col-sm-4">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted mb-1">Expected customer orders</div>
                                        <div class="fs-4 fw-bold">{{ $week ? number_format($week->predicted_cases, 0) : '—' }} <span class="fs-6 fw-normal">cases</span></div>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted mb-1">Available stock</div>
                                        <div class="fs-4 fw-bold">{{ number_format($availableStock) }} <span class="fs-6 fw-normal">cases</span></div>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted mb-1">Recommended additional production</div>
                                        <div class="fs-4 fw-bold">{{ $plan ? number_format($plan['recommended_cases']) : '—' }} <span class="fs-6 fw-normal">cases</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                @if($plan)
                                    <span class="badge {{ $plan['recommended_cases'] > 0 ? 'text-bg-warning' : 'text-bg-success' }} fs-6 fw-normal">{{ $plan['recommended_cases'] > 0 ? 'Additional production recommended' : 'Stock sufficient' }}</span>
                                @elseif($week)
                                    <span class="badge text-bg-secondary fs-6 fw-normal">{{ ($week->evaluation['mode'] ?? null) === 'historical_demo' ? 'Historical forecast — no current production recommendation' : 'No current production recommendation' }}</span>
                                @else
                                    <span class="badge text-bg-secondary fs-6 fw-normal">Forecast not available yet</span>
                                @endif
                            </div>
                            @if($plan)
                                <details class="mt-3 border-top pt-3">
                                    <summary class="small fw-semibold">How was this recommendation calculated?</summary>
                                    <p class="small text-muted mt-2 mb-0">
                                        Expected orders: {{ number_format($week->predicted_cases, 0) }} cases.
                                        Already recorded this week: {{ number_format($plan['recorded_cases']) }} cases.
                                        Remaining expected orders: {{ number_format($plan['remaining_forecast_cases'], 0) }} cases.
                                        Safety allowance: {{ number_format($plan['buffer_cases']) }} cases.
                                        Confirmed orders: {{ number_format($plan['confirmed_cases']) }} cases.
                                        Confirmed cases without a reservation: {{ number_format($plan['unreserved_confirmed_cases']) }} cases.
                                        Available stock for this period: {{ number_format($plan['eligible_stock']) }} cases.
                                        Unfinished approved production: {{ number_format($plan['planned_cases']) }} cases.
                                        Reserved confirmed cases are excluded from available stock. The recommendation adds any confirmed cases without a reservation to remaining expected orders and the safety allowance, then subtracts available unreserved stock and unfinished approved production. It cannot be below zero.
                                    </p>
                                </details>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="tab-pane fade" id="forecast-month" role="tabpanel" aria-labelledby="forecast-month-tab" tabindex="0">
            <p class="small text-muted mb-3">This longer-range view is for review only. Production-plan approval uses the 7-day forecast.</p>
            <div class="row g-3">
                @foreach($products as $product)
                    @php($group = $latestForecasts[$product->id] ?? collect())
                    @php($month = $group->first(fn ($row) => $row->horizon_days === 30 && ($row->evaluation['selected'] ?? false)))
                    <div class="col-md-6 col-xl-3">
                        <div class="panel h-100">
                            <h5 class="mb-3">{{ $product->name }}</h5>
                            <div class="small text-muted mb-1">Expected customer orders in 30 days</div>
                            <div class="fs-3 fw-bold">{{ $month ? number_format($month->predicted_cases, 0) : '—' }} <span class="fs-6 fw-normal">cases</span></div>
                            @unless($month)<p class="small text-muted mb-0 mt-2">Forecast not available yet.</p>@endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if(auth()->user()->hasRole('administrator', 'manager', 'auditor'))
        <div class="panel mt-3">
                <h6>Completed current forecasts</h6>
                <p class="small text-muted">When a week finishes, recorded orders are compared with the forecast saved before that week. Negative error means demand was underestimated. Historical demonstrations are excluded.</p>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Product</th><th>Week</th><th>Forecast cases</th><th>Actual cases</th><th>Error cases</th></tr></thead>
                        <tbody>
                            @forelse($completedEvaluations as $evaluation)
                                <tr><td>{{ $evaluation['product'] }}</td><td>{{ $evaluation['week_start']->format('d M Y') }}–{{ $evaluation['week_end']->format('d M Y') }}</td><td>{{ number_format($evaluation['predicted_cases'], 0) }}</td><td>{{ number_format($evaluation['actual_cases']) }}</td><td>{{ number_format($evaluation['error_cases'], 1) }}</td></tr>
                            @empty
                                <tr><td colspan="5">No completed current forecast is available yet. Record new orders and evaluate a current week to begin this check.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
        </div>
    @endif
@endif

@if($tab === 'plans' && auth()->user()->hasRole('administrator', 'manager'))
    <div class="panel">
        <h6>Approve a current production plan</h6>
        @if(empty($planning))<p class="mb-3">No current forecast is ready for approval. Run a forecast from the Forecasts tab first.</p>@endif
        @foreach($products as $product)@foreach([7] as $days)
            @php($selected = ($latestForecasts[$product->id] ?? collect())->first(fn ($row) => $row->horizon_days == $days && ($row->evaluation['selected'] ?? false) && \Carbon\CarbonImmutable::parse($row->origin_date)->lt(today()) && \Carbon\CarbonImmutable::parse($row->origin_date)->addDays(7)->gte(today()) && ($row->evaluation['mode'] ?? null) === 'current'))
            @php($plan = $planning[$product->id][$days] ?? null)
            @if($selected && $plan)
                <form class="row g-2 align-items-end mb-3" method="post" action="{{ route('packaged.forecasts.decision', $selected) }}">
                    @csrf
                    <div class="col-md-4"><strong>{{ $product->name }}</strong><div class="tiny">This week · {{ number_format($plan['recommended_cases']) }} additional cases needed</div></div>
                    <div class="col-md-3"><label class="form-label">Total cases to plan</label><input class="form-control" type="number" name="approved_cases" min="0" value="{{ $plan['recommended_cases'] + $plan['planned_cases'] }}" required></div>
                    <div class="col-md-3"><label class="form-label">Planned completion</label><input class="form-control" type="date" name="planned_for" min="{{ today()->toDateString() }}" max="{{ \Carbon\CarbonImmutable::parse($selected->origin_date)->addDays(7)->toDateString() }}" value="{{ today()->toDateString() }}" required></div>
                    <div class="col-md-2"><button class="btn btn-brand">Approve plan</button></div>
                </form>
            @endif
        @endforeach
        @endforeach
    </div>
@endif
@endsection
