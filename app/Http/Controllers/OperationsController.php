<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(Request $request, string $type): View
    {
        $this->authorizeType($request, $type);
        if ($type === 'customers') {
            $rows = Customer::query()->latest()->paginate(12);

            return view('operations.index', compact('type', 'rows'));
        }

        return $this->packagedIndex($request, $type);
    }

    public function create(Request $request, string $type): View
    {
        $this->authorizeType($request, $type);
        abort_unless($type === 'customers', 404);

        return view('operations.form', ['type' => $type, 'customers' => Customer::orderBy('name')->get()]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->authorizeType($request, $type);
        abort_unless($type === 'customers', 404);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:30|unique:customers,phone_number',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
        ]);
        Customer::create($data);

        return redirect()->route('customers.index')->with('status', 'Customer record saved.');
    }

    private function packagedIndex(Request $request, string $type): View
    {
        $searchKey = match ($type) {
            'distribution' => 'order',
            'sales' => 'invoice',
            default => 'batch',
        };
        $filters = in_array($type, ['production', 'inventory', 'distribution', 'sales'], true) ? $request->validate([
            $searchKey => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            ...($type === 'sales' ? ['status' => ['nullable', 'in:unpaid,part_paid,paid,credited,settled,credit_due']] : []),
        ]) : [];
        $productionQuery = ProductionBatch::query();
        if ($type === 'production') {
            $batchCode = trim($filters['batch'] ?? '');
            if ($batchCode !== '') {
                $productionQuery->where('batch_code', 'like', '%'.$batchCode.'%');
            }
            if (! empty($filters['from'])) {
                $productionQuery->whereDate('produced_on', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $productionQuery->whereDate('produced_on', '<=', $filters['to']);
            }
        }
        $movementQuery = StockMovement::query();
        if ($type === 'inventory') {
            $batchCode = trim($filters['batch'] ?? '');
            if ($batchCode !== '') {
                $movementQuery->whereHas('batch', fn ($query) => $query->where('batch_code', 'like', '%'.$batchCode.'%'));
            }
            if (! empty($filters['from'])) {
                $movementQuery->whereDate('created_at', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $movementQuery->whereDate('created_at', '<=', $filters['to']);
            }
        }
        $dispatchQuery = Dispatch::query();
        if ($type === 'distribution') {
            $orderNumber = trim($filters['order'] ?? '');
            if ($orderNumber !== '') {
                $dispatchQuery->whereHas('order', fn ($query) => $query->where('order_number', 'like', '%'.$orderNumber.'%'));
            }
            if (! empty($filters['from'])) {
                $dispatchQuery->whereDate('dispatched_on', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $dispatchQuery->whereDate('dispatched_on', '<=', $filters['to']);
            }
        }
        $invoiceQuery = Invoice::query();
        if ($type === 'sales') {
            $invoiceNumber = trim($filters['invoice'] ?? '');
            if ($invoiceNumber !== '') {
                $invoiceQuery->where('invoice_number', 'like', '%'.$invoiceNumber.'%');
            }
            if (! empty($filters['from'])) {
                $invoiceQuery->whereDate('issued_on', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $invoiceQuery->whereDate('issued_on', '<=', $filters['to']);
            }
            if (! empty($filters['status'])) {
                $invoiceQuery->where('status', $filters['status']);
            }
        }
        $rows = match ($type) {
            'production' => (clone $productionQuery)->with('product')->orderByDesc('produced_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'inventory' => $movementQuery->with('batch.product')->orderByDesc('id')->paginate(20)->withQueryString(),
            'distribution' => (clone $dispatchQuery)->with(['order.customer', 'lines'])->orderByDesc('dispatched_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'sales' => (clone $invoiceQuery)->with(['order.customer', 'order.items', 'payments'])->orderByDesc('issued_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            default => abort(404),
        };

        $summary = match ($type) {
            'production' => ['label' => 'Cases produced', 'value' => (clone $productionQuery)->sum('cases_produced'), 'suffix' => 'cases'],
            'inventory' => ['label' => 'Available saleable stock', 'value' => ProductionBatch::where('status', 'released')->whereDate('expires_on', '>=', today())->selectRaw('COALESCE(SUM(cases_on_hand - cases_reserved), 0) as total')->value('total'), 'suffix' => 'cases'],
            'distribution' => ['label' => 'Cases dispatched', 'value' => DB::table('dispatch_lines')->whereIn('dispatch_id', (clone $dispatchQuery)->select('id'))->sum('cases'), 'suffix' => 'cases'],
            'sales' => ['label' => 'Invoiced sales', 'value' => (clone $invoiceQuery)->sum('amount'), 'suffix' => 'KSh'],
        };

        $stockByProduct = $type === 'inventory'
            ? DB::table('products')->leftJoin('production_batches', function ($join) {
                $join->on('products.id', '=', 'production_batches.product_id')
                    ->where('production_batches.status', '=', 'released')
                    ->where('production_batches.expires_on', '>=', today()->toDateString());
            })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get()
            : collect();

        return view('operations.packaged-index', compact('type', 'rows', 'summary', 'stockByProduct', 'filters'));
    }

    private function authorizeType(Request $request, string $type): void
    {
        $roles = ['production' => ['administrator', 'manager', 'production_staff'], 'inventory' => ['administrator', 'manager', 'inventory_staff'], 'distribution' => ['administrator', 'manager', 'distribution_staff'], 'sales' => ['administrator', 'manager', 'sales_staff'], 'customers' => ['administrator', 'manager', 'sales_staff']];
        abort_unless(isset($roles[$type]) && $request->user()->hasRole(...$roles[$type]), 403);
    }
}
