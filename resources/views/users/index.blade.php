@extends('layouts.app')
@section('page-title', 'User management')
@section('content')
<style>
    .user-avatar { width: 38px; height: 38px; border-radius: 50%; background: #f6d6da; color: #8b1e2d; display: inline-grid; place-items: center; font-size: .78rem; font-weight: 700; flex: none; }
</style>

<div class="d-flex justify-content-between align-items-center gap-3 mb-3">
    <p class="small text-muted mb-0">Manage staff accounts, roles, and access requests.</p>
    <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-outline-brand w-auto mt-0" href="{{ route('access.reviews.index') }}">Pending requests</a>
        <a class="btn btn-brand btn-sm" href="{{ route('users.create') }}"><i class="bi bi-person-plus me-1"></i>Add user</a>
    </div>
</div>

<form class="toolbar mb-3" method="get" action="{{ route('users.index') }}">
    <div class="flex-grow-1" style="min-width:220px"><label class="form-label" for="user-search">Search users</label><input class="form-control" id="user-search" name="search" value="{{ $search }}" placeholder="Name or email"></div>
    <div style="min-width:180px"><label class="form-label" for="user-role">Role</label><select class="form-select" id="user-role" name="role"><option value="">All roles</option>@foreach(\App\Models\User::ROLES as $option)<option value="{{ $option }}" @selected($role === $option)>{{ ucwords(str_replace('_', ' ', $option)) }}</option>@endforeach</select></div>
    <div style="min-width:150px"><label class="form-label" for="user-status">Status</label><select class="form-select" id="user-status" name="status"><option value="active" @selected($status === 'active')>Active</option><option value="inactive" @selected($status === 'inactive')>Inactive</option><option value="all" @selected($status === 'all')>All users</option></select></div>
    <div class="align-self-end d-flex gap-2"><button class="btn btn-brand" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Clear</a></div>
</form>

<div class="panel p-0 overflow-hidden">
    <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center"><strong class="small">Staff accounts</strong><span class="tiny">{{ number_format($users->total()) }} {{ $search !== '' || $role !== '' || $status !== 'all' ? 'matching' : 'total' }} users</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th class="px-3">User</th><th>Role</th><th>Status</th><th>Created</th><th class="text-end px-3">Actions</th></tr></thead>
        <tbody>
        @forelse($users as $user)
            <tr>
                <td class="px-3"><div class="d-flex align-items-center gap-2"><span class="user-avatar" aria-hidden="true">{{ strtoupper(collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('')) }}</span><div><strong>{{ $user->name }}</strong><small class="d-block text-muted">{{ $user->email }}</small></div></div></td>
                <td><span class="badge text-bg-light border text-capitalize">{{ str_replace('_', ' ', $user->role) }}</span></td>
                <td>@if($user->is_active)<span class="badge rounded-pill text-bg-success">Active</span>@else<span class="badge rounded-pill text-bg-secondary">Inactive</span>@endif</td>
                <td class="small text-muted">{{ $user->created_at?->format('d M Y') }}</td>
                <td class="text-end px-3"><div class="d-inline-flex align-items-center gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('users.edit', $user) }}" aria-label="Edit {{ $user->name }}">Edit</a>@if(! $user->is(auth()->user()) && $user->is_active)<form method="post" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Deactivate this user? Historical records will remain.')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Deactivate {{ $user->name }}">Deactivate</button></form>@endif</div></td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-5 text-center text-muted">No users match these filters. Try another role, status, or search term.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="p-3 border-top">{{ $users->links() }}</div>
</div>
@endsection
