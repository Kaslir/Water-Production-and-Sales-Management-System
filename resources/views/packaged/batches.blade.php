@extends('layouts.app')
@section('page-title', 'Batches & quality')
@section('content')
<div class="panel mb-3 d-flex justify-content-between"><div><strong>Bottled water stock</strong><div class="tiny">Only approved, unexpired cases can be sold.</div></div>@if(auth()->user()->hasRole('administrator','manager','production_staff'))<a class="btn btn-brand" href="{{ route('packaged.batches.create') }}">Record batch</a>@endif</div>
<div class="row g-3 mb-3">@foreach($products as $product)<div class="col-md-3"><div class="metric"><div><strong>{{ $product->name }}</strong><div class="tiny">{{ $product->bottles_per_case }} bottles / case</div></div><strong>KSh {{ number_format($product->default_price_per_case, 2) }}</strong></div>@if(auth()->user()->hasRole('administrator','manager'))<form class="d-flex gap-1 mt-1" method="post" action="{{ route('packaged.products.price',$product) }}">@csrf<input class="form-control" name="default_price_per_case" type="number" min="0.01" step="0.01" value="{{ $product->default_price_per_case }}" aria-label="Case price"><button class="btn btn-sm btn-outline-danger">Set price</button></form>@endif</div>@endforeach</div>
<div class="panel table-responsive"><table class="table align-middle"><thead><tr><th>Batch</th><th>Product</th><th>Produced</th><th>Expiry</th><th>Status</th><th>Cases on hand</th><th>Cost / case</th><th></th></tr></thead><tbody>
@forelse($batches as $batch)<tr><td>{{ $batch->batch_code }}</td><td>{{ $batch->product->name }}</td><td>{{ $batch->produced_on->format('d M Y') }}</td><td>{{ $batch->expires_on->format('d M Y') }}</td><td>{{ str_replace('_',' ',ucfirst($batch->status)) }}</td><td>{{ $batch->cases_on_hand }} ({{ $batch->cases_reserved }} reserved)</td><td>KSh {{ number_format($batch->cost_per_case,2) }}</td><td><a href="{{ route('packaged.batches.show',$batch) }}">View batch</a></td></tr>
@empty<tr><td colspan="8">No packaged-water batches yet.</td></tr>@endforelse
</tbody></table>{{ $batches->links() }}</div>
<div class="alert alert-info mt-3">Open a batch to review its quality result, trace customer deliveries, and manage recalls or disposal.</div>
@endsection
