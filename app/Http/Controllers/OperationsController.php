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
            $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
            $search = trim($filters['q'] ?? '');
            $rows = Customer::query()
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone_number', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('contact_person', 'like', '%'.$search.'%');
                    });
                })
                ->latest()->paginate(12)->withQueryString();

            return view('operations.index', compact('type', 'rows', 'search'));
        }

        if ($type === 'inventory') {
            return $this->inventoryIndex($request);
        }

        return $this->packagedIndex($request, $type);
    }

    public function customerShow(Request $request, Customer $customer): View
    {
        $this->authorizeType($request, 'customers');
        $orders = $customer->orders()->with(['items', 'invoice'])
            ->orderByDesc('ordered_on')->orderByDesc('id')->paginate(15);
        $orderCount = $customer->orders()->count();
        $invoiced = (float) DB::table('invoices')
            ->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('invoices.amount');
        $credits = (float) DB::table('sales_returns')
            ->join('invoices', 'sales_returns.invoice_id', '=', 'invoices.id')
            ->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('sales_returns.credit_amount');
        $cashCollected = (float) DB::table('payments')
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('payments.amount');
        $netInvoiced = $invoiced - $credits;
        $balanceDue = $netInvoiced - $cashCollected;

        return view('operations.customer-show', compact('customer', 'orders', 'orderCount', 'netInvoiced', 'cashCollected', 'balanceDue'));
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

    private function inventoryIndex(Request $request): View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'in:stock,batches,movements'],
            'batch' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $tab = $filters['tab'] ?? 'stock';
        $batchCode = trim($filters['batch'] ?? '');
        $rows = null;

        if ($tab === 'batches') {
            $rows = ProductionBatch::query()->with('product')
                ->when($batchCode !== '', fn ($query) => $query->where('batch_code', 'like', '%'.$batchCode.'%'))
                ->when(! empty($filters['from']), fn ($query) => $query->whereDate('produced_on', '>=', $filters['from']))
                ->when(! empty($filters['to']), fn ($query) => $query->whereDate('produced_on', '<=', $filters['to']))
                ->orderByDesc('produced_on')->orderByDesc('id')->paginate(20)->withQueryString();
        } elseif ($tab === 'movements') {
            $rows = StockMovement::query()->with('batch.product')
                ->when($batchCode !== '', fn ($query) => $query->whereHas('batch', fn ($batchQuery) => $batchQuery->where('batch_code', 'like', '%'.$batchCode.'%')))
                ->when(! empty($filters['from']), fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
                ->when(! empty($filters['to']), fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
                ->orderByDesc('id')->paginate(20)->withQueryString();
        }

        $stockByProduct = DB::table('products')->leftJoin('production_batches', function ($join) {
            $join->on('products.id', '=', 'production_batches.product_id')
                ->where('production_batches.status', '=', 'released')
                ->where('production_batches.expires_on', '>=', today()->toDateString());
        })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')
            ->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get();
        $availableCases = $stockByProduct->sum('cases');

        return view('operations.inventory', compact('tab', 'filters', 'rows', 'stockByProduct', 'availableCases'));
    }

    private function packagedIndex(Request $request, string $type): View
    {
        $searchKey = match ($type) {
            'distribution' => 'order',
            'sales' => 'invoice',
            default => 'batch',
        };
        $filters = in_array($type, ['production', 'distribution', 'sales'], true) ? $request->validate([
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
            'distribution' => (clone $dispatchQuery)->with(['order.customer', 'lines'])->orderByDesc('dispatched_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'sales' => (clone $invoiceQuery)->with(['order.customer', 'order.items', 'payments'])->orderByDesc('issued_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            default => abort(404),
        };

        $summary = match ($type) {
            'production' => ['label' => 'Cases produced', 'value' => (clone $productionQuery)->sum('cases_produced'), 'suffix' => 'cases'],
            'distribution' => ['label' => 'Cases dispatched', 'value' => DB::table('dispatch_lines')->whereIn('dispatch_id', (clone $dispatchQuery)->select('id'))->sum('cases'), 'suffix' => 'cases'],
            'sales' => ['label' => 'Invoiced sales', 'value' => (clone $invoiceQuery)->sum('amount'), 'suffix' => 'KSh'],
        };

        return view('operations.packaged-index', compact('type', 'rows', 'summary', 'filters'));
    }

    private function authorizeType(Request $request, string $type): void
    {
        $roles = ['production' => ['administrator', 'manager', 'production_staff'], 'inventory' => ['administrator', 'manager', 'inventory_staff', 'production_staff'], 'distribution' => ['administrator', 'manager', 'distribution_staff'], 'sales' => ['administrator', 'manager', 'sales_staff'], 'customers' => ['administrator', 'manager', 'sales_staff']];
        abort_unless(isset($roles[$type]) && $request->user()->hasRole(...$roles[$type]), 403);
    }
}
