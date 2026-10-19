<?php

namespace App\Http\Controllers;

use App\Models\{InventoryTransaction, Sale};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        // Selecting the analysis period
        $period = $request->query('period', 'all');
        if (! in_array($period, ['week', 'month', '3months', '6months', 'year', 'all'], true)) {
            $period = 'all';
        }

        $latestDates = array_filter([Sale::max('sale_date'), InventoryTransaction::max('transaction_date')]);
        $latestDate = $latestDates ? CarbonImmutable::parse(max($latestDates)) : null;
        $startDate = $latestDate ? match ($period) {
            'week' => $latestDate->subDays(6),
            'month' => $latestDate->subMonthNoOverflow(),
            '3months' => $latestDate->subMonthsNoOverflow(3),
            '6months' => $latestDate->subMonthsNoOverflow(6),
            'year' => $latestDate->subYearNoOverflow(),
            default => null,
        } : null;

        // Retrieving and calculating sales
        $salesQuery = Sale::selectRaw('sale_date, SUM(quantity_sold) quantity, SUM(total_amount) revenue');
        if ($startDate) {
            $salesQuery->where('sale_date', '>=', $startDate->toDateString());
        }
        $dailySales = $salesQuery->groupBy('sale_date')->orderBy('sale_date')->get();
        $recordedSalesDays = $dailySales->count();
        $totalLitresSold = (float) $dailySales->sum('quantity');
        $totalSalesValue = (float) $dailySales->sum('revenue');
        $averageLitresPerRecordedDay = $recordedSalesDays ? $totalLitresSold / $recordedSalesDays : 0;
        // Preparing data for demand forecasting
        $values = $dailySales->pluck('quantity')->map(fn ($value) => (float) $value)->values()->all();
        $forecast = $this->forecast($values);

        // Tracking inventory consumption
        $movementsQuery = InventoryTransaction::selectRaw("transaction_date, SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END) consumed");
        if ($startDate) {
            $movementsQuery->where('transaction_date', '>=', $startDate->toDateString());
        }
        $movements = $movementsQuery->groupBy('transaction_date')->orderBy('transaction_date')->get();

        // Generating future demand projections
        $forecastHistory = $period === 'all' ? $dailySales : Sale::selectRaw('sale_date, SUM(quantity_sold) quantity')
            ->groupBy('sale_date')->orderBy('sale_date')->get();
        $outlookForecast = $period === 'all' ? $forecast : $this->forecast(
            $forecastHistory->pluck('quantity')->map(fn ($value) => (float) $value)->values()->all()
        );
        $outlook = $this->futureDemandOutlook($forecastHistory, $outlookForecast);

        // Displaying the analytics dashboard
        return view('analytics.index', compact('dailySales', 'forecast', 'movements', 'period', 'totalLitresSold', 'totalSalesValue', 'averageLitresPerRecordedDay', 'outlook'));
    }

    private function futureDemandOutlook($history, array $forecast): array
    {
        if (! $forecast['available']) {
            return ['available' => false];
        }

        $lastRecordedDate = $history->last()->sale_date;
        $today = CarbonImmutable::today('Africa/Nairobi');
        $startDate = $today->addDay();
        if ($lastRecordedDate->gte($startDate)) {
            $startDate = CarbonImmutable::parse($lastRecordedDate)->addDay();
        }

        $dailyRate = max(0, (float) $forecast['forecast_quantity']);
        $periods = [
            ['label' => 'Next 1 week', 'end' => $startDate->addWeek()->subDay()],
            ['label' => 'Next 1 month', 'end' => $startDate->addMonthNoOverflow()->subDay()],
            ['label' => 'Next 3 months', 'end' => $startDate->addMonthsNoOverflow(3)->subDay()],
            ['label' => 'Next 6 months', 'end' => $startDate->addMonthsNoOverflow(6)->subDay()],
            ['label' => 'Next 1 year', 'end' => $startDate->addYearNoOverflow()->subDay()],
        ];
        $rows = [];
        foreach ($periods as $period) {
            $days = (int) $startDate->diffInDays($period['end']) + 1;
            $rows[] = [
                'label' => $period['label'],
                'start' => $startDate,
                'end' => $period['end'],
                'days' => $days,
                'litres' => $dailyRate * $days,
            ];
        }

        return [
            'available' => true,
            'method' => $forecast['best_method'],
            'daily_rate' => $dailyRate,
            'last_recorded_date' => $lastRecordedDate,
            'is_stale' => $lastRecordedDate->lt($today->subDays(7)),
            'rows' => $rows,
        ];
    }

    private function forecast(array $values): array
    {
        // Checking whether forecasting is possible
        if (count($values) < 2) {
            return ['available' => false];
        }

        // Connecting Laravel to the Python forecasting model
        try {
            $process = new Process([env('PYTHON_BINARY', 'python'), base_path('analytics/analyze.py')]);
            $process->setInput(json_encode(['sales' => $values]));
            $process->run();
            $result = json_decode($process->getOutput(), true);
            if ($process->isSuccessful() && is_array($result)
                && in_array($result['best_method'] ?? null, ['moving_average', 'exponential_smoothing'], true)
                && isset($result['forecast_quantity'], $result['moving_average']['mae'], $result['exponential_smoothing']['mae'])) {
                return ['available' => true] + $result;
            }
        } catch (\Throwable $exception) {
            return ['available' => false];
        }

        return ['available' => false];
    }
}
