@extends('layouts.app')
@section('page-title', 'Inventory')
@section('content')
<div class="panel mb-3 pb-0">
    <nav class="nav nav-tabs border-0" aria-label="Inventory sections">
        <a class="nav-link {{ $tab === 'stock' ? 'active' : '' }}" href="{{ route('inventory.index', ['tab' => 'stock']) }}" @if($tab === 'stock') aria-current="page" @endif>Stock overview</a>
        <a class="nav-link {{ $tab === 'batches' ? 'active' : '' }}" href="{{ route('inventory.index', ['tab' => 'batches']) }}" @if($tab === 'batches') aria-current="page" @endif>Batches &amp; quality</a>
        <a class="nav-link {{ $tab === 'movements' ? 'active' : '' }}" href="{{ route('inventory.index', ['tab' => 'movements']) }}" @if($tab === 'movements') aria-current="page" @endif>Movement history</a>
    </nav>
</div>

<div class="row g-3 mb-3"><div class="col-md-4"><div class="metric"><span class="metric-label">AVAILABLE SALEABLE STOCK</span><span class="metric-value">{{ number_format($availableCases) }} cases</span></div></div></div>
@if($tab === 'stock')
    <div class="row g-3 mb-3">
        @foreach($stockByProduct as $stock)
            <div class="col-md-3"><div class="metric"><span class="metric-label">{{ $stock->name }}</span><span class="metric-value">{{ number_format($stock->cases) }} cases</span></div></div>
        @endforeach
    </div>
    <div class="panel"><p class="mb-0 small">Available stock includes only released, unexpired cases not reserved for confirmed orders. Stock leaves inventory once, at dispatch.</p></div>
@else
    @if($tab === 'batches')
        <div class="panel mb-3 d-flex justify-content-between align-items-center"><div><strong>Production batches</strong><div class="tiny">Open a batch to review its quality result, trace deliveries, or manage a recall or disposal.</div></div>@if(auth()->user()->hasRole('administrator', 'manager', 'production_staff'))<a class="btn btn-brand" href="{{ route('packaged.batches.create') }}">Record batch</a>@endif</div>
    @endif
    <form class="toolbar mb-3" method="get" action="{{ route('inventory.index') }}">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div><label class="form-label" for="inventory-batch">Batch code</label><input class="form-control" id="inventory-batch" name="batch" value="{{ $filters['batch'] ?? '' }}" placeholder="Search batch code"></div>
        <div><label class="form-label" for="inventory-from">{{ $tab === 'batches' ? 'Produced' : 'Movement' }} from</label><input class="form-control" id="inventory-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
        <div><label class="form-label" for="inventory-to">{{ $tab === 'batches' ? 'Produced' : 'Movement' }} to</label><input class="form-control" id="inventory-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
        <div class="align-self-end d-flex gap-2"><button class="btn btn-brand" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('inventory.index', ['tab' => $tab]) }}">Clear</a></div>
    </form>
    <div class="panel p-0 overflow-hidden">
        <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ $tab === 'batches' ? 'Batches & quality' : 'Stock movements' }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr>
                @if($tab === 'batches')<th class="px-3">Batch</th><th>Product</th><th>Produced</th><th>Expiry</th><th>Status</th><th>Cases on hand</th><th></th>
                @else<th class="px-3">Date</th><th>Batch</th><th>Product</th><th>Movement</th><th>Cases</th><th>Notes</th>@endif
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    @if($tab === 'batches')
                        <td class="px-3"><a href="{{ route('packaged.batches.show', $row) }}">{{ $row->batch_code }}</a></td><td>{{ $row->product->name }}</td><td>{{ $row->produced_on->format('d M Y') }}</td><td>{{ $row->expires_on->format('d M Y') }}</td><td>{{ str_replace('_', ' ', ucfirst($row->status)) }}</td><td>{{ number_format($row->cases_on_hand) }} ({{ number_format($row->cases_reserved) }} reserved)</td><td><a href="{{ route('packaged.batches.show', $row) }}">View batch</a></td>
                    @else
                        <td class="px-3">{{ $row->created_at->format('d M Y') }}</td><td><a href="{{ route('packaged.batches.show', $row->batch) }}">{{ $row->batch->batch_code }}</a></td><td>{{ $row->batch->product->name }}</td><td>{{ str_replace('_', ' ', ucfirst($row->reason)) }}</td><td>{{ $row->signed_cases > 0 ? '+' : '' }}{{ number_format($row->signed_cases) }} cases</td><td>{{ $row->notes ?: '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7" class="p-5 text-center text-muted">{{ array_filter(array_diff_key($filters, ['tab' => true])) ? 'No records match these filters.' : 'No records yet.' }}</td></tr>
            @endforelse
            </tbody>
        </table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
    </div>
@endif
@endsection
