<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $event = $request->query('event');
        $logs = AuditLog::query()->with('actor')->when($event, fn ($query) => $query->where('event', $event))->orderByDesc('id')->paginate(30)->withQueryString();

        return view('packaged.audit', compact('logs', 'event'));
    }
}
