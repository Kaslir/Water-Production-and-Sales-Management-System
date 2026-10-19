@extends('layouts.app')
@section('page-title', 'System events')
@section('content')
<div class="panel mb-3"><strong>Technical event summary</strong><p class="tiny mb-0">Administrator access only. Sensitive exception details remain in the server-side Laravel log for system maintainers and are not exposed here.</p></div><div class="panel table-responsive"><table class="table"><thead><tr><th>Time</th><th>Type</th><th>Application path</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->occurred_at->format('d M Y H:i:s') }}</td><td>{{ $event->event_type }}</td><td>{{ $event->source }}</td></tr>@empty<tr><td colspan="3">No technical events recorded.</td></tr>@endforelse</tbody></table>{{ $events->links() }}</div>
@endsection
