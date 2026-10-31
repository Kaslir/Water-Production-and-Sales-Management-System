<?php

namespace App\Http\Controllers;

use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductManufacturingPolicy;
use App\Services\AuditTrail;
use App\Services\PackagedWaterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $batch->load('product', 'manufacturingPolicy');
        $deliveries = DispatchLine::query()->with(['dispatch.order.customer'])->where('production_batch_id', $batch->id)->orderByDesc('id')->get();

        return view('packaged.batch-show', compact('batch', 'deliveries'));
    }

    public function manufacturingSettings(): View
    {
        return view('packaged.manufacturing-settings', [
            'products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get(),
            'policies' => ProductManufacturingPolicy::query()->with('product')->orderBy('product_id')->orderByDesc('effective_from')->get()->groupBy('product_id'),
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
        ]);
        $service->createBatch($data, $request->user()->user_id);

        return redirect()->route('inventory.index', ['tab' => 'batches'])->with('status', 'Batch recorded on quality hold.');
    }

    public function release(Request $request, ProductionBatch $batch, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate(['quality_notes' => ['required', 'string', 'max:2000'], 'rejected_cases' => ['nullable', 'integer', 'min:0']]);
        $service->releaseBatch($batch, $request->user()->user_id, $data['quality_notes'], (int) ($data['rejected_cases'] ?? 0));

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
}
