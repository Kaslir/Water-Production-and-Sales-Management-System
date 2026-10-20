@extends('layouts.app')
@section('page-title', 'Overview')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="metric"><span class="metric-label text-success">AVAILABLE SALEABLE STOCK</span><span class="metric-value">{{ number_format($data['availableCases']) }} cases</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label text-primary">PRODUCED</span><span class="metric-value">{{ number_format($data['producedCases']) }} cases</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label text-warning">DISPATCHED</span><span class="metric-value">{{ number_format($data['dispatchedCases']) }} cases</span></div></div>
    <div class="col-md-3"><div class="metric"><span class="metric-label text-danger">INVOICED SALES</span><span class="metric-value">KSh {{ number_format($data['revenue']) }}</span></div></div>
</div>
<div class="row g-3">
    <div class="col-xl-7"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Recent customer orders</strong>@if(auth()->user()->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff'))<a class="small" href="{{ route('packaged.orders.index') }}">View all</a>@endif</div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th>Value</th></tr></thead><tbody>
            @forelse($data['orders'] as $order)<tr><td>@if(auth()->user()->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff'))<a href="{{ route('packaged.orders.show', $order) }}">{{ $order->order_number }}</a>@else{{ $order->order_number }}@endif</td><td>{{ $order->customer->name }}</td><td>{{ $order->ordered_on->format('d M Y') }}</td><td>{{ ucfirst($order->status) }}</td><td>KSh {{ number_format($order->total_amount, 2) }}</td></tr>@empty<tr><td colspan="5" class="py-4 text-muted">No customer orders yet.</td></tr>@endforelse
        </tbody></table></div>
    </div></div>
    <div class="col-xl-5"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Available cases by product</strong>@if(auth()->user()->hasRole('administrator', 'manager', 'inventory_staff'))<a class="small" href="{{ route('inventory.index') }}">View inventory</a>@endif</div>
        @foreach($data['productStock'] as $stock)<div class="detail-row py-2"><span>{{ $stock->name }}</span><strong>{{ number_format($stock->cases) }} cases</strong></div>@endforeach
        <p class="tiny mt-3 mb-0">Only released, unexpired cases not reserved for an order are available.</p>
    </div></div>
</div>
<div class="row g-3 mt-1"><div class="col-xl-7"><div class="panel h-100"><strong class="small">Invoiced sales trend</strong><span class="tiny float-end">Last 30 recorded dates</span><div style="height:230px"><canvas id="salesChart"></canvas></div></div></div>
    <div class="col-xl-5"><div class="panel h-100"><div class="d-flex justify-content-between mb-3"><strong class="small">Recent production batches</strong>@if(auth()->user()->hasRole('administrator', 'manager', 'production_staff', 'inventory_staff'))<a class="small" href="{{ route('packaged.batches.index') }}">View all</a>@endif</div>
        @forelse($data['recentBatches'] as $batch)<div class="detail-row py-2"><span>{{ $batch->batch_code }} · {{ $batch->product->name }}</span><strong>{{ number_format($batch->cases_produced) }} cases</strong></div>@empty<p class="text-muted small">No production batches yet.</p>@endforelse
    </div></div>
</div>
<script>new Chart(document.getElementById('salesChart'),{type:'line',data:{labels:@json($data['salesChart']->map(fn ($row) => \Carbon\Carbon::parse($row->issued_on)->format('d M Y'))->values()),datasets:[{label:'Invoiced sales (KSh)',data:@json($data['salesChart']->pluck('total')),borderColor:'#b42332',backgroundColor:'#b4233218',fill:true,tension:.3,borderWidth:2}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}}}});</script>
@endsection
