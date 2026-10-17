@extends('layouts.app')
@section('page-title','Analytics')
@section('content')
<form method="get" action="{{ route('analytics.index') }}" class="toolbar mb-3">
    <label for="analytics-period" class="form-label mb-0">Time:</label>
    <select id="analytics-period" name="period" class="form-select w-auto">
        <option value="week" @selected($period === 'week')>Past 1 week</option>
        <option value="month" @selected($period === 'month')>Past 1 month</option>
        <option value="3months" @selected($period === '3months')>Past 3 months</option>
        <option value="6months" @selected($period === '6months')>Past 6 months</option>
        <option value="year" @selected($period === 'year')>Past 1 year</option>
        <option value="all" @selected($period === 'all')>All time</option>
    </select>
    <button type="submit" class="btn btn-brand">Apply</button>
</form>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="metric"><span class="metric-label">TOTAL LITRES SOLD</span><span class="metric-value">{{ number_format($totalLitresSold, 2) }} L</span></div></div>
    <div class="col-md-4"><div class="metric"><span class="metric-label">TOTAL SALES VALUE</span><span class="metric-value">KSh {{ number_format($totalSalesValue, 2) }}</span></div></div>
    <div class="col-md-4"><div class="metric"><span class="metric-label">AVERAGE PER RECORDED SALES DAY</span><span class="metric-value">{{ number_format($averageLitresPerRecordedDay, 2) }} L</span></div></div>
</div>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="panel h-100">
            <strong class="small">Sales demand trend</strong>
            <div style="height:280px">
                @if($dailySales->isNotEmpty())
                    <canvas id="sales-demand"></canvas>
                @else
                    <p class="tiny mt-3">No sales records in this period.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel h-100">
            <strong class="small">Revenue trend</strong>
            <div style="height:280px">
                @if($dailySales->isNotEmpty())
                    <canvas id="revenue-trend"></canvas>
                @else
                    <p class="tiny mt-3">No revenue records in this period.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="panel h-100">
            <strong class="small">Inventory consumption</strong>
            <div style="height:210px">
                @if($movements->isNotEmpty())
                    <canvas id="consumption"></canvas>
                @else
                    <p class="tiny mt-3">No inventory movements in this period.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <strong class="small">Forecast evaluation</strong>
            @if($dailySales->count() < 2)
                <p class="tiny mt-3">At least two days of sales are needed to evaluate forecasts.</p>
            @elseif(!$forecast['available'])
                <p class="tiny mt-3">Forecast evaluation is temporarily unavailable.</p>
            @else
                <p class="tiny mt-2 mb-0">Each method is compared using earlier recorded sales only.</p>
                <table class="table mt-3">
                    <thead><tr><th>Method</th><th>MAE (L)</th><th>RMSE (L)</th><th>MAPE</th></tr></thead>
                    <tbody>
                        @foreach(['moving_average'=>'Moving Average','exponential_smoothing'=>'Exponential Smoothing'] as $key=>$label)
                            <tr><td>{{ $label }}</td><td>{{ number_format($forecast[$key]['mae'], 2) }}</td><td>{{ number_format($forecast[$key]['rmse'], 2) }}</td><td>{{ number_format($forecast[$key]['mape'], 2) }}%</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="border-top pt-3 mt-3">
                    <p class="small mb-2"><strong>MAE (L):</strong> The average number of litres each estimate differs from recorded sales.</p>
                    <p class="small mb-2"><strong>RMSE (L):</strong> Similar to MAE, but larger mistakes have more effect on the score.</p>
                    <p class="small mb-2"><strong>MAPE:</strong> The average difference expressed as a percentage of recorded sales.</p>
                    <p class="tiny mb-0">Lower scores mean the estimates were closer to recorded sales for the selected period.</p>
                </div>
            @endif
        </div>
    </div>
    <div class="col-12">
        <div class="panel">
            <strong class="small">Future water demand (sales-based)</strong>
            @if(!$outlook['available'])
                <p class="tiny mt-3 mb-0">At least two recorded sales days and a working forecast are needed to show future demand.</p>
            @else
                <p class="tiny mt-2 mb-2">Based on all recorded sales through {{ $outlook['last_recorded_date']->format('d M Y') }}, independent of the Time filter above. Method: {{ $outlook['method'] === 'moving_average' ? 'Moving Average' : 'Exponential Smoothing' }}. Estimated daily sales demand: {{ number_format($outlook['daily_rate'], 2) }} L.</p>
                <div class="table-responsive">
                    <table class="table mb-2">
                        <thead><tr><th>Future period</th><th>Dates</th><th>Days</th><th>Estimated demand</th></tr></thead>
                        <tbody>
                            @foreach($outlook['rows'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td>{{ $row['start']->format('d M Y') }} - {{ $row['end']->format('d M Y') }}</td>
                                    <td>{{ $row['days'] }}</td>
                                    <td>{{ number_format($row['litres']) }} L</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="tiny mb-0">These are baseline estimates that hold daily sales demand constant. They do not include non-sale distributions or future seasonal changes, so longer periods are less certain.</p>
                @if($outlook['is_stale'])
                    <p class="tiny mt-2 mb-0">The latest sales record is more than a week old. Treat these projections as illustrative until recent records are added.</p>
                @endif
            @endif
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
if (document.getElementById('sales-demand')) new Chart(document.getElementById('sales-demand'), {
    type: 'line',
    data: { labels: salesDates, datasets: [{ label: 'Volume (L)', data: @json($dailySales->pluck('quantity')), borderColor: '#d32f45', tension: .3 }] },
    options: salesChartOptions('Litres (L)')
});
if (document.getElementById('revenue-trend')) new Chart(document.getElementById('revenue-trend'), {
    type: 'line',
    data: { labels: salesDates, datasets: [{ label: 'Revenue (KSh)', data: @json($dailySales->pluck('revenue')), borderColor: '#20a18a', tension: .3 }] },
    options: salesChartOptions('Kenyan shillings (KSh)')
});
if (document.getElementById('consumption')) new Chart(document.getElementById('consumption'), {
    type: 'bar',
    data: { labels: @json($movements->map(fn ($row) => $row->transaction_date->format('d M Y'))->values()), datasets: [{ label: 'Inventory OUT (L)', data: @json($movements->pluck('consumed')), backgroundColor: '#e8a32b' }] },
    options: { maintainAspectRatio: false }
});
</script>
@endsection
