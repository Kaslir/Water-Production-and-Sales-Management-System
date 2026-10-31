@extends('layouts.app')
@section('page-title', 'Access requests')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><p class="tiny mb-0">Verify the applicant belongs to your organization before assigning a role.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('users.index') }}">Back to users</a></div>
@if(session('mail_error'))<div class="alert alert-warning">{{ session('mail_error') }}</div>@endif
<div class="panel p-0 overflow-hidden"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="px-3">Applicant</th><th>Phone</th><th>Email</th><th>Status</th><th>Submitted</th><th class="px-3">Action</th></tr></thead><tbody>
@forelse($requests as $accessRequest)
<tr><td class="px-3">{{ $accessRequest->name }}</td><td>{{ $accessRequest->phone_number }}</td><td>{{ $accessRequest->email }}</td><td>@if($accessRequest->status === 'pending'){{ $accessRequest->email_verified_at ? 'Pending approval' : 'Awaiting email verification' }}@else{{ ucfirst($accessRequest->status) }}@endif</td><td>{{ $accessRequest->created_at->format('d M Y') }}</td><td class="px-3">
@if($accessRequest->status === 'pending' && $accessRequest->email_verified_at)
<form class="d-inline-flex gap-2 align-items-center" method="post" action="{{ route('access.reviews.approve', $accessRequest) }}">@csrf<select class="form-select form-select-sm" name="role" aria-label="Role for {{ $accessRequest->name }}" required><option value="">Select role</option>@foreach(\App\Models\User::ROLES as $role)<option value="{{ $role }}">{{ ucwords(str_replace('_', ' ', $role)) }}</option>@endforeach</select><button class="btn btn-brand btn-sm" type="submit">Approve</button></form>
@endif
@if($accessRequest->status === 'pending')<form class="d-inline" method="post" action="{{ route('access.reviews.reject', $accessRequest) }}" onsubmit="return confirm('Reject this access request?')">@csrf<button class="btn btn-outline-danger btn-sm" type="submit">Reject</button></form>@endif
@if($accessRequest->status === 'approved')<form method="post" action="{{ route('access.reviews.resend', $accessRequest) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Resend notice</button></form>@endif
</td></tr>
@empty<tr><td colspan="6" class="p-5 text-center text-muted">No access requests yet.</td></tr>@endforelse
</tbody></table></div><div class="p-3 border-top">{{ $requests->links() }}</div></div>
@endsection
