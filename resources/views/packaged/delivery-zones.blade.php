@extends('layouts.app')
@section('page-title', 'Delivery zones')
@section('content')
<div class="panel mb-3"><h1 class="h5">Nairobi County delivery zones</h1><p class="small text-muted mb-0">Fixed fees cover one customer order on a shared truck route. Industrial Area is the demonstration distribution centre. The starting fees are illustrative.</p></div>
<div class="panel table-responsive mb-3">
    <table class="table align-middle"><thead><tr><th>Zone</th><th>Areas</th><th>Current fee</th><th>Status</th><th>Change fee or status</th></tr></thead><tbody>
    @foreach($zones as $zone)<tr><td><strong>Zone {{ $zone->code }}</strong></td><td>{{ $zone->areas->pluck('name')->join(', ') }}</td><td>KSh {{ number_format($zone->fixed_fee, 2) }}</td><td><span class="badge {{ $zone->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $zone->is_active ? 'Active' : 'Inactive' }}</span></td><td>
        <form class="d-flex gap-2 flex-wrap" method="post" action="{{ route('packaged.delivery-zones.update', $zone) }}">@csrf
            <input class="form-control" style="max-width:110px" type="number" name="fixed_fee" min="0.01" step="0.01" value="{{ $zone->fixed_fee }}" aria-label="Zone {{ $zone->code }} fee" required>
            <select class="form-select" style="max-width:120px" name="is_active" aria-label="Zone {{ $zone->code }} status"><option value="1" @selected($zone->is_active)>Active</option><option value="0" @selected(!$zone->is_active)>Inactive</option></select>
            <input class="form-control" style="max-width:190px" name="reason" placeholder="Reason for change" aria-label="Reason for Zone {{ $zone->code }} change" required>
            <button class="btn btn-outline-primary">Save</button>
        </form>
    </td></tr>@endforeach
    </tbody></table>
</div>
<div class="panel"><h2 class="h6">Delivery fee history</h2><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Zone</th><th>Previous fee</th><th>New fee</th><th>Changed by</th><th>Reason</th></tr></thead><tbody>@forelse($changes as $change)<tr><td>{{ $change->created_at->format('d M Y, H:i') }}</td><td>Zone {{ $change->zone->code }}</td><td>KSh {{ number_format($change->previous_fee, 2) }}</td><td>KSh {{ number_format($change->new_fee, 2) }}</td><td>{{ $change->changer?->name ?? 'Former user' }}</td><td>{{ $change->reason ?: '—' }}</td></tr>@empty<tr><td colspan="6" class="text-muted">No delivery fee changes yet.</td></tr>@endforelse</tbody></table></div></div>
@endsection
