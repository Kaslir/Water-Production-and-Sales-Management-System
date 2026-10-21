@extends('layouts.app')
@section('page-title', 'Analytics')
@section('content')
<form method="get" action="{{ route('analytics.index') }}" class="toolbar mb-3">
    <label for="analytics-period" class="form-label mb-0">Time:</label>
    <select id="analytics-period" name="period" class="form-select w-auto">
        @foreach(['week' => 'Past 1 week', 'month' => 'Past 1 month', '3months' => 'Past 3 months', '6months' => 'Past 6 months', 'year' => 'Past 1 year', 'all' => 'All time'] as $value => $label)
            <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="btn btn-brand">Apply</button>
    @if($latestDate)<span class="tiny ms-auto">Latest recorded activity: {{ $latestDate->format('d M Y') }}</span>@endif
</form>
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="metric"><span class="metric-label">CASES ORDERED</span><span class="metric-value">{{ number_format($totalCasesOrdered) }}</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">LITRES ORDERED</span><span class="metric-value">{{ number_format($totalLitresOrdered) }} L</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">INVOICED SALES</span><span class="metric-value">KSh {{ number_format($totalInvoiced) }}</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label">AVERAGE CASES / RECORDED ORDER DAY</span><span class="metric-value">{{ number_format($averageCasesPerRecordedDay, 1) }}</span></div></div>
</div>
<div class="row g-3">
    <div class="col-lg-6"><div class="panel"><strong class="small">Customer order demand</strong><div style="height:280px">@if($dailyDemand->isNotEmpty())<canvas id="demand-chart"></canvas>@else<p class="tiny mt-3">No orders in this period.</p>@endif</div></div></div>
    <div class="col-lg-6"><div class="panel"><strong class="small">Invoiced revenue</strong><div style="height:280px">@if($dailyRevenue->isNotEmpty())<canvas id="revenue-chart"></canvas>@else<p class="tiny mt-3">No invoices in this period.</p>@endif</div></div></div>
    <div class="col-lg-8"><div class="panel"><strong class="small">Inventory out at dispatch</strong><div style="height:250px">@if($dailyDispatches->isNotEmpty())<canvas id="dispatch-chart"></canvas>@else<p class="tiny mt-3">No dispatches in this period.</p>@endif</div></div></div>
    <div class="col-lg-4"><div class="panel h-100"><strong class="small">Demand by bottled-water product</strong><table class="table mt-3"><thead><tr><th>Product</th><th>Cases</th><th>Litres</th></tr></thead><tbody>@forelse($productDemand as $product)<tr><td>{{ $product['name'] }}</td><td>{{ number_format($product['cases']) }}</td><td>{{ number_format($product['litres']) }}</td></tr>@empty<tr><td colspan="3">No orders in this period.</td></tr>@endforelse</tbody></table><p class="tiny">Order demand, invoiced sales, and dispatches are different stages of the workflow; they are not added together.</p><a href="{{ route('packaged.forecasts.index') }}">View product forecasts and production planning</a></div></div>
</div>
<script>
const chartOptions = (unit) => ({maintainAspectRatio:false,elements:{point:{radius:0,hitRadius:5}},scales:{x:{ticks:{maxTicksLimit:10}},y:{beginAtZero:true,title:{display:true,text:unit}}}});
if(document.getElementById('demand-chart')) new Chart(document.getElementById('demand-chart'),{type:'line',data:{labels:@json($dailyDemand->pluck('day')->map(fn ($day) => \Carbon\Carbon::parse($day)->format('d M Y'))),datasets:[{label:'Cases ordered',data:@json($dailyDemand->pluck('cases')),borderColor:'#b42332',tension:.25}]},options:chartOptions('Cases')});
if(document.getElementById('revenue-chart')) new Chart(document.getElementById('revenue-chart'),{type:'line',data:{labels:@json($dailyRevenue->pluck('day')->map(fn ($day) => \Carbon\Carbon::parse($day)->format('d M Y'))),datasets:[{label:'Invoiced sales (KSh)',data:@json($dailyRevenue->pluck('revenue')),borderColor:'#20a18a',tension:.25}]},options:chartOptions('KSh')});
if(document.getElementById('dispatch-chart')) new Chart(document.getElementById('dispatch-chart'),{type:'bar',data:{labels:@json($dailyDispatches->pluck('day')->map(fn ($day) => \Carbon\Carbon::parse($day)->format('d M Y'))),datasets:[{label:'Cases dispatched',data:@json($dailyDispatches->pluck('cases')),backgroundColor:'#e8a32b'}]},options:chartOptions('Cases')});
</script>
@endsection
