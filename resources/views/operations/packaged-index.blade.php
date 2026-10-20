@extends('layouts.app')
@section('page-title', ucfirst($type))
@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="metric"><span class="metric-label">{{ strtoupper($summary['label']) }}</span><span class="metric-value">{{ $summary['suffix'] === 'KSh' ? 'KSh ' : '' }}{{ number_format($summary['value']) }}{{ $summary['suffix'] === 'cases' ? ' cases' : '' }}</span></div></div>
    <div class="col-md-8"><div class="toolbar h-100 justify-content-end">
        @if($type === 'production' && auth()->user()->hasRole('administrator', 'manager', 'production_staff'))<a class="btn btn-brand btn-sm" href="{{ route('packaged.batches.create') }}">Create batch</a>@endif
        @if($type === 'production' || $type === 'inventory')<a class="btn btn-outline-brand w-auto mt-0" href="{{ route('packaged.batches.index') }}">Batches &amp; quality</a>@endif
        @if(($type === 'distribution' || $type === 'sales') && auth()->user()->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff'))<a class="btn btn-outline-brand w-auto mt-0" href="{{ route('packaged.orders.index') }}">Customer orders</a>@endif
        @if($type === 'sales' && auth()->user()->hasRole('administrator', 'manager', 'sales_staff'))<a class="btn btn-brand btn-sm" href="{{ route('packaged.orders.create') }}">Create order</a>@endif
    </div></div>
</div>
@if($type === 'inventory')
<div class="row g-3 mb-3">@foreach($stockByProduct as $stock)<div class="col-md-3"><div class="metric"><span class="metric-label">{{ $stock->name }}</span><span class="metric-value">{{ number_format($stock->cases) }} cases</span></div></div>@endforeach</div>
<p class="tiny">Available stock includes only released, unexpired cases not reserved for confirmed orders. Stock leaves inventory once, at dispatch.</p>
@endif
<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ match($type) {'production' => 'Production batches', 'inventory' => 'Stock movements', 'distribution' => 'Dispatches', 'sales' => 'Invoices'} }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr>
            @if($type === 'production')<th class="px-3">Batch</th><th>Product</th><th>Production date</th><th>Expiry date</th><th>Cases produced</th><th>Cases on hand</th><th>Status</th>
            @elseif($type === 'inventory')<th class="px-3">Date</th><th>Batch</th><th>Product</th><th>Movement</th><th>Cases</th><th>Notes</th>
            @elseif($type === 'distribution')<th class="px-3">Date</th><th>Order</th><th>Customer</th><th>Destination</th><th>Cases</th><th>Status</th>
            @else<th class="px-3">Invoice</th><th>Issued</th><th>Order</th><th>Customer</th><th>Cases ordered</th><th>Amount</th><th>Paid</th><th>Status</th>@endif
        </tr></thead>
        <tbody>@forelse($rows as $row)<tr>
            @if($type === 'production')<td class="px-3"><a href="{{ route('packaged.batches.show', $row) }}">{{ $row->batch_code }}</a></td><td>{{ $row->product->name }}</td><td>{{ $row->produced_on->format('d M Y') }}</td><td>{{ $row->expires_on->format('d M Y') }}</td><td>{{ number_format($row->cases_produced) }} cases</td><td>{{ number_format($row->cases_on_hand) }} cases</td><td>{{ str_replace('_', ' ', ucfirst($row->status)) }}</td>
            @elseif($type === 'inventory')<td class="px-3">{{ $row->created_at->format('d M Y') }}</td><td><a href="{{ route('packaged.batches.show', $row->batch) }}">{{ $row->batch->batch_code }}</a></td><td>{{ $row->batch->product->name }}</td><td>{{ str_replace('_', ' ', ucfirst($row->reason)) }}</td><td>{{ $row->signed_cases > 0 ? '+' : '' }}{{ number_format($row->signed_cases) }} cases</td><td>{{ $row->notes ?: '—' }}</td>
            @elseif($type === 'distribution')<td class="px-3">{{ $row->dispatched_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $row->order) }}">{{ $row->order->order_number }}</a></td><td>{{ $row->order->customer->name }}</td><td>{{ $row->order->delivery_address }}</td><td>{{ number_format($row->lines->sum('cases')) }} cases</td><td>{{ ucfirst($row->status) }}</td>
            @else<td class="px-3">{{ $row->invoice_number }}</td><td>{{ $row->issued_on->format('d M Y') }}</td><td><a href="{{ route('packaged.orders.show', $row->order) }}">{{ $row->order->order_number }}</a></td><td>{{ $row->order->customer->name }}</td><td>{{ number_format($row->order->items->sum('cases_ordered')) }} cases</td><td>KSh {{ number_format($row->amount, 2) }}</td><td>KSh {{ number_format($row->payments->sum('amount'), 2) }}</td><td>{{ ucfirst($row->status) }}</td>@endif
        </tr>@empty<tr><td colspan="8" class="p-5 text-center text-muted">No {{ $type }} records yet.</td></tr>@endforelse</tbody>
    </table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
