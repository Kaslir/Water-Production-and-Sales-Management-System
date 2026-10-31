<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class TechnicalEventController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('logs.index', ['tab' => 'system']);
    }
}
