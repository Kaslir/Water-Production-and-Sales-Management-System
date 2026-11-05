<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryTrip;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(Request $request, string $type): View
    {
        $this->authorizeType($request, $type);
        if ($type === 'customers') {
            $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
            $search = trim($filters['q'] ?? '');
            $rows = Customer::query()->with('deliveryArea.zone')
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
        abort_unless($request->user()->hasRole('administrator', 'manager', 'sales_staff'), 403);
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
            ->whereNull('payments.reversed_at')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('payments.amount');
        $refundsPaid = (float) DB::table('customer_refunds')
            ->join('invoices', 'customer_refunds.invoice_id', '=', 'invoices.id')
            ->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('customer_refunds.amount');
        $deliveryFeeCredits = (float) DB::table('delivery_fee_credits')
            ->join('invoices', 'delivery_fee_credits.invoice_id', '=', 'invoices.id')
            ->join('sales_orders', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.customer_id', $customer->customer_id)->sum('delivery_fee_credits.amount');
        $netInvoiced = $invoiced - $credits - $deliveryFeeCredits;
        $invoiceBalances = Invoice::query()->whereHas('order', fn ($query) => $query->where('customer_id', $customer->customer_id))->with(['returns', 'activePayments', 'refunds', 'deliveryFeeCredit'])->get();
        $balanceDue = (float) $invoiceBalances->sum(fn (Invoice $invoice) => max(0, (float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) ($invoice->deliveryFeeCredit?->amount ?? 0) - (float) $invoice->activePayments->sum('amount') + (float) $invoice->refunds->sum('amount')));
        $customerCreditDue = (float) $invoiceBalances->sum(fn (Invoice $invoice) => max(0, (float) $invoice->activePayments->sum('amount') - ((float) $invoice->amount - (float) $invoice->returns->sum('credit_amount') - (float) ($invoice->deliveryFeeCredit?->amount ?? 0)) - (float) $invoice->refunds->sum('amount')));

        $customer->load('deliveryArea.zone');

        return view('operations.customer-show', compact('customer', 'orders', 'orderCount', 'netInvoiced', 'cashCollected', 'refundsPaid', 'balanceDue', 'customerCreditDue'));
    }

    public function create(Request $request, string $type): View
    {
        $this->authorizeType($request, $type);
        abort_unless($type === 'customers', 404);
        abort_unless($request->user()->hasRole('administrator', 'manager', 'sales_staff'), 403);

        return view('operations.form', ['type' => $type, 'areas' => DeliveryArea::query()->with('zone')->whereHas('zone', fn ($query) => $query->where('is_active', true))->where('county', 'Nairobi')->orderBy('name')->get()]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->authorizeType($request, $type);
        abort_unless($type === 'customers', 404);
        abort_unless($request->user()->hasRole('administrator', 'manager', 'sales_staff'), 403);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:30|unique:customers,phone_number',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'delivery_area_id' => 'required|integer|exists:delivery_areas,id',
        ]);
        $this->requireActiveNairobiArea((int) $data['delivery_area_id']);
        $data['delivery_county'] = 'Nairobi';
        Customer::create($data);

        return redirect()->route('customers.index')->with('status', 'Customer record saved.');
    }

    public function editCustomer(Request $request, Customer $customer): View
    {
        $this->authorizeType($request, 'customers');
        abort_unless($request->user()->hasRole('administrator', 'manager', 'sales_staff'), 403);

        return view('operations.customer-delivery-edit', ['customer' => $customer, 'areas' => DeliveryArea::query()->with('zone')->whereHas('zone', fn ($query) => $query->where('is_active', true))->where('county', 'Nairobi')->orderBy('name')->get()]);
    }

    public function updateCustomerDelivery(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeType($request, 'customers');
        abort_unless($request->user()->hasRole('administrator', 'manager', 'sales_staff'), 403);
        $data = $request->validate(['delivery_area_id' => ['required', 'integer', 'exists:delivery_areas,id'], 'address' => ['required', 'string', 'max:255']]);
        $this->requireActiveNairobiArea((int) $data['delivery_area_id']);
        $customer->update($data + ['delivery_county' => 'Nairobi']);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer delivery location updated. Existing orders retain their saved location and fee.');
    }

    private function requireActiveNairobiArea(int $areaId): void
    {
        if (! DeliveryArea::query()->whereKey($areaId)->where('county', 'Nairobi')->whereHas('zone', fn ($query) => $query->where('is_active', true)->where('county', 'Nairobi'))->exists()) {
            throw ValidationException::withMessages(['delivery_area_id' => 'Choose an active Nairobi delivery area.']);
        }
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
                ->where('production_batches.expires_on', '>', today()->toDateString());
        })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')
            ->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get();
        $availableCases = $stockByProduct->sum('cases');
        $nearExpiry = $tab === 'stock'
            ? ProductionBatch::query()->with('product')->where('status', 'released')->where('cases_on_hand', '>', 0)
                ->whereDate('expires_on', '>', today())->whereDate('expires_on', '<=', today()->addDays(30))
                ->orderBy('expires_on')->get()
            : collect();

        return view('operations.inventory', compact('tab', 'filters', 'rows', 'stockByProduct', 'availableCases', 'nearExpiry'));
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
            ...($type === 'production' ? ['product_id' => ['nullable', 'integer', Rule::exists('products', 'id')]] : []),
            ...($type === 'sales' ? ['status' => ['nullable', 'in:unpaid,part_paid,paid,credited,settled,credit_due']] : []),
        ]) : [];
        $productionQuery = ProductionBatch::query();
        if ($type === 'production') {
            $batchCode = trim($filters['batch'] ?? '');
            if (! empty($filters['product_id'])) {
                $productionQuery->where('product_id', $filters['product_id']);
            }
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
            'distribution' => (clone $dispatchQuery)->with(['order.customer', 'order.deliveryTrips', 'lines'])->orderByDesc('dispatched_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'sales' => (clone $invoiceQuery)->with(['order.customer', 'order.items', 'activePayments'])->orderByDesc('issued_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            default => abort(404),
        };

        $summary = match ($type) {
            'production' => null,
            'distribution' => ['label' => 'Cases dispatched', 'value' => DB::table('dispatch_lines')->whereIn('dispatch_id', (clone $dispatchQuery)->select('id'))->sum('cases'), 'suffix' => 'cases'],
            'sales' => ['label' => 'Invoiced sales', 'value' => (clone $invoiceQuery)->sum('amount'), 'suffix' => 'KSh'],
        };

        $trips = $type === 'distribution' ? DeliveryTrip::query()->withCount('orders')->orderByDesc('delivery_on')->orderByDesc('id')->limit(20)->get() : collect();
        $products = $type === 'production' ? Product::query()->orderBy('volume_ml')->get() : collect();
        $productionSummary = null;
        $businessToday = null;
        if ($type === 'production') {
            $businessToday = CarbonImmutable::today(config('app.business_timezone'));
            $totals = ProductionBatch::query()->selectRaw(
                'COALESCE(SUM(cases_produced), 0) as all_time_cases, COALESCE(SUM(CASE WHEN produced_on >= ? AND produced_on < ? THEN cases_produced ELSE 0 END), 0) as month_cases, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as pending_batches',
                [$businessToday->startOfMonth()->toDateString(), $businessToday->addDay()->toDateString(), 'quality_hold']
            )->first();
            $productionSummary = [
                'all_time_cases' => (int) $totals->all_time_cases,
                'month_cases' => (int) $totals->month_cases,
                'pending_batches' => (int) $totals->pending_batches,
                'month_label' => $businessToday->format('F Y'),
            ];
        }

        return view('operations.packaged-index', compact('type', 'rows', 'summary', 'filters', 'trips', 'products', 'productionSummary', 'businessToday'));
    }

    private function authorizeType(Request $request, string $type): void
    {
        $roles = ['production' => ['administrator', 'manager', 'production_staff'], 'inventory' => ['administrator', 'manager', 'inventory_staff', 'production_staff'], 'distribution' => ['administrator', 'manager', 'distribution_staff'], 'sales' => ['administrator', 'manager', 'sales_staff'], 'customers' => ['administrator', 'manager', 'sales_staff', 'distribution_staff']];
        abort_unless(isset($roles[$type]) && $request->user()->hasRole(...$roles[$type]), 403);
    }
}
