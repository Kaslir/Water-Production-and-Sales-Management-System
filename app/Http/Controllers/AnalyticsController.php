<?php

namespace App\Http\Controllers;

use App\Models\{InventoryTransaction, Sale};
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class AnalyticsController extends Controller
{
    public function index()
    {
        $dailySales = Sale::selectRaw('sale_date, SUM(quantity_sold) quantity, SUM(total_amount) revenue')
            ->groupBy('sale_date')->orderBy('sale_date')->get();
        $values = $dailySales->pluck('quantity')->map(fn ($value) => (float) $value)->values()->all();
        $forecast = $this->forecast($values);
        $movements = InventoryTransaction::selectRaw("transaction_date, SUM(CASE WHEN transaction_type = 'OUT' THEN quantity ELSE 0 END) consumed")
            ->groupBy('transaction_date')->orderBy('transaction_date')->get();
        return view('analytics.index', compact('dailySales', 'forecast', 'movements'));
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
