<?php

namespace App\Services;

use App\Mail\AlertEmail;
use App\Models\AppAlert;
use App\Models\ProductForecast;
use App\Models\ProductionBatch;
use App\Models\TechnicalEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AppAlertService
{
    public function __construct(private ProductForecastService $forecasts) {}

    public function publish(
        string $key,
        string $category,
        string $severity,
        string $title,
        string $message,
        string $targetType,
        ?int $targetId,
        array $roles,
        array $emailRoles = [],
    ): void {
        $users = User::query()->where('is_active', true)->whereIn('role', $roles)->get();

        foreach ($users as $user) {
            $alert = AppAlert::query()->firstOrCreate(
                ['user_id' => $user->user_id, 'alert_key' => $key],
                ['category' => $category, 'severity' => $severity, 'title' => $title, 'message' => $message, 'target_type' => $targetType, 'target_id' => $targetId],
            );

            $newOccurrence = $alert->wasRecentlyCreated;
            if (! $newOccurrence && $alert->resolved_at !== null) {
                $alert->update(['category' => $category, 'severity' => $severity, 'title' => $title, 'message' => $message, 'target_type' => $targetType, 'target_id' => $targetId, 'read_at' => null, 'resolved_at' => null]);
                $newOccurrence = true;
            }

            if ($newOccurrence && in_array($user->role, $emailRoles, true)) {
                $send = function () use ($user, $title, $message, $alert): void {
                    try {
                        Mail::to($user->email)->send(new AlertEmail($title, $message));
                    } catch (Throwable) {
                        TechnicalEvent::create(['event_type' => 'AlertEmailFailure', 'source' => 'alert-'.$alert->category, 'occurred_at' => now()]);
                    }
                };

                if (DB::transactionLevel() > 0) {
                    DB::afterCommit($send);
                } else {
                    $send();
                }
            }
        }
    }

    public function resolve(string $key): void
    {
        AppAlert::query()->where('alert_key', $key)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function scanIfDue(bool $force = false): void
    {
        DB::transaction(function () use ($force): void {
            $settings = DB::table('app_alert_settings')->where('id', 1)->lockForUpdate()->first();
            if (! $settings) {
                return;
            }

            $today = CarbonImmutable::today('Africa/Nairobi');
            if (! $force && $settings->last_scan_on === $today->toDateString()) {
                return;
            }

            $this->scanExpiry($today, $settings->activated_at);
            $this->scanShortages($today, $settings->activated_at);
            DB::table('app_alert_settings')->where('id', 1)->update(['last_scan_on' => $today->toDateString()]);
        });
    }

    private function scanExpiry(CarbonImmutable $today, string $activatedAt): void
    {
        $activeKeys = [];
        $batches = ProductionBatch::query()->with('product')
            ->where('created_at', '>=', $activatedAt)
            ->where('status', 'released')
            ->where('cases_on_hand', '>', 0)
            ->whereDate('expires_on', '<=', $today->addDays(30)->toDateString())
            ->get();

        foreach ($batches as $batch) {
            $days = (int) $today->diffInDays($batch->expires_on, false);
            $stage = $days <= 0 ? 'expired' : ($days <= 7 ? '7-days' : '30-days');
            $key = 'batch:expiry:'.$batch->id.':'.$stage;
            $activeKeys[] = $key;
            $title = $days <= 0 ? 'Batch expired' : ($days <= 7 ? 'Batch expires within 7 days' : 'Batch expires within 30 days');
            $this->publish($key, 'expiry', $days <= 7 ? 'high' : 'warning', $title,
                $batch->batch_code.' ('.$batch->product->name.') has '.$batch->cases_on_hand.' cases on hand. Expiry: '.$batch->expires_on->format('d M Y').'.',
                'batch', $batch->id, ['administrator', 'manager', 'inventory_staff']);
        }

        $this->resolveInactive('expiry', $activeKeys);
    }

    private function scanShortages(CarbonImmutable $today, string $activatedAt): void
    {
        $activeKeys = [];
        $forecasts = ProductForecast::query()->with('product')
            ->where('created_at', '>=', $activatedAt)
            ->whereDate('origin_date', $today->toDateString())
            ->where('horizon_days', 7)
            ->whereIn('method', ['random_forest', 'gradient_boosting'])
            ->orderByDesc('id')->get()
            ->filter(fn ($forecast) => ($forecast->evaluation['selected'] ?? false) === true && ($forecast->evaluation['mode'] ?? null) === 'current')
            ->unique('product_id');

        foreach ($forecasts as $forecast) {
            $recommendation = $this->forecasts->recommendation($forecast);
            if ($recommendation['recommended_cases'] <= 0) {
                continue;
            }

            $key = 'product:shortage:'.$forecast->product_id;
            $activeKeys[] = $key;
            $this->publish($key, 'shortage', 'high', 'Production may be needed',
                $forecast->product->name.' needs about '.$recommendation['recommended_cases'].' more cases for the next 7 days after eligible stock and approved plans are considered.',
                'stock', $forecast->product_id, ['administrator', 'manager', 'production_staff'], ['administrator', 'manager']);
        }

        $this->resolveInactive('shortage', $activeKeys);
    }

    private function resolveInactive(string $category, array $activeKeys): void
    {
        $query = AppAlert::query()->where('category', $category)->whereNull('resolved_at');
        if ($activeKeys !== []) {
            $query->whereNotIn('alert_key', $activeKeys);
        }
        $query->update(['resolved_at' => now()]);
    }
}
