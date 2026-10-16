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

        $salesQuery = Sale::selectRaw('sale_date, SUM(quantity_sold) quantity, SUM(total_amount) revenue');
        if ($startDate) {
            $salesQuery->where('sale_date', '>=', $startDate->toDateString());
        }
        $dailySales = $salesQuery->groupBy('sale_date')->orderBy('sale_date')->get();
        $values = $dailySales->pluck('quantity')->map(fn ($value) => (float) $value)->values()->all();
        $forecast = $this->forecast($values);
        $movementsQuery = InventoryTransaction::selectRaw("transaction_date, SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END) consumed");
        if ($startDate) {
            $movementsQuery->where('transaction_date', '>=', $startDate->toDateString());
        }
        $movements = $movementsQuery->groupBy('transaction_date')->orderBy('transaction_date')->get();
        return view('analytics.index', compact('dailySales', 'forecast', 'movements', 'period', 'latestDate'));
    }

    private function forecast(array $values): array
    {
        if (count($values) < 2) return ['forecast_quantity'=>0, 'best_method'=>'Not enough history', 'moving_average'=>['mae'=>0,'rmse'=>0,'mape'=>0], 'exponential_smoothing'=>['mae'=>0,'rmse'=>0,'mape'=>0]];
        $python = env('PYTHON_BINARY', 'python');
        $process = new Process([$python, base_path('analytics/analyze.py')]);
        $process->setInput(json_encode(['sales'=>$values])); $process->run();
        if ($process->isSuccessful() && ($result = json_decode($process->getOutput(), true))) return $result;
        $average = array_sum($values) / count($values);
        return ['forecast_quantity'=>round($average,2), 'best_method'=>'Moving average', 'moving_average'=>['mae'=>0,'rmse'=>0,'mape'=>0], 'exponential_smoothing'=>['mae'=>0,'rmse'=>0,'mape'=>0]];
    }
}
