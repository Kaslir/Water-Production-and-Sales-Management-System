@extends('layouts.app')
@section('page-title', 'Overview')
@section('page-subtitle', 'Business performance — Last 30 days')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label text-success">AVAILABLE SALEABLE STOCK</span><span class="metric-value">{{ number_format($data['availableCases']) }} cases</span><span class="tiny">Current balance · released, unexpired and unreserved</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label text-primary">PRODUCED</span><span class="metric-value">{{ number_format($data['producedCases']) }} cases</span><span class="tiny">{{ $periodLabel }}</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label text-warning">DISPATCHED</span><span class="metric-value">{{ number_format($data['dispatchedCases']) }} cases</span><span class="tiny">{{ $periodLabel }}</span></div></div>
    <div class="col-sm-6 col-xl-3"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label text-danger">INVOICED SALES</span><span class="metric-value">@if($canSeeFinance)KSh {{ number_format($data['revenue'], 2) }}@else—@endif</span><span class="tiny">{{ $canSeeFinance ? $periodLabel.' · water and delivery' : 'Financial access restricted' }}</span></div></div>
</div>

<div class="row g-3 mb-3">
    @if(count($attention))
    <div class="col-xl-{{ $canSeeFinance ? '5' : '12' }}"><section class="panel h-100" aria-labelledby="attention-title"><h2 class="h6 mb-3" id="attention-title">Attention Required</h2>
        @foreach($attention as $item)<div class="d-flex justify-content-between align-items-center gap-2 border-top py-2"><a class="small" href="{{ $item['route'] }}">{{ $item['label'] }}</a><span class="badge {{ $item['count'] > 0 ? 'text-bg-warning' : 'text-bg-light' }}">{{ number_format($item['count']) }}</span></div>@endforeach
        @if(collect($attention)->sum('count') === 0)<p class="tiny mb-0 mt-2">No items currently need attention in these categories.</p>@endif
    </section></div>
    @endif
    @if($canSeeFinance)
    <div class="col-xl-{{ count($attention) ? '7' : '12' }}"><section class="panel h-100" aria-labelledby="financial-title"><div class="d-flex justify-content-between flex-wrap gap-2"><h2 class="h6 mb-1" id="financial-title">Financial Summary</h2><a class="small" href="{{ route('packaged.reports.index') }}">Profit &amp; expiry details</a></div><p class="tiny mb-2">Management estimates, not audited financial statements.</p>
        <div class="row g-2">
            <div class="col-sm-6"><div class="border rounded p-2 h-100"><div class="tiny">Net invoiced revenue · {{ $periodLabel }}</div><strong>KSh {{ number_format($financialSummary['revenue'], 2) }}</strong></div></div>
            <div class="col-sm-6"><div class="border rounded p-2 h-100"><div class="tiny">Gross profit · {{ $periodLabel }}</div><strong>KSh {{ number_format($financialSummary['grossProfit'], 2) }}</strong></div></div>
            <div class="col-sm-6"><div class="border rounded p-2 h-100"><div class="tiny">Estimated operating profit · {{ $periodLabel }}</div><strong>KSh {{ number_format($financialSummary['operatingProfit'], 2) }}</strong></div></div>
            <div class="col-sm-6"><div class="border rounded p-2 h-100"><div class="tiny">Outstanding customer payments · all invoices</div><strong>KSh {{ number_format($financialSummary['outstanding'], 2) }}</strong></div></div>
        </div>
    </section></div>
    @endif
</div>

@if($canSeePlanning)
<section class="panel mb-3" aria-labelledby="planning-title"><div class="d-flex justify-content-between align-items-center gap-2"><h2 class="h6 mb-0" id="planning-title">Production Planning Summary</h2><a class="small" href="{{ route('packaged.forecasts.index') }}">View production planning</a></div>
    @if($planning->isEmpty())<p class="small text-muted mb-0 mt-2">No valid current 7-day product forecasts are available.</p>
    @else<div class="row g-2 mt-1">@foreach($planning as $item)<div class="col-sm-6 col-xl-3"><div class="border rounded p-2 h-100"><div class="tiny">{{ $item['product'] }}</div><strong>{{ number_format($item['recommendedCases']) }} additional cases</strong></div></div>@endforeach</div><p class="tiny mb-0 mt-2">Uses the current 7-day Random Forest forecast and the existing stock, orders and approved-plan rules.</p>@endif
</section>
@endif

<div class="row g-3">
    @if($canSeeOrders)<div class="col-xl-7"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Recent customer orders</strong><a class="small" href="{{ route('packaged.orders.index') }}">View all</a></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th>Value</th></tr></thead><tbody>
            @forelse($data['orders'] as $order)<tr><td><a href="{{ route('packaged.orders.show', $order) }}">{{ $order->order_number }}</a></td><td>{{ $order->customer->name }}</td><td>{{ $order->ordered_on->format('d M Y') }}</td><td>@php($orderBadge = match ($order->status) {'confirmed' => 'text-bg-warning', 'dispatched' => 'text-bg-info', 'delivered', 'invoiced', 'paid' => 'text-bg-success', 'cancelled' => 'text-bg-secondary', default => 'text-bg-light'})<span class="badge {{ $orderBadge }}">{{ ucfirst($order->status) }}</span></td><td>KSh {{ number_format($order->total_amount, 2) }}</td></tr>@empty<tr><td colspan="5" class="py-4 text-muted">No customer orders yet.</td></tr>@endforelse
        </tbody></table></div>
    </div></div>@endif
    <div class="col-xl-{{ $canSeeOrders ? '5' : '12' }}"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Available cases by product</strong>@if(auth()->user()->hasRole('administrator', 'manager', 'production_staff', 'inventory_staff'))<a class="small" href="{{ route('inventory.index') }}">View inventory</a>@endif</div>
        @forelse($data['productStock'] as $stock)<div class="detail-row py-2"><span>{{ $stock->name }}</span><strong>{{ number_format($stock->cases) }} cases</strong></div>@empty<p class="small text-muted">No products configured.</p>@endforelse
        <p class="tiny mt-3 mb-0">Current balance: released, unexpired cases not reserved for an order.</p>
    </div></div>
</div>

<div class="row g-3 mt-1">
    @if($canSeeFinance)<div class="col-xl-{{ $canSeeBatches ? '7' : '12' }}"><div class="panel h-100"><div class="d-flex justify-content-between flex-wrap gap-2 mb-2"><strong class="small">Invoiced sales trend</strong><span class="tiny">{{ $periodLabel }} · daily · KSh</span></div>
        @if($data['hasDemonstrationInvoices'])<p class="tiny mb-2">Includes synthetic historical demonstration invoices.</p>@endif
        @if(! $data['hasRecentInvoices'])<p class="small text-muted mb-2">No invoices in the last 30 days. The chart shows zero sales for each day.</p>@endif
        <div style="height:230px"><canvas id="salesChart" aria-label="Invoiced sales trend for {{ $periodLabel }}"></canvas></div>
    </div></div>@endif
    @if($canSeeBatches)<div class="col-xl-{{ $canSeeFinance ? '5' : '12' }}"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Recent production batches</strong><a class="small" href="{{ route('packaged.batches.index') }}">View all</a></div>
        @forelse($data['recentBatches'] as $batch)<div class="detail-row py-2 align-items-center gap-2"><span>{{ $batch->batch_code }} · {{ $batch->product->name }}<span class="d-block tiny">Produced {{ $batch->produced_on->format('d M Y') }}</span></span><span class="text-end"><span class="badge {{ match($batch->status) {'released' => 'text-bg-success', 'quality_hold' => 'text-bg-warning', 'quarantined', 'recalled' => 'text-bg-danger', default => 'text-bg-secondary'} }}">{{ ucwords(str_replace('_', ' ', $batch->status)) }}</span> <strong>{{ number_format($batch->cases_produced) }} cases</strong></span></div>@empty<p class="text-muted small">No production batches yet.</p>@endforelse
    </div></div>@endif
</div>

@if($canSeeFinance)
<script>
const money = new Intl.NumberFormat('en-KE', {maximumFractionDigits: 0});
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {labels: @json($data['salesChart']->pluck('label')), datasets: [{label: 'Invoiced sales', data: @json($data['salesChart']->pluck('total')), borderColor: '#b42332', backgroundColor: '#b4233218', fill: true, tension: .3, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4}]},
    options: {maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {display: false}, tooltip: {callbacks: {label: context => 'Invoiced sales: KSh ' + money.format(context.parsed.y)}}}, scales: {x: {ticks: {autoSkip: true, maxTicksLimit: 8, maxRotation: 0}}, y: {beginAtZero: true, ticks: {callback: value => 'KSh ' + money.format(value)}}}}
});
</script>
@endif
@endsection
