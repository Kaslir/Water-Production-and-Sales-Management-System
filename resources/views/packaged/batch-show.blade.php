@extends('layouts.app')
@section('page-title', 'Batch '.$batch->batch_code)
@section('content')
<div class="panel mb-3">
    <h5>{{ $batch->batch_code }} · {{ $batch->product->name }}</h5>
    <p>Produced {{ $batch->produced_on->format('d M Y') }} · Expires {{ $batch->expires_on->format('d M Y') }} · {{ str_replace('_',' ',ucfirst($batch->status)) }}</p>
    @if($batch->manufacturingPolicy)<p class="tiny">Shelf life: {{ $batch->manufacturingPolicy->shelf_life_days }} days · Approved basis: {{ $batch->manufacturingPolicy->shelf_life_evidence }}</p>@endif
    <p>Quality result:
        @if($batch->status === 'released')<span class="badge bg-success">Passed</span>
        @elseif($batch->status === 'quality_hold')<span class="badge bg-warning text-dark">Pending review</span>
        @elseif($batch->status === 'quarantined')<span class="badge bg-warning text-dark">Quarantined</span>
        @elseif($batch->status === 'recalled')<span class="badge bg-danger">Recalled</span>
        @else<span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span>@endif
        @if($batch->quality_rejected_cases > 0)<span class="small ms-2">{{ number_format($batch->quality_rejected_cases) }} cases rejected</span>@endif
    </p>
    <p>Produced {{ $batch->cases_produced }} cases · On hand {{ $batch->cases_on_hand }}</p>
    <p class="small mb-1">Water source: {{ $batch->water_source_reference ?: 'Not recorded for this historical batch' }} · Input: {{ $batch->water_input_litres ? number_format($batch->water_input_litres, 2).' L' : 'Not recorded' }} · Treatment log: {{ $batch->treatment_log_reference ?: 'Not recorded' }}</p>
    @if($batch->unplanned_reason)<p class="small mb-1">Unplanned production approved by manager #{{ $batch->unplanned_authorized_by }}: {{ $batch->unplanned_reason }}</p>@endif
    <p class="small mb-0">Quality evidence: {{ $batch->quality_evidence_reference ?: 'Not recorded for this historical batch' }}@if($batch->quality_tested_on) · Tested {{ $batch->quality_tested_on->format('d M Y') }} · pH {{ $batch->quality_ph }} · Turbidity {{ $batch->quality_turbidity_ntu }} NTU · Microbiology {{ $batch->microbiological_result }}@endif</p>
</div>
@if(auth()->user()->hasRole('administrator','manager','inventory_staff'))
<div class="panel mb-3"><h6>Physical stock count</h6><p class="tiny">A count does not change stock until a manager approves it. Resolve any reservation shortage before submitting.</p>
@php $pendingCount = $batch->stockCounts->firstWhere('status', 'pending'); @endphp
@if($pendingCount)
<p>Pending count: {{ $pendingCount->counted_cases }} cases · System: {{ $pendingCount->system_cases }} · Difference: {{ $pendingCount->difference_cases }} · Reason: {{ $pendingCount->reason }}</p>
@if(auth()->user()->hasRole('administrator','manager'))<form class="row g-2 align-items-end" method="post" action="{{ route('packaged.stock-counts.decision', $pendingCount) }}">@csrf<div class="col-md-7"><label class="form-label">Review reason</label><input class="form-control" name="reason" required></div><div class="col-md-5"><button class="btn btn-success" name="decision" value="approve">Approve and adjust</button> <button class="btn btn-outline-danger" name="decision" value="reject">Reject</button></div></form>@endif
@else
<form class="row g-2 align-items-end" method="post" action="{{ route('packaged.batches.stock-counts.store', $batch) }}">@csrf<div class="col-md-3"><label class="form-label">Cases physically counted</label><input class="form-control" type="number" name="counted_cases" min="{{ $batch->cases_reserved }}" required></div><div class="col-md-6"><label class="form-label">Discrepancy reason or count reference</label><input class="form-control" name="reason" required></div><div class="col-md-3"><button class="btn btn-outline-primary">Submit count</button></div></form>
@endif</div>
@endif
<div class="panel"><h6>Customers who received this batch</h6><div class="tiny mb-2">Use this list to contact recipients if this batch is recalled.</div><table class="table"><thead><tr><th>Customer</th><th>Order</th><th>Dispatch date</th><th>Reference</th><th>Cases</th></tr></thead><tbody>@forelse($deliveries as $line)<tr><td>{{ $line->dispatch->order->customer->name }}<div class="tiny">{{ $line->dispatch->order->customer->phone_number }}</div></td><td><a href="{{ route('packaged.orders.show',$line->dispatch->order) }}">{{ $line->dispatch->order->order_number }}</a></td><td>{{ $line->dispatch->dispatched_on->format('d M Y') }}</td><td>{{ $line->dispatch->delivery_reference }}</td><td>{{ $line->cases }}</td></tr>@empty<tr><td colspan="5">No customer dispatches from this batch.</td></tr>@endforelse</tbody></table></div>
@if(auth()->user()->hasRole('administrator','manager') && $batch->status==='quality_hold')
<div class="panel mt-3"><h6>Quality release</h6><p class="tiny">Record the measured results and a traceable laboratory or quality record. The approver must check these against the company's approved quality procedure; the system does not assume pass thresholds. Rejected cases are removed before sale.</p>
<form class="row g-2 align-items-end" method="post" action="{{ route('packaged.batches.release',$batch) }}">@csrf
<div class="col-md-3"><label class="form-label">Tested on</label><input class="form-control" name="quality_tested_on" type="date" min="{{ $batch->produced_on->toDateString() }}" max="{{ today()->toDateString() }}" value="{{ old('quality_tested_on', today()->toDateString()) }}" required></div>
<div class="col-md-3"><label class="form-label">Quality evidence reference</label><input class="form-control" name="quality_evidence_reference" value="{{ old('quality_evidence_reference') }}" required></div>
<div class="col-md-2"><label class="form-label">pH</label><input class="form-control" name="quality_ph" type="number" min="0" max="14" step="0.01" value="{{ old('quality_ph') }}" required></div>
<div class="col-md-2"><label class="form-label">Turbidity (NTU)</label><input class="form-control" name="quality_turbidity_ntu" type="number" min="0" step="0.001" value="{{ old('quality_turbidity_ntu') }}" required></div>
<div class="col-md-2"><label class="form-label">Microbiology</label><select class="form-select" name="microbiological_result" required><option value="">Select</option><option value="pass" @selected(old('microbiological_result')==='pass')>Pass</option><option value="fail" @selected(old('microbiological_result')==='fail')>Fail</option></select></div>
<div class="col-md-3"><label class="form-label">Rejected cases</label><input class="form-control" name="rejected_cases" type="number" min="0" max="{{ $batch->cases_on_hand-1 }}" value="{{ old('rejected_cases', 0) }}" required></div>
<div class="col-md-7"><label class="form-label">Quality decision notes</label><input class="form-control" name="quality_notes" value="{{ old('quality_notes') }}" required></div>
<div class="col-md-2"><button class="btn btn-success">Approve saleable cases</button></div>
</form></div>@endif
@if(auth()->user()->hasRole('administrator','manager'))
<div class="panel mt-3"><h6>Actual manufacturing cost</h6><p class="small">Estimated batch cost: KSh {{ number_format($batch->total_cost, 2) }}. Historical dispatch costs and profit reports retain that estimate.</p>
@if($batch->actualCost)<p class="mb-0">Recorded actual cost: KSh {{ number_format($batch->actualCost->actual_total, 2) }} · Difference: KSh {{ number_format((float)$batch->actualCost->actual_total-(float)$batch->total_cost, 2) }} · Evidence: {{ $batch->actualCost->evidence_reference }}</p>
@else
<form class="row g-2 align-items-end" method="post" action="{{ route('packaged.batches.actual-cost', $batch) }}">@csrf
    @foreach(['actual_treatment' => 'Treatment', 'actual_bottles' => 'Bottles', 'actual_inbound' => 'Inbound transport', 'actual_caps_labels' => 'Caps and labels', 'actual_labour' => 'Labour', 'actual_electricity' => 'Electricity', 'actual_other' => 'Other production'] as $field => $label)
        <div class="col-md-3"><label class="form-label" for="{{ $field }}">{{ $label }} (KSh)</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="0.01" value="{{ old($field, 0) }}" required></div>
    @endforeach
    <div class="col-md-6"><label class="form-label" for="evidence_reference">Cost evidence reference</label><input class="form-control" id="evidence_reference" name="evidence_reference" value="{{ old('evidence_reference') }}" required></div>
    <div class="col-md-3"><button class="btn btn-outline-primary">Record actual cost</button></div>
</form>
@endif</div>
@endif
@if(auth()->user()->hasRole('administrator','manager') && $batch->status==='recalled' && $batch->cases_on_hand>0)<div class="panel mt-3"><h6>Dispose recalled stock</h6><form class="d-flex gap-2" method="post" action="{{ route('packaged.batches.dispose',$batch) }}">@csrf<input class="form-control" name="cases" type="number" min="1" max="{{ $batch->cases_on_hand }}" placeholder="Cases" required><input class="form-control" name="reason" placeholder="Disposal reason" required><button class="btn btn-outline-danger">Record disposal</button></form></div>@endif
@if(auth()->user()->hasRole('administrator','manager'))
@if(in_array($batch->status,['quality_hold','released']) && $batch->cases_reserved==0)<div class="panel mt-3"><h6>Quarantine batch</h6><form class="d-flex gap-2" method="post" action="{{ route('packaged.batches.quarantine',$batch) }}">@csrf<input class="form-control" name="reason" placeholder="Reason for quarantine" required><button class="btn btn-outline-warning">Quarantine</button></form></div>@endif
@if($batch->status!=='recalled')<div class="panel mt-3"><h6>Recall batch</h6><p class="tiny">Stops further dispatch, releases open reservations, and preserves the customer trace above.</p><form class="d-flex gap-2" method="post" action="{{ route('packaged.batches.recall',$batch) }}">@csrf<input class="form-control" name="reason" placeholder="Reason for recall" required><button class="btn btn-outline-danger" onclick="return confirm('Recall this batch and release affected reservations?')">Recall</button></form></div>@endif
@if($batch->status!=='recalled' && ($batch->status==='quarantined'||$batch->expires_on->lte(today())) && $batch->cases_on_hand>$batch->cases_reserved)<div class="panel mt-3"><h6>Dispose blocked stock</h6><form class="d-flex gap-2" method="post" action="{{ route('packaged.batches.dispose',$batch) }}">@csrf<input class="form-control" name="cases" type="number" min="1" max="{{ $batch->cases_on_hand-$batch->cases_reserved }}" placeholder="Cases" required><input class="form-control" name="reason" placeholder="Disposal reason" required><button class="btn btn-outline-danger">Record disposal</button></form></div>@endif
@endif
@endsection
