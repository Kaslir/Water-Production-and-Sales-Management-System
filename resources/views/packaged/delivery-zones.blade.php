@extends('layouts.app')
@section('page-title', 'Delivery zones')
@section('content')
<div class="panel mb-3 d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <h1 class="h5 mb-0">Nairobi County delivery zones</h1>
    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#deliveryFeeHistory" aria-expanded="false" aria-controls="deliveryFeeHistory">Delivery fee history</button>
</div>
<div class="collapse mb-3" id="deliveryFeeHistory">
    <div class="panel">
        <h2 class="h6">Delivery fee history</h2>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Zone</th><th>Previous fee</th><th>New fee</th><th>Changed by</th></tr></thead>
                <tbody>
                @forelse($changes as $change)
                    <tr><td>{{ $change->created_at->format('d M Y, H:i') }}</td><td>Zone {{ $change->zone->code }}</td><td>KSh {{ number_format($change->previous_fee, 2) }}</td><td>KSh {{ number_format($change->new_fee, 2) }}</td><td>{{ $change->changer?->name ?? 'Former user' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No delivery fee changes yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="panel table-responsive mb-3">
    <table class="table align-middle">
        <thead><tr><th>Zone</th><th>Areas</th><th>Current fee</th><th>Change fee</th></tr></thead>
        <tbody>
        @foreach($zones as $zone)
            <tr>
                <td><strong>Zone {{ $zone->code }}</strong></td>
                <td>{{ $zone->areas->pluck('name')->join(', ') }}</td>
                <td>KSh {{ number_format($zone->fixed_fee, 2) }}</td>
                <td>
                    <form class="d-flex gap-2 flex-wrap" method="post" action="{{ route('packaged.delivery-zones.update', $zone) }}">
                        @csrf
                        <input type="hidden" name="is_active" value="{{ (int) $zone->is_active }}">
                        <input class="form-control" style="max-width:110px" type="number" name="fixed_fee" min="0.01" step="0.01" value="{{ $zone->fixed_fee }}" aria-label="Zone {{ $zone->code }} fee" required>
                        <button class="btn btn-outline-primary">Save</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
