@extends('layouts.app')
@section('page-title', 'Inventory')
@section('content')
<div class="panel mb-3 pb-0">
    <nav class="nav nav-tabs border-0" aria-label="Inventory sections">
        <a class="nav-link {{ $tab === 'batches' ? 'active' : '' }}" href="{{ route('inventory.index', ['tab' => 'batches']) }}" @if($tab === 'batches') aria-current="page" @endif>Batches &amp; Quality</a>
        <a class="nav-link {{ $tab === 'movements' ? 'active' : '' }}" href="{{ route('inventory.index', ['tab' => 'movements']) }}" @if($tab === 'movements') aria-current="page" @endif>Movement History</a>
    </nav>
</div>

<div class="row g-3 mb-3"><div class="col-sm-6 col-lg-4"><div class="metric h-100 flex-column align-items-start gap-1"><span class="metric-label">AVAILABLE SALEABLE STOCK</span><span class="metric-value">{{ number_format($availableCases) }} cases</span><span class="tiny">Current balance · released, unexpired and unreserved</span></div></div></div>

@if($tab === 'batches')
<div class="panel mb-3"><strong>Batches &amp; Quality</strong><div class="tiny">Open a batch to review quality, trace deliveries, or manage a recall or disposal. Expiry warnings are separate from quality status.</div></div>
@endif

<form class="toolbar mb-3" method="get" action="{{ route('inventory.index') }}">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div><label class="form-label" for="inventory-batch">Batch code</label><input class="form-control" id="inventory-batch" name="batch" value="{{ $filters['batch'] ?? '' }}" placeholder="Search batch code"></div>
    <div><label class="form-label" for="inventory-product">Product</label><select class="form-select" id="inventory-product" name="product_id"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
    @if($tab === 'batches')
        <div><label class="form-label" for="inventory-status">Quality status</label><select class="form-select" id="inventory-status" name="status"><option value="">All statuses</option>@foreach($qualityStatuses as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
    @else
        <div><label class="form-label" for="inventory-movement-type">Movement type</label><select class="form-select" id="inventory-movement-type" name="movement_type"><option value="">All movements</option>@foreach($movementTypes as $movementType)<option value="{{ $movementType }}" @selected(($filters['movement_type'] ?? '') === $movementType)>{{ ucwords(str_replace('_', ' ', $movementType)) }}</option>@endforeach</select></div>
    @endif
    <div><label class="form-label" for="inventory-from">{{ $tab === 'batches' ? 'Production' : 'Movement' }} date from</label><input class="form-control" id="inventory-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
    <div><label class="form-label" for="inventory-to">{{ $tab === 'batches' ? 'Production' : 'Movement' }} date to</label><input class="form-control" id="inventory-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
    <div class="align-self-end d-flex gap-2"><button class="btn btn-brand" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('inventory.index', ['tab' => $tab]) }}">Clear</a></div>
</form>

<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">{{ $tab === 'batches' ? 'Batches & Quality' : 'Movement History' }}</strong><span class="tiny">{{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr>
            @if($tab === 'batches')<th class="px-3">Batch</th><th>Product</th><th>Produced</th><th>Expiry</th><th>Quality status</th><th>Cases on hand</th><th>Reserved cases</th><th></th>
            @else<th class="px-3">Date</th><th>Batch</th><th>Product</th><th>Movement type</th><th>Cases (+/−)</th><th>Reference</th><th>Notes</th>@endif
        </tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                @if($tab === 'batches')
                    <td class="px-3"><a href="{{ route('packaged.batches.show', $row) }}">{{ $row->batch_code }}</a></td>
                    <td>{{ $row->product->name }}</td>
                    <td>{{ $row->produced_on->format('d M Y') }}</td>
                    <td>{{ $row->expires_on->format('d M Y') }} @if($row->expires_on->lt($businessToday))<span class="badge text-bg-danger ms-1">Expired</span>@elseif($row->expires_on->isSameDay($businessToday))<span class="badge text-bg-warning ms-1">Expires today</span>@elseif($row->expires_on->lte($businessToday->addDays(30)))<span class="badge text-bg-warning ms-1">Expires within 30 days</span>@endif</td>
                    <td><span class="badge {{ match($row->status) {'quality_hold' => 'text-bg-warning', 'released' => 'text-bg-success', 'quarantined', 'recalled', 'rejected' => 'text-bg-danger', default => 'text-bg-secondary'} }}">{{ ucwords(str_replace('_', ' ', $row->status)) }}</span></td>
                    <td>{{ number_format($row->cases_on_hand) }}</td>
                    <td>{{ number_format($row->cases_reserved) }}</td>
                    <td><a href="{{ route('packaged.batches.show', $row) }}">View batch</a></td>
                @else
                    <td class="px-3">{{ $row->created_at->format('d M Y') }}</td>
                    <td><a href="{{ route('packaged.batches.show', $row->batch) }}">{{ $row->batch->batch_code }}</a></td>
                    <td>{{ $row->batch->product->name }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $row->reason)) }}</td>
                    <td><span class="fw-semibold {{ $row->signed_cases > 0 ? 'text-success' : ($row->signed_cases < 0 ? 'text-danger' : '') }}">{{ $row->signed_cases > 0 ? 'Incoming +' : ($row->signed_cases < 0 ? 'Outgoing ' : '') }}{{ number_format($row->signed_cases) }} cases</span></td>
                    <td>@if(in_array($row->reference_type, ['Dispatch', 'dispatch'], true) && $dispatchOrders->get($row->reference_id)?->order)Order {{ $dispatchOrders->get($row->reference_id)->order->order_number }}@elseif(in_array($row->reference_type, ['ProductionBatch', 'production_batch'], true) && (int) $row->reference_id === (int) $row->batch->id){{ $row->batch->batch_code }}@elseif($row->reference_type && $row->reference_id){{ \Illuminate\Support\Str::headline($row->reference_type) }} #{{ $row->reference_id }}@else—@endif</td>
                    <td>{{ in_array($row->notes, ['Entirely synthetic dataset', 'Synthetic dataset import'], true) || blank($row->notes) ? '—' : $row->notes }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $tab === 'batches' ? 8 : 7 }}" class="p-5 text-center text-muted">{{ array_filter(array_diff_key($filters, ['tab' => true])) ? 'No records match these filters.' : ($tab === 'batches' ? 'No production batches recorded yet.' : 'No stock movements recorded yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
@endsection
