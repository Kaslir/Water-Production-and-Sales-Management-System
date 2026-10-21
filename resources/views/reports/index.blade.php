@extends('layouts.app')
@section('page-title', 'Reports')
@section('content')
<form class="toolbar mb-3" method="get" action="{{ route('reports.index') }}">
    <label class="form-label mb-0" for="report-type">Report</label>
    <select id="report-type" class="form-select w-auto" name="type">
        @foreach(['sales' => 'Invoiced sales', 'production' => 'Production batches', 'inventory' => 'Stock movements', 'distribution' => 'Dispatches', 'customers' => 'Customers', 'costs' => 'Production costs', 'expiry' => 'Batch expiry', 'disposals' => 'Disposals'] as $key => $label)<option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>@endforeach
    </select>
    <label class="form-label mb-0" for="report-from">From</label><input id="report-from" class="form-control w-auto" type="date" name="from" value="{{ $from }}" required>
    <label class="form-label mb-0" for="report-to">To</label><input id="report-to" class="form-control w-auto" type="date" name="to" value="{{ $to }}" required>
    <button class="btn btn-brand btn-sm">Generate report</button>
    <button class="btn btn-light btn-sm ms-auto" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
</form>
<div class="row g-3 mb-3">@foreach($totals as $total)<div class="col-md-4"><div class="metric"><span class="metric-label">{{ strtoupper($total['label']) }}</span><span class="metric-value">{{ $total['unit'] === 'KSh' ? 'KSh ' : '' }}{{ number_format($total['value'], $total['unit'] === 'KSh' ? 2 : 0) }}{{ $total['unit'] === 'cases' ? ' cases' : '' }}</span></div></div>@endforeach</div>
<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small text-capitalize">{{ $type }} report</strong><span class="tiny">{{ $from }} to {{ $to }} · {{ number_format($rows->total()) }} records</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr>@foreach($columns as $label)<th class="px-3">{{ $label }}</th>@endforeach</tr></thead><tbody>
        @forelse($rows as $row)<tr>@foreach($columns as $field => $label)<td class="px-3">@php($value = $row->$field){{ is_numeric($value) && $field !== 'phone_number' ? number_format($value, in_array($field, ['amount', 'invoiced', 'total_cost', 'cost_per_case', 'loss']) ? 2 : 0) : $value }}</td>@endforeach</tr>
        @empty<tr><td colspan="{{ count($columns) }}" class="p-5 text-center text-muted">No {{ $type }} records in this period.</td></tr>@endforelse
    </tbody></table></div><div class="p-3 border-top">{{ $rows->links() }}</div>
</div>
<p class="tiny mt-3">Reports show packaged-water records only. Invoices, payments, and stock dispatch are separate events. For profit after credits, delivery, and expiry loss, use <a href="{{ route('packaged.reports.index', ['from' => $from, 'to' => $to]) }}">Profit &amp; expiry</a>.</p>
@endsection
