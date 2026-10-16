@extends('layouts.app')
@section('page-title','Analytics')
@section('content')
<div class="row g-3">
    <div class="col-lg-6">
        <div class="panel h-100">
            <strong class="small">Sales demand trend</strong>
            <div style="height:280px"><canvas id="sales-demand"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel h-100">
            <strong class="small">Revenue trend</strong>
            <div style="height:280px"><canvas id="revenue-trend"></canvas></div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="panel h-100">
            <strong class="small">Inventory consumption</strong>
            <div style="height:210px"><canvas id="consumption"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <strong class="small">Forecast evaluation</strong>
            <table class="table mt-3">
                <thead><tr><th>Method</th><th>MAE</th><th>RMSE</th><th>MAPE</th></tr></thead>
                <tbody>
                    @foreach(['moving_average'=>'Moving Average','exponential_smoothing'=>'Exponential Smoothing'] as $key=>$label)
                        <tr><td>{{ $label }}</td><td>{{ number_format($forecast[$key]['mae'], 2) }}</td><td>{{ number_format($forecast[$key]['rmse'], 2) }}</td><td>{{ number_format($forecast[$key]['mape'], 2) }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
const salesDates = @json($dailySales->map(fn ($row) => $row->sale_date->format('d M Y'))->values());
const salesChartOptions = (unit) => ({
    maintainAspectRatio: false,
    elements: { point: { radius: 0, hitRadius: 5 } },
    scales: {
        x: { ticks: { maxTicksLimit: 10 } },
        y: { beginAtZero: true, title: { display: true, text: unit } }
    }
});
new Chart(document.getElementById('sales-demand'), {
    type: 'line',
    data: { labels: salesDates, datasets: [{ label: 'Volume (L)', data: @json($dailySales->pluck('quantity')), borderColor: '#d32f45', tension: .3 }] },
    options: salesChartOptions('Litres (L)')
});
new Chart(document.getElementById('revenue-trend'), {
    type: 'line',
    data: { labels: salesDates, datasets: [{ label: 'Revenue (KSh)', data: @json($dailySales->pluck('revenue')), borderColor: '#20a18a', tension: .3 }] },
    options: salesChartOptions('Kenyan shillings (KSh)')
});
new Chart(document.getElementById('consumption'), {
    type: 'bar',
    data: { labels: @json($movements->map(fn ($row) => $row->transaction_date->format('d M Y'))->values()), datasets: [{ label: 'Inventory OUT (L)', data: @json($movements->pluck('consumed')), backgroundColor: '#e8a32b' }] },
    options: { maintainAspectRatio: false }
});
</script>
@endsection
