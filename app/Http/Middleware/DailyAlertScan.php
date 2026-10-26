<?php

namespace App\Http\Middleware;

use App\Services\AppAlertService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DailyAlertScan
{
    public function __construct(private AppAlertService $alerts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->alerts->scanIfDue();

        return $next($request);
    }
}
