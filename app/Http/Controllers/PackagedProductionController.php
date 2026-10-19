<?php

namespace App\Http\Controllers;

use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Services\AuditTrail;
use App\Services\PackagedWaterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackagedProductionController extends Controller
{
    public function index(): View
    {
        return view('packaged.batches', [
            'products' => Product::query()->orderBy('volume_ml')->get(),
            'batches' => ProductionBatch::query()->with('product')->orderByDesc('produced_on')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('packaged.batch-create', ['products' => Product::query()->where('is_active', true)->orderBy('volume_ml')->get()]);
    }

    public function show(ProductionBatch $batch): View
    {
        $batch->load('product');
        $deliveries = DispatchLine::query()->with(['dispatch.order.customer'])->where('production_batch_id', $batch->id)->orderByDesc('id')->get();

        return view('packaged.batch-show', compact('batch', 'deliveries'));
    }

    public function store(Request $request, PackagedWaterService $service): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'batch_code' => ['required', 'string', 'max:80', Rule::unique('production_batches', 'batch_code')],
            'produced_on' => ['required', 'date', 'before_or_equal:today'],
            'expires_on' => ['required', 'date', 'after:produced_on'],
            'cases_produced' => ['required', 'integer', 'min:1'],
            'water_treatment_cost' => ['required', 'numeric', 'min:0'],
            'bottle_cost' => ['required', 'numeric', 'min:0'],
            'inbound_transport_cost' => ['required', 'numeric', 'min:0'],
            'cap_label_cost' => ['required', 'numeric', 'min:0'],
            'labour_cost' => ['required', 'numeric', 'min:0'],
            'electricity_cost' => ['required', 'numeric', 'min:0'],
            'other_production_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $service->createBatch($data, $request->user()->user_id);

        return redirect()->route('packaged.batches.index')->with('status', 'Batch recorded on quality hold.');
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

    public function updatePrice(Request $request, Product $product, AuditTrail $auditTrail): RedirectResponse
    {
        abort_unless($request->user()->hasRole('administrator', 'manager'), 403);
        $data = $request->validate(['default_price_per_case' => ['required', 'numeric', 'gt:0']]);
        $before = $product->default_price_per_case;
        $product->update($data);
        $auditTrail->record('product.price_changed', $product, $request->user()->user_id, ['price' => $before], ['price' => $product->default_price_per_case]);

        return back()->with('status', 'Default case price updated.');
    }
}
