<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TechnicalEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'in:audit,system'],
            'event' => ['nullable', 'string', 'max:100'],
        ]);
        $tab = $filters['tab'] ?? 'audit';
        abort_if($tab === 'system' && ! $request->user()->hasRole('administrator'), 403);

        if ($tab === 'system') {
            $events = TechnicalEvent::query()->orderByDesc('id')->paginate(30)->withQueryString();

            return view('logs.index', compact('tab', 'events'));
        }

        $event = trim($filters['event'] ?? '');
        $logs = AuditLog::query()->with('actor')
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('logs.index', compact('tab', 'logs', 'event'));
    }
}
