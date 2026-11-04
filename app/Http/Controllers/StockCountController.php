<?php

namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    public function store(Request $request, ProductionBatch $batch, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['counted_cases' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $batch, $data, $auditTrail): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (StockCount::query()->where('production_batch_id', $batch->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['counted_cases' => 'Approve or reject the existing count before submitting another.']);
            }
            if ($data['counted_cases'] < $batch->cases_reserved) {
                throw ValidationException::withMessages(['counted_cases' => 'The count is lower than reserved cases. Resolve affected orders first.']);
            }
            $count = StockCount::create([
                'production_batch_id' => $batch->id,
                'counted_cases' => $data['counted_cases'],
                'system_cases' => $batch->cases_on_hand,
                'difference_cases' => $data['counted_cases'] - $batch->cases_on_hand,
                'reason' => $data['reason'],
                'status' => 'pending',
                'counted_by' => $request->user()->user_id,
            ]);
            $auditTrail->record('stock.count_submitted', $count, $request->user()->user_id, ['system_cases' => $batch->cases_on_hand], ['counted_cases' => $count->counted_cases], $count->reason);
        });

        return back()->with('status', 'Physical count submitted for manager review. Stock has not changed yet.');
    }

    public function decide(Request $request, StockCount $count, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $count, $data, $auditTrail): void {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This count has already been reviewed.']);
            }
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($count->production_batch_id);
            if ($data['decision'] === 'approve') {
                if ($batch->cases_on_hand !== $count->system_cases || $count->counted_cases < $batch->cases_reserved) {
                    throw ValidationException::withMessages(['decision' => 'Stock changed since the count. Take a new physical count.']);
                }
                $batch->update(['cases_on_hand' => $count->counted_cases]);
                if ($count->difference_cases !== 0) {
                    StockMovement::create(['production_batch_id' => $batch->id, 'signed_cases' => $count->difference_cases, 'reason' => 'stocktake_adjustment', 'reference_type' => 'StockCount', 'reference_id' => $count->id, 'performed_by' => $request->user()->user_id, 'notes' => $count->reason.'; approval: '.$data['reason']]);
                }
            }
            $count->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'approved_by' => $request->user()->user_id, 'approved_at' => now()]);
            $auditTrail->record('stock.count_'.$count->status, $count, $request->user()->user_id, ['system_cases' => $count->system_cases], ['counted_cases' => $count->counted_cases, 'difference_cases' => $count->difference_cases], $data['reason']);
        });

        return back()->with('status', 'Stock count reviewed.');
    }
}
