<?php

namespace App\Console\Commands;

use App\Services\AppAlertService;
use Illuminate\Console\Command;

class ScanAlerts extends Command
{
    protected $signature = 'alerts:scan {--force : Run the checks even if they ran today}';

    protected $description = 'Check new operational records for expiry and stock alerts';

    public function handle(AppAlertService $alerts): int
    {
        $alerts->scanIfDue((bool) $this->option('force'));
        $this->info('Alert checks completed.');

        return self::SUCCESS;
    }
}
