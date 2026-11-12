<?php

namespace App\Http\Controllers;

use App\Models\BatchActualCost;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductManufacturingPolicy;
use App\Models\WaterSupplyRate;
use App\Services\AuditTrail;
use App\Services\PackagedWaterService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        $batch->load('product');
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
            'waterRates' => WaterSupplyRate::query()->orderByDesc('effective_from')->orderByDesc('id')->get(),
            'selectedProduct' => $products->firstWhere('id', (int) $request->query('product')),
        ]);
    }

    public function saveWaterSupplyRate(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString(), Rule::unique('water_supply_rates', 'effective_from')],
            'price_per_litre' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'bill_or_tariff_reference' => ['required', 'string', 'max:255'],
        ]);

        $rate = WaterSupplyRate::create($data + ['approved_by' => $request->user()->user_id]);
        $auditTrail->record('water_supply.rate_approved', $rate, $request->user()->user_id, [], [
            'effective_from' => $rate->effective_from->toDateString(),
            'price_per_litre' => $rate->price_per_litre,
            'bill_or_tariff_reference' => $rate->bill_or_tariff_reference,
        ]);

        return redirect()->route('packaged.manufacturing-settings.index')->with('status', 'Water supply rate approved. New batches will use it from the effective date.');
    }

    public function saveManufacturingSettings(Request $request, Product $product, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString(), Rule::unique('product_manufacturing_policies', 'effective_from')->where('product_id', $product->id)],
            'shelf_life_days' => ['required', 'integer', 'between:1,3650'],
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
        ]);

        return redirect()->route('packaged.manufacturing-settings.index')->with('status', 'Product manufacturing settings approved. New batches will use them from the effective date.');
    }

    public function store(Request $request, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'produced_on' => ['required', 'date', 'before_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()],
            'cases_produced' => ['required', 'integer', 'between:1,10000'],
            'water_source_reference' => ['nullable', 'string', 'max:255'],
            'treatment_log_reference' => ['nullable', 'string', 'max:255'],
            'unplanned_approval' => ['nullable', 'boolean'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        $data['water_input_litres'] = round($data['cases_produced'] * $product->bottles_per_case * $product->volume_ml / 1000, 2);
        $data['unplanned_reason'] = ($data['unplanned_approval'] ?? false) ? 'Approved without a matching production plan.' : null;
        unset($data['unplanned_approval']);
        $service->createBatch($data, $request->user()->user_id);

        return redirect()->route('inventory.index', ['tab' => 'batches'])->with('status', 'Batch recorded on quality hold.');
    }

    public function release(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate([
            'quality_tested_on' => ['required', 'date', 'after_or_equal:'.$batch->produced_on->toDateString(), 'before_or_equal:'.CarbonImmutable::today(config('app.business_timezone'))->toDateString()],
            'quality_evidence_reference' => ['required', 'string', 'max:255'],
            'microbiological_result' => ['required', 'in:pass'],
        ]);
        $service->releaseBatch($batch, $request->user()->user_id, 'Approved after documented quality review.', 0, $data);

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
            'actual_water_supply' => [$batch->water_supply_rate_id ? 'required' : 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_treatment' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_bottles' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_inbound' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_caps_labels' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_labour' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_electricity' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'actual_other' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);
        $data['actual_total'] = round(array_sum(array_map('floatval', array_intersect_key($data, array_flip(['actual_water_supply', 'actual_treatment', 'actual_bottles', 'actual_inbound', 'actual_caps_labels', 'actual_labour', 'actual_electricity', 'actual_other'])))), 2);
        DB::transaction(function () use ($batch, $data, $request, $auditTrail): void {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_if(BatchActualCost::query()->where('production_batch_id', $batch->id)->exists(), 422, 'Actual cost has already been recorded for this batch.');
            $actual = BatchActualCost::create($data + ['production_batch_id' => $batch->id, 'recorded_by' => $request->user()->user_id]);
            $auditTrail->record('batch.actual_cost_recorded', $actual, $request->user()->user_id, ['estimated_total' => $batch->total_cost], ['actual_total' => $actual->actual_total], $actual->evidence_reference);
        });

        return back()->with('status', 'Actual batch cost recorded. The original estimated cost remains unchanged.');
    }
}
