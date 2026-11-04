<?php

namespace App\Http\Controllers;

use App\Models\BatchActualCost;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductManufacturingPolicy;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\PackagedWaterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PackagedProductionController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('inventory.index', ['tab' => 'batches']);
    }

    public function create(): View
    {
        return view('packaged.batch-create', [
            'products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get(),
        ]);
    }

    public function show(ProductionBatch $batch): View
    {
        $batch->load('product', 'manufacturingPolicy', 'actualCost', 'stockCounts');
        $deliveries = DispatchLine::query()->with(['dispatch.order.customer'])->where('production_batch_id', $batch->id)->orderByDesc('id')->get();

        return view('packaged.batch-show', compact('batch', 'deliveries'));
    }

    public function manufacturingSettings(Request $request): View
    {
        $products = Product::query()->where('is_active', true)->orderBy('volume_ml')->get();
        $policies = ProductManufacturingPolicy::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->orderBy('product_id')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('product_id');

        return view('packaged.manufacturing-settings', [
            'products' => $products,
            'policies' => $policies,
            'selectedProduct' => $products->firstWhere('id', (int) $request->query('product')),
            'approvers' => User::query()->whereIn('user_id', $policies->flatten()->pluck('approved_by')->filter()->unique())->pluck('name', 'user_id'),
        ]);
    }

    public function saveManufacturingSettings(Request $request, Product $product, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:today', Rule::unique('product_manufacturing_policies', 'effective_from')->where('product_id', $product->id)],
            'shelf_life_days' => ['required', 'integer', 'between:1,3650'],
            'shelf_life_evidence' => ['required', 'string', 'max:500'],
            'water_treatment_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'bottle_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'inbound_transport_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'cap_label_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'labour_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'electricity_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
            'other_production_per_case' => ['required', 'numeric', 'between:0,9999999.99'],
        ]);
        $policy = ProductManufacturingPolicy::create($data + ['product_id' => $product->id, 'approved_by' => $request->user()->user_id]);
        $auditTrail->record('product.manufacturing_policy_approved', $policy, $request->user()->user_id, [], [
            'product_id' => $product->id,
            'effective_from' => $policy->effective_from->toDateString(),
            'shelf_life_days' => $policy->shelf_life_days,
            'shelf_life_evidence' => $policy->shelf_life_evidence,
        ]);

        return redirect()->route('packaged.manufacturing-settings.index')->with('status', 'Product manufacturing settings approved. New batches will use them from the effective date.');
    }

    public function store(Request $request, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'produced_on' => ['required', 'date', 'before_or_equal:today'],
            'cases_produced' => ['required', 'integer', 'between:1,10000'],
            'water_source_reference' => ['required', 'string', 'max:255'],
            'water_input_litres' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'treatment_log_reference' => ['required', 'string', 'max:255'],
            'unplanned_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        $bottledLitres = $data['cases_produced'] * $product->bottles_per_case * $product->volume_ml / 1000;
        if ((float) $data['water_input_litres'] < $bottledLitres) {
            throw ValidationException::withMessages(['water_input_litres' => 'Water input cannot be less than the bottled volume of '.number_format($bottledLitres, 2).' L.']);
        }
        $service->createBatch($data, $request->user()->user_id);

        return redirect()->route('inventory.index', ['tab' => 'batches'])->with('status', 'Batch recorded on quality hold.');
    }

    public function release(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate([
            'quality_notes' => ['required', 'string', 'max:2000'],
            'rejected_cases' => ['nullable', 'integer', 'min:0'],
            'quality_tested_on' => ['required', 'date', 'after_or_equal:'.$batch->produced_on->toDateString(), 'before_or_equal:today'],
            'quality_evidence_reference' => ['required', 'string', 'max:255'],
            'quality_ph' => ['required', 'numeric', 'between:0,14', 'decimal:0,2'],
            'quality_turbidity_ntu' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'microbiological_result' => ['required', 'in:pass'],
        ]);
        $service->releaseBatch($batch, $request->user()->user_id, $data['quality_notes'], (int) ($data['rejected_cases'] ?? 0), $data);

        return back()->with('status', 'Batch released for sale.');
    }

    public function quarantine(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->quarantineBatch($batch, $request->user()->user_id, $data['reason']);

        return back()->with('status', 'Batch quarantined.');
    }

    public function dispose(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['cases' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $service->disposeBatch($batch, $data['cases'], $data['reason'], $request->user()->user_id);

        return back()->with('status', 'Disposal recorded.');
    }

    public function recall(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->recallBatch($batch, $request->user()->user_id, $data['reason']);

        return back()->with('status', 'Batch recalled. Affected open orders returned to draft for reallocation.');
    }

    public function recordActualCost(Request $request, ProductionBatch $batch, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'actual_treatment' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_bottles' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_inbound' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_caps_labels' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_labour' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_electricity' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_other' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);
        $data['actual_total'] = round(array_sum(array_map('floatval', array_intersect_key($data, array_flip(['actual_treatment', 'actual_bottles', 'actual_inbound', 'actual_caps_labels', 'actual_labour', 'actual_electricity', 'actual_other'])))), 2);
        DB::transaction(function () use ($batch, $data, $request, $auditTrail): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_if(BatchActualCost::query()->where('production_batch_id', $batch->id)->exists(), 422, 'Actual cost has already been recorded for this batch.');
            $actual = BatchActualCost::create($data + ['production_batch_id' => $batch->id, 'recorded_by' => $request->user()->user_id]);
            $auditTrail->record('batch.actual_cost_recorded', $actual, $request->user()->user_id, ['estimated_total' => $batch->total_cost], ['actual_total' => $actual->actual_total], $actual->evidence_reference);
        });

        return back()->with('status', 'Actual batch cost recorded. The original estimated cost remains unchanged.');
    }
}
