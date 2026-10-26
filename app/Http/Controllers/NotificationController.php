<?php

namespace App\Http\Controllers;

use App\Models\AppAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('filter', 'unread');
        abort_unless(in_array($filter, ['unread', 'all'], true), 404);

        $alerts = $request->user()->appAlerts()
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at')->whereNull('resolved_at'))
            ->latest()->paginate(20)->withQueryString();

        return view('notifications.index', compact('alerts', 'filter'));
    }

    public function read(Request $request, AppAlert $alert): RedirectResponse
    {
        $this->authorizeOwner($request, $alert);
        $alert->update(['read_at' => $alert->read_at ?? now()]);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->appAlerts()->whereNull('read_at')->whereNull('resolved_at')->update(['read_at' => now()]);

        return back();
    }

    public function open(Request $request, AppAlert $alert): RedirectResponse
    {
        $this->authorizeOwner($request, $alert);
        $alert->update(['read_at' => $alert->read_at ?? now()]);

        return redirect()->to($alert->destination($request->user()));
    }

    private function authorizeOwner(Request $request, AppAlert $alert): void
    {
        abort_unless($alert->user_id === $request->user()->user_id, 404);
    }
}
