<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('logs.index', ['tab' => 'audit', 'event' => $request->query('event')]);
    }
}
