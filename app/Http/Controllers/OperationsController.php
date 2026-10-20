<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Distribution;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\Production;
use App\Models\ProductionBatch;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function index(string $type)
    {
        $this->authorizeType(request(), $type);
        if ($type !== 'customers') {
            return $this->packagedIndex($type);
        }

        $model = ['production' => Production::class, 'distribution' => Distribution::class, 'sales' => Sale::class, 'customers' => Customer::class, 'inventory' => InventoryTransaction::class][$type] ?? abort(404);
        $rows = $model::query()->latest()->paginate(12);

        return view('operations.index', compact('type', 'rows'));
    }

    public function create(string $type)
    {
        $this->authorizeType(request(), $type);

        if ($type === 'production') {
            return redirect()->route('packaged.batches.create');
        }
        if ($type === 'distribution' || $type === 'sales') {
            return redirect()->route('packaged.orders.create');
        }

        return view('operations.form', ['type' => $type, 'customers' => Customer::orderBy('name')->get()]);
    }

    private function packagedIndex(string $type)
    {
        $rows = match ($type) {
            'production' => ProductionBatch::with('product')->orderByDesc('produced_on')->orderByDesc('id')->paginate(20),
            'inventory' => StockMovement::with('batch.product')->orderByDesc('id')->paginate(20),
            'distribution' => Dispatch::with(['order.customer', 'lines'])->orderByDesc('dispatched_on')->orderByDesc('id')->paginate(20),
            'sales' => Invoice::with(['order.customer', 'order.items', 'payments'])->orderByDesc('issued_on')->orderByDesc('id')->paginate(20),
            default => abort(404),
        };

        $summary = match ($type) {
            'production' => ['label' => 'Cases produced', 'value' => ProductionBatch::sum('cases_produced'), 'suffix' => 'cases'],
            'inventory' => ['label' => 'Available saleable stock', 'value' => ProductionBatch::where('status', 'released')->whereDate('expires_on', '>=', today())->selectRaw('COALESCE(SUM(cases_on_hand - cases_reserved), 0) as total')->value('total'), 'suffix' => 'cases'],
            'distribution' => ['label' => 'Cases dispatched', 'value' => DB::table('dispatch_lines')->sum('cases'), 'suffix' => 'cases'],
            'sales' => ['label' => 'Invoiced sales', 'value' => Invoice::sum('amount'), 'suffix' => 'KSh'],
        };

        $stockByProduct = $type === 'inventory'
            ? DB::table('products')->leftJoin('production_batches', function ($join) {
                $join->on('products.id', '=', 'production_batches.product_id')
                    ->where('production_batches.status', '=', 'released')
                    ->where('production_batches.expires_on', '>=', today()->toDateString());
            })->select('products.name')->selectRaw('COALESCE(SUM(production_batches.cases_on_hand - production_batches.cases_reserved), 0) as cases')->groupBy('products.id', 'products.name')->orderBy('products.volume_ml')->get()
            : collect();

        return view('operations.packaged-index', compact('type', 'rows', 'summary', 'stockByProduct'));
    }

    public function store(Request $r, string $type, InventoryService $stock)
    {
        $this->authorizeType($r, $type);
        $uid = $r->user()->user_id;
        if ($type === 'production') {
            $d = $r->validate(['production_date' => 'required|date', 'quantity' => 'required|numeric|gt:0', 'notes' => 'nullable']);
            DB::transaction(function () use (&$p, $d, $uid, $stock) {
                $p = Production::create(['production_date' => $d['production_date'], 'quantity_produced' => $d['quantity'], 'notes' => $d['notes'] ?? null, 'user_id' => $uid]);
                $stock->add($d['quantity'], $d['production_date'], $uid, 'production', $p->production_id, $d['notes'] ?? null);
            });
        } elseif ($type === 'distribution') {
            $d = $r->validate(['distribution_date' => 'required|date', 'quantity' => 'required|numeric|gt:0', 'destination' => 'required|string|max:255', 'notes' => 'nullable']);
            DB::transaction(function () use (&$p, $d, $uid, $stock) {
                $p = Distribution::create(['distribution_date' => $d['distribution_date'], 'quantity_distributed' => $d['quantity'], 'destination' => $d['destination'], 'notes' => $d['notes'] ?? null, 'user_id' => $uid]);
                $stock->remove($d['quantity'], $d['distribution_date'], $uid, 'distribution', $p->distribution_id, $d['notes'] ?? null);
            });
        } elseif ($type === 'sales') {
            $d = $r->validate(['sale_date' => 'required|date', 'quantity' => 'required|numeric|gt:0', 'unit_price' => 'required|numeric|min:0', 'customer_id' => 'nullable|exists:customers,customer_id', 'customer_name' => 'required_without:customer_id|nullable|string', 'phone_number' => 'required_without:customer_id|nullable|string']);
            $customer = $d['customer_id'] ? Customer::find($d['customer_id']) : Customer::firstOrCreate(['phone_number' => $d['phone_number']], ['name' => $d['customer_name']]);
            DB::transaction(function () use (&$p, $d, $uid, $stock, $customer) {
                $p = Sale::create(['sale_date' => $d['sale_date'], 'quantity_sold' => $d['quantity'], 'unit_price' => $d['unit_price'], 'total_amount' => $d['quantity'] * $d['unit_price'], 'customer_id' => $customer->customer_id, 'user_id' => $uid]);
                $stock->remove($d['quantity'], $d['sale_date'], $uid, 'sale', $p->sale_id);
            });
        } elseif ($type === 'customers') {
            $d = $r->validate(['name' => 'required|string|max:255', 'phone_number' => 'required|string|max:30|unique:customers,phone_number', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:255', 'contact_person' => 'nullable|string|max:255']);
            Customer::create($d);
        } else {
            abort(404);
        }

        return redirect()->route($type.'.index')->with('status', ucfirst($type).' record saved.');
    }

    public function adjust(Request $r, InventoryService $stock)
    {
        $d = $r->validate(['quantity' => 'required|numeric|not_in:0', 'transaction_date' => 'required|date', 'notes' => 'required|string']);
        $stock->adjust($d['quantity'], $d['transaction_date'], $r->user()->user_id, $d['notes']);

        return back()->with('status', 'Inventory adjusted.');
    }

    private function authorizeType(Request $request, string $type): void
    {
        $roles = ['production' => ['administrator', 'manager', 'production_staff'], 'inventory' => ['administrator', 'manager', 'inventory_staff'], 'distribution' => ['administrator', 'manager', 'distribution_staff'], 'sales' => ['administrator', 'manager', 'sales_staff'], 'customers' => ['administrator', 'manager', 'sales_staff']];
        abort_unless(isset($roles[$type]) && $request->user()->hasRole(...$roles[$type]), 403);
    }
}
