@extends('layouts.app')
@section('page-title', 'Logs')
@section('content')
<div class="panel mb-3 pb-0">
    <nav class="nav nav-tabs border-0" aria-label="Log sections">
        <a class="nav-link {{ $tab === 'audit' ? 'active' : '' }}" href="{{ route('logs.index', ['tab' => 'audit']) }}" @if($tab === 'audit') aria-current="page" @endif>Audit trail</a>
        @if(auth()->user()->hasRole('administrator'))
            <a class="nav-link {{ $tab === 'system' ? 'active' : '' }}" href="{{ route('logs.index', ['tab' => 'system']) }}" @if($tab === 'system') aria-current="page" @endif>System events</a>
        @endif
    </nav>
</div>

@if($tab === 'audit')
    <div class="panel mb-3">
        <strong>Read-only action history</strong>
        <div class="tiny">Shows who performed important actions and which records changed. Passwords, verification codes, and session tokens are never shown.</div>
        <form method="get" action="{{ route('logs.index') }}" class="d-flex gap-2 mt-3 flex-wrap">
            <input type="hidden" name="tab" value="audit">
            <label class="visually-hidden" for="audit-event">Event</label>
            <input class="form-control w-auto" id="audit-event" name="event" value="{{ $event }}" placeholder="Filter event, e.g. batch.released">
            <button class="btn btn-brand" type="submit">Filter</button>
            @if($event !== '')<a class="btn btn-outline-secondary" href="{{ route('logs.index', ['tab' => 'audit']) }}">Clear</a>@endif
        </form>
    </div>
    <div class="panel p-0 overflow-hidden">
        <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">Audit trail</strong><span class="tiny">{{ number_format($logs->total()) }} records</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="px-3">Time</th><th>Actor</th><th>Action</th><th>Record</th><th>Reason</th><th>Changes</th></tr></thead><tbody>
            @forelse($logs as $log)
                <tr><td class="px-3">{{ $log->created_at->format('d M Y H:i') }}</td><td>{{ $log->actor?->name ?? 'System' }}</td><td>{{ $log->event }}</td><td>{{ class_basename($log->subject_type ?? '') }} #{{ $log->subject_id }}</td><td>{{ $log->reason ?: '—' }}</td><td><details><summary>View safe values</summary><small>Before: {{ json_encode($log->before_values) }}<br>After: {{ json_encode($log->after_values) }}</small></details></td></tr>
            @empty
                <tr><td colspan="6" class="p-5 text-center text-muted">No audit entries {{ $event !== '' ? 'match this event.' : 'yet.' }}</td></tr>
            @endforelse
        </tbody></table></div><div class="p-3 border-top">{{ $logs->links() }}</div>
    </div>
@else
    <div class="panel mb-3"><strong>Technical event summary</strong><p class="tiny mb-0">Administrator access only. Sensitive exception details remain in the server-side Laravel log for system maintainers.</p></div>
    <div class="panel p-0 overflow-hidden">
        <div class="px-3 py-3 border-bottom d-flex justify-content-between"><strong class="small">System events</strong><span class="tiny">{{ number_format($events->total()) }} records</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="px-3">Time</th><th>Type</th><th>Application path</th></tr></thead><tbody>
            @forelse($events as $event)
                <tr><td class="px-3">{{ $event->occurred_at->format('d M Y H:i:s') }}</td><td>{{ $event->event_type }}</td><td>{{ $event->source }}</td></tr>
            @empty
                <tr><td colspan="3" class="p-5 text-center text-muted">No technical events recorded.</td></tr>
            @endforelse
        </tbody></table></div><div class="p-3 border-top">{{ $events->links() }}</div>
    </div>
@endif
@endsection
