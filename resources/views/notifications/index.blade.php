@extends('layouts.app')
@section('page-title', 'Notifications')
@section('content')
<div class="panel mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
        <strong>Notifications</strong>
        <div class="tiny">Alerts are shown only to the staff responsible for them. Reading an alert does not resolve the underlying issue.</div>
    </div>
    <form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-outline-primary">Mark all unread as read</button></form>
</div>
<div class="mb-3 d-flex gap-2">
    <a class="btn btn-sm {{ $filter === 'unread' ? 'btn-brand' : 'btn-outline-secondary' }}" href="{{ route('notifications.index', ['filter' => 'unread']) }}">Unread</a>
    <a class="btn btn-sm {{ $filter === 'all' ? 'btn-brand' : 'btn-outline-secondary' }}" href="{{ route('notifications.index', ['filter' => 'all']) }}">All</a>
</div>
<div class="panel">
    @forelse ($alerts as $alert)
        <div class="border-bottom py-3 d-flex flex-wrap justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <strong>{{ $alert->title }}</strong>
                    <span class="badge {{ $alert->severity === 'high' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ ucfirst($alert->severity) }}</span>
                    @if ($alert->resolved_at)<span class="badge text-bg-secondary">Resolved</span>@endif
                    @if ($alert->read_at)<span class="badge text-bg-light border">Read</span>@endif
                </div>
                <div>{{ $alert->message }}</div>
                <div class="tiny mt-1">{{ $alert->created_at->format('d M Y, H:i') }}</div>
            </div>
            <div class="d-flex align-items-start gap-2">
                <form method="post" action="{{ route('notifications.open', $alert) }}">@csrf<button class="btn btn-sm btn-brand">Open record</button></form>
                @unless ($alert->read_at)
                    <form method="post" action="{{ route('notifications.read', $alert) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Mark read</button></form>
                @endunless
            </div>
        </div>
    @empty
        <div class="text-muted">{{ $filter === 'unread' ? 'No unread notifications.' : 'No notifications yet.' }}</div>
    @endforelse
    <div class="mt-3">{{ $alerts->links() }}</div>
</div>
@endsection
