<?php

namespace App\Http\Controllers;

use App\Models\TechnicalEvent;
use Illuminate\View\View;

class TechnicalEventController extends Controller
{
    public function index(): View
    {
        return view('packaged.technical-events', ['events' => TechnicalEvent::query()->orderByDesc('id')->paginate(30)]);
    }
}
